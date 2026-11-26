# Proposal: Backup y restore de la pila

## Why
El despliegue está documentado (`docs/deployment.md` cubre bring-up y rollout) pero no existe **ningún camino soportado para exportar o restaurar** los datos de TAAP: Postgres (`users`, `agents`, `messages`, `settings`, ...), la raíz `TAAP_FS_ROOT` y los datos de los agentes (montañas de workspace, sesiones, tokens). Un fallo de disco o una migración rota deja a un operador con copias manuales y sin procedimiento.

## What Changes
- Comando `php artisan taap:backup` que produce un bundle fechado (timestamp) con: dump de Postgres, copia de `TAAP_FS_ROOT` y manifiesto de los datos de agente (volúmenes) — dentro de una ruta dentro de la configuración de despliegue.
- Procedimiento de restore documentado: restauración de Postgres + copia de FS root + manifiesto para verificar la proveniencia de cada agente.
- Verificación de integridad del bundle en el restore (hash o manifesto) — fallos señalizados.
- Smoke/e2e que ejerce backup → restore en una sola corrida, sobre entornos locales.

**Out of scope:** backups automáticos (cron) o incrementales; sync a almacenamiento externo; cifrado de los bundles; alta disponibilidad de la pila.

## Capabilities

### New Capabilities
- `backup-restore`: exportación y restauración soportada de los datos de TAAP (DB + FS root + datos de agente) con integridad y procedimiento documentado.

### Modified Capabilities
*(ninguna)*

## Impact
- Nuevo comando Artisan + rutas de configuración (ruta del backup en `.env`).
- `docs/deployment.md` gana sección de «Backup y restore».
- CI: el smoke E2E (si existe una corrida de restore) lo cubre.
