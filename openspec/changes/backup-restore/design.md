# Design: Backup y restore

## Context
Ver proposal.md — Why. Estado actual (deploy): Postgres en named volume `taap_pgdata`; `TAAP_FS_ROOT` (default `/var/lib/taap/fs`) como bind de host; datos de agentes en `TAAP_AGENT_DATA_DIR` (default `/var/lib/taap/agents`) como bind de host. La app no gestiona este almacenamiento: backup/restore no existen.

## Goals / Non-Goals
**Goals:**
- Bundle fechado verificable con hashes cubriendo los tres componentes de datos.
- Procedimiento documentado de restore.

**Non-Goals:**
- Backups automáticos/incrementales; sync externo; cifrado; replicación/HA.

## Decisions

1. **Comando `php artisan taap:backup`** — produce `backups/YYYY-MM-DD-HHMMSS/` (ruta de backup configurable, defecto `backups/` en la raíz del proyecto o dentro de la ruta de datos) con:
   - `db.sql.gz` — `pg_dump` (`--format=custom`) del cluster `taap_pgdata` (via `docker compose exec postgres pg_dump`) o directo vía `pg_dump -h 127.0.0.1` si Postgres está expuesto; la elección real se fija en el diseño de runtime (por defecto: `docker compose` exec, porque los agentes corren en la red interna).
   - `fs-root.tar.gz` — copia de la raíz de `TAAP_FS_ROOT`.
   - `agent-data.tar.gz` — copia de `TAAP_AGENT_DATA_DIR`.
   - `manifest.json` — `{ created_at, taap_version, parts: [{ name, file, size_bytes, sha256 }] }`, con `sha256` por parte (tar/gz) y por file individual... simplificado: hash sobre cada tar.gz y sobre el db dump.
2. **Restore en dos fases: verificar y aplicar** — `taap:backup:restore` (requiere `--yes`), con:
   - Verificar todos los hashes contra el manifiesto (fallo → exit 1 sin escribir).
   - Aplicar: restore db (drop+create en el mismo cluster, o restore selectivo sobre el cluster — elevar la opción; default: drop+create vía `docker compose exec -T postgres psql`), restore fs-root (rsync/tar -xf sobre el bind de host), restore agent-data (tar -xf sobre el bind de host).
   - Requiere dejar la app en mantenimiento (o documentar la ventana de indisponibilidad); el orquestador + cola paradas durante el restore.
3. **Retención** — sin borrado automático de bundles viejos en esta versión (decisión: el admin administra `backups/` manualmente). Documentado.
4. **Secretos** — el bundle excluye `.env`, claves de proveedores (cifradas en BD pero no necesarias para restaurar), tokens de agentes (los tokens se regeneran en el próximo reprovision — documentado como paso opcional `taap:agents:reprovision --only=<agente>`).
5. **Verificación post-restore** — `taap:backup:restore` muestra resumen (partes restauradas, checksums, tiempos) y exit code 0. No hay validación profunda de contenido (el operador revisa el smoke E2E + panel).
6. **Estructura de salida** — bundle dentro de `backups/` es un directorio único con `manifest.json` + 3 partes; el operador puede mover/guardar la carpeta entero.

## Risks / Trade-offs
- [Postgres restore selectivo vs. drop+create] → drop+create es más simple y determinista; el volumen `taap_pgdata` se reemplaza con el dump (restore sobre el mismo cluster). Documentado como ventana de mantenimiento.
- [Backup muy grande (FS root + agent-data)] → tars comprimidos; el bundle puede crecer — el admin decide; sin retención automática.
- [Datos parciales por un fallo a mitad] → el restore es atómico: si cualquier hash falla, no se escribe; la restauración está en fases explícitas con exit code.
- [El bundle no contiene .env] → los pasos post-restore documentados exigen reponer `.env` (documentación explícita); fuera de alcance el cifrado del bundle.

## Migration Plan
- Sin datos migrados existentes (la cosa nace con el comando). Rollout: `php artisan taap:backup` + docs; rollback: el bundle se borra manualmente (el sistema no destruye datos sin confirmación).

## Open Questions
Ninguna diferible. (Retención automática y cifrado quedan explícitamente fuera.)
