#!/bin/sh
# Arranca los procesos del orquestador dentro del contenedor.
#
# Corre como root solo para soltar el socket de Docker (chmod) y luego
# todos los procesos corren como www-data (setpriv).
#
#  1. Reverb (WSS)    -> :8080 (broadcast a Web/iOS)
#  2. Scheduler       -> tareas programadas
#  3. TaapBridge      -> el demonio 24/7: IPC con agentes, estado, y
#     spawnea sus propios workers de cola (taap-bridge + default)
#  4. Servidor HTTP   -> :8000 (web + API + Model Proxy + MCP bridge)
set -e

# Socket de Docker: el daemon lo crea como root:root 0660.
[ -S /var/run/docker.sock ] && chmod 666 /var/run/docker.sock || true

php artisan storage:link 2>/dev/null || true

# Espera a que PostgreSQL responda antes de migrar: el arranque paralelo
# (o un reinicio de la base) no puede tumbar el contenedor en el primer
# fallo transitorio. depends_on: service_healthy protege el caso normal,
# esto cubre los que no (manual, sin depends_on, postgres caido).
DB_ATTEMPTS=24
DB_RETRY_SECONDS=5

attempt=1
# Probe ligero: una conexion real contra la BD. (db:show fallaria aqui:
# formatea con Number, requiere la extension intl ausente en la imagen.)
while ! php artisan tinker --execute='DB::select("select 1");' >/dev/null 2>&1; do
    if [ "$attempt" -ge "$DB_ATTEMPTS" ]; then
        echo "[entrypoint] ERROR: la base de datos no responde tras $((DB_ATTEMPTS * DB_RETRY_SECONDS))s" >&2
        exit 1
    fi
    echo "[entrypoint] BD no disponible (intento $attempt/$DB_ATTEMPTS); reintentando en ${DB_RETRY_SECONDS}s" >&2
    sleep "$DB_RETRY_SECONDS"
    attempt=$((attempt + 1))
done

# Migraciones con reintentos: un fallo transitorio no debe tumbar el
# contenedor. Un fallo permanente (p. ej. sintaxis SQL) se agota y sale 1.
migrate_attempt=1
while ! php artisan migrate --force; do
    if [ "$migrate_attempt" -ge 6 ]; then
        echo "[entrypoint] ERROR: migrate fallo tras $migrate_attempt intentos" >&2
        exit 1
    fi
    echo "[entrypoint] migrate fallo (intento $migrate_attempt/6); reintentando en 5s" >&2
    sleep 5
    migrate_attempt=$((migrate_attempt + 1))
done

AS_APP() {
    # Corre un comando como www-data (33:33)
    setpriv --reuid=33 --regid=33 --clear-groups "$@"
}

AS_APP php artisan reverb:start --host=0.0.0.0 --port=8080 &
REVERB_PID=$!
AS_APP php artisan schedule:work &
SCHED_PID=$!
# El bridge es la red de vida de los agentes (re-attach, stale-check,
# status): sin supervision, una excepcion sin reintentos (p. ej. una caida
# de postgres) lo deja muerto para siempre y los agentes quedan colgados
# en `starting`. El loop lo re-levanta.
AS_APP sh -c 'while :; do php artisan taap:bridge; echo "[entrypoint] taap:bridge salio; re-levantando en 5s"; sleep 5; done' &
BRIDGE_PID=$!

trap 'kill $REVERB_PID $SCHED_PID $BRIDGE_PID 2>/dev/null; exit 0' TERM INT

AS_APP php -S 0.0.0.0:8000 -t public
