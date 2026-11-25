# Proposal

## Why

Los agentes trabajan en contenedores aislados: lo que producen queda en su workspace privado, los usuarios no pueden compartir documentos con sus agentes ni entre agentes distintos, y no existe ninguna vía para inspeccionar, subir o descargar esos ficheros desde la web. Se necesita un sistema de archivos compartido —un árbol físico único, con vistas virtuales por rol y permisos por agente— para que personas y agentes colaboren sobre los mismos ficheros.

## What Changes

- Nueva entrada "Archivos" en el menú superior de la web: explorador de carpetas y ficheros navegable con creación de carpetas, subida, renombrado, borrado y descarga (fichero suelto y carpeta comprimida en .zip).
- Un solo almacenamiento físico compartido (raíz configurable en `.env` como `$TAAP_FS_ROOT`), montado por bind en el orquestador y en cada contenedor de agente; el punto de montaje es el "/" del sistema de archivos virtual para agentes y orquestador.
- Cada usuario obtiene un directorio con su nombre (slug estable) en el primer nivel del almacenamiento físico: ese directorio es el "/" de su vista virtual; no ve ni modifica los de otros usuarios ni niveles superiores. Los administradores ven y gestionan todo el árbol físico.
- La configuración de cada agente admite añadir, editar y borrar múltiples carpetas del FS compartido, cada una con modo `desactivada`, `solo lectura` o `lectura/escritura`; el administrador puede conectar carpetas de cualquier usuario. El agente solo puede leer/escribir los paths configurados (recursivamente) y nada más, con enforcement a nivel kernel del contenedor.
- Los agentes acceden a sus carpetas conectadas sin solicitar confirmación al usuario en el chat; las operaciones fuera de ellas mantienen el flujo de aprobaciones habitual.
- Dos o más agentes pueden conectar la misma carpeta y coordinarse sobre ella.
- Las rutas se muestran en formato Unix (`/datos/pruebas/fichero.txt`); la UI del propietario las presenta relativas a su propio directorio.
- Contrato de API v1 versionado y estable (listado, mkdir, renombrado, borrado, subida, descarga, descarga .zip) que la futura app iOS consumirá sin cambios; la app iOS no implementa la funcionalidad en v1.
- **BREAKING** (nivel de spec): sustituye el requisito no implementado "Per-agent filesystem access" de `agent-lifecycle` (montajes de directorios del host arbitrarios) por el modelo de FS compartido.
- Fuera de alcance: cuota de disco por usuario, gestión de ficheros en la app iOS, cambio de la raíz física desde la UI (se define en `.env` y la UI la muestra en solo lectura).

## Capabilities

### New Capabilities

- `shared-filesystem`: almacenamiento físico compartido con raíz configurable; vistas virtuales por rol (admin = árbol completo, usuario = su directorio, agente = sus paths con modo de acceso); explorador web "Archivos" con sus acciones; montaje por path en los contenedores de agente (arena de solo lectura + binds); permisos de runtime sin confirmación para los paths conectados; y contrato de API v1 estable para clientes.

### Modified Capabilities

- `agent-lifecycle`: el requisito "Per-agent filesystem access" deja de describir montajes de directorios del host arbitrarios y pasa a referirse al modelo de FS compartido (paths dentro del árbol con modo desactivada/solo lectura/lectura-escritura, aplicados como binds en el contenedor). La persistencia de workspace y datos de runtime en volúmenes Docker permanece invariada.

## Impact

- **Config/deploy**: `orchestrator/config/taap.php` (`fs_base`/`fs_host_base`), `deploy/compose.yaml` (bind `$TAAP_FS_ROOT:/data/fs` en orquestador y worker de cola), `deploy/env.example`.
- **Base de datos**: migración `users.fs_slug` (slug único y estable) y tabla `agent_fs_mounts` (path canónico + modo, única por agente+path).
- **Orquestador**: nuevo `FilesystemService` (raíz por rol, canonicalización de rutas, acciones); `ConfigRenderer` (arena ro + binds por path + bloque de permisos/prompt por runtime); `AgentManager` (re-provisioning al cambiar un modo); migraciones; modelo `User` (slug + directorio).
- **Web**: componente Livewire `Files` + route `/files` + entrada en la navbar + strings localizados (es/en/eu).
- **API**: rutas públicas `/api/v1/files*` (auth Sanctum) sobre el mismo `FilesystemService`.
- **Imágenes de agente**: directorio arena en el área persistente por agente; la extensión `taap-approval.ts` de Pi auto-aprueba comandos sobre paths conectados; pendientes de validar en el spike de Docker (rootfs ro + arena ro + binds anidados).
- **Sin cambios**: `ios-client` (la compatibilidad se garantiza vía el contrato de API; la app no implementa la funcionalidad), resto de capacidades de `agent-runtimes` (solo se añade el bloque de permisos de FS a la entrega de configuración existente).
