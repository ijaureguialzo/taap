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
php artisan migrate --force

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
