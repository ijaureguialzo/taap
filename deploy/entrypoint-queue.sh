#!/bin/sh
# Arranca el worker de cola dentro del contenedor.
#
# Misma espera a la BD del orquestador: un arranque paralelo con postgres
# (o un reinicio de la base) no debe matar al worker en el primer fallo
# transitorio. depends_on: service_healthy protege el caso normal, esto
# cubre los que no (manual, sin depends_on, postgres caido).
#
# No migra: las migraciones las aplica el orquestador (ejecutarlas dos veces
# podria fallar al migrar en paralelo). Con la BD lista, el worker arranca
# con exec (PID 1 = php artisan queue:work).
set -e

DB_ATTEMPTS=24
DB_RETRY_SECONDS=5

attempt=1
# Probe ligero: una conexion real contra la BD. (db:show fallaria aqui:
# formatea con Number, requiere la extension intl ausente en la imagen.)
while ! php artisan tinker --execute='DB::select("select 1");' >/dev/null 2>&1; do
    if [ "$attempt" -ge "$DB_ATTEMPTS" ]; then
        echo "[entrypoint-queue] ERROR: la base de datos no responde tras $((DB_ATTEMPTS * DB_RETRY_SECONDS))s" >&2
        exit 1
    fi
    echo "[entrypoint-queue] BD no disponible (intento $attempt/$DB_ATTEMPTS); reintentando en ${DB_RETRY_SECONDS}s" >&2
    sleep "$DB_RETRY_SECONDS"
    attempt=$((attempt + 1))
done

exec "$@"
