#!/bin/sh
# Arranca los procesos del orquestador:
#  1. Reverb (WSS)      -> :8080
#  2. Queue worker      -> trabajos en segundo plano
#  3. Scheduler         -> tareas programadas (monitor de agentes)
#  4. Servidor HTTP     -> :8000 (web + API + Model Proxy)
set -e

php artisan storage:link 2>/dev/null || true
php artisan migrate --force
php artisan reverb:start --host=0.0.0.0 --port=8080 &
REVERB_PID=$!
php artisan queue:work --tries=3 --timeout=120 &
QUEUE_PID=$!
php artisan schedule:work &
SCHED_PID=$!

trap 'kill $REVERB_PID $QUEUE_PID $SCHED_PID 2>/dev/null; exit 0' TERM INT

exec php -S 0.0.0.0:8000 -t public
