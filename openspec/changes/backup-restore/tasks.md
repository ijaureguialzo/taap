# Tasks

## 1. Migración y comandos de backup

- [ ] 1.1 Crear `taap:backup`: genera bundle fechado con db dump (pg_dump), fs-root.tar.gz, agent-data.tar.gz y manifest.json con sha256 (excluir .env y secretos); verificar con `php artisan taap:backup` que el bundle se genera con checksums válidos
- [ ] 1.2 Crear `taap:backup:restore` con `--yes`: verificar todos los hashes antes de escribir, abortar en cualquier fallo, restaurar Postgres/FS root/datos de agentes, mostrar resumen; verificar con restore dry-run against a bundle on a temp copy

## 2. Docs

- [ ] 2.1 Documentar procedimiento completo en `docs/deployment.md` (comandos exactos, ventana de mantenimiento, qué hacer post-restore); verificar con `grep` que las secciones existen y los comandos coinciden con los comandos reales del comando

## 3. Verificación final

- [ ] 3.1 Suite completa (`php artisan test`) + verificación manual de backup/restore en staging; verificar que el sistema funciona tras el restore
