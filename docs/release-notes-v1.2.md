# Release Notes — TAAP v1.2

**The Agentic AI Playground 1.2** — el sistema de ficheros compartido: un
árbol físico único que los usuarios gestionan desde «Archivos» y que los
agentes montan carpeta a carpeta, con los modos aplicados por el kernel.

## FS compartido

- **Una raíz física, vistas virtuales**: `TAAP_FS_ROOT` (defecto
  `/var/lib/taap/fs`) se monta en el orquestador y la cola en `/data/fs` y en
  cada contenedor de agente en `/fs`. El `/fs` del agente es siempre una
  **arena en solo lectura** sobre la raíz; las carpetas que tenga
  configuradas se montan encima (`read_write` = rw, `read_only` = `:ro`,
  `disabled` = sin montaje). Los modos los aplica el kernel (binds `:ro`),
  no el prompt: una carpeta `read_only` se niega a escribir aunque el modelo
  intente.
- **Vistas por rol**: el admin ve el árbol completo; cada usuario ve su home
  (`/var/lib/taap/fs/<fs_slug>`, slug corto único asignado automáticamente)
  y no sale de ella. Un path fuera de la home se marca «externa».
- **Web «Archivos»**: navegador por el propio árbol — listado, subidas (con
  topes y `413` descriptivo), carpetas, renombrar, borrar, descarga y `.zip`
  de directorios. La misma API v1 estable (`/api/v1/files/…`) la sirve la
  app iOS.
- **Carpetas por agente**: en la ficha del agente, el propietario (o el
  admin) añade carpetas del árbol con su modo —picker de directorios o path
  manual— y las cambia sin perder la sesión: si los mounts cambian, el
  orquestador **recrea el contenedor** con los nuevos binds y la sesión se
  reanuda (vive en el volumen, id estable).
- **Auto-aprobación en lo montado** (Pi): los comandos `bash` que solo tocan
  las carpetas montadas en su modo (escribir en `read_write`, leer en
  `read_only`) no piden confirmación; cualquier cosa fuera de las carpetas,
  o una escritura en una `read_only`, sigue pidiendo aprobación.
- **API v1**: los agentes exponen/aceptan `fs_mounts` en
  `POST/PUT /api/v1/agents` (contrato estable para la app iOS).

## Despliegue y operaciones

- **Rollout**: `php artisan taap:agents:reprovision` ([`--only=…`,
  `--dry-run`]) aplica la nueva forma de mounts a la flota entera
  reanudando las sesiones; cambiar `TAAP_FS_ROOT` requiere recrear
  orquestador + cola y ejecutarlo (ver `docs/deployment.md`, «FS
  compartido»).
- Imagen de orquestador con la extension PHP `zip` + `zip`/`unzip` (los
  `.zip` de directorios).
- El smoke E2E (`deploy/smoke-e2e.sh`) cubre el flujo completo: subida del
  usuario, agente rw que lee/escribe sin confirmación, segundo agente ro que
  ve el cambio y se niega a escribir, y el explorador del admin.

## Notas para actualizar desde v1.1

1. `git pull && make build && make images`
2. `docker compose … up -d --force-recreate orchestrator queue`
3. `php artisan taap:agents:reprovision` (opcional pero recomendado: aplica
   la arena `:ro` + carpeta a los agentes existentes; las sesiones se
   reanudan)
