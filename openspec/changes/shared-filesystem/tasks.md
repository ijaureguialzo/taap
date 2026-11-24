# Tasks

## 1. Spike: montaje Docker del FS compartido

- [x] 1.1 Spike con un contenedor desechable (rootfs `--read-only` + arena bind `:ro` + binds anidados rw/ro bajo ella): verificar que el daemon crea los destinos anidados en el lado host de la arena, que un bind rw bajo arena ro es totalmente escribible y que la arena ro es absoluta también para un proceso root (caso Hermes). Verificación: transcripción `docker create/inspect` y las comprobaciones fijadas como fixture + conclusión con la forma exacta de los `--volume` (primaria o fallback del design D2/Risks) en `notes/spike-docker-fs.md` dentro del cambio.

## 2. Datos y configuración base

- [x] 2.1 Migración `users.fs_slug` (unique, backfill con slug del nombre desambiguado `-N`) + accesores en el modelo `User`. Verificación: `migrate` up/down en base de prueba y test de backfill (usuarios existentes obtienen slug único estable).
- [x] 2.2 Migración `agent_fs_mounts` (`agent_id` FK cascade, `path`, `mode` enum, única por agente+path) + modelo `AgentFsMount` + `Agent::fsMounts()`. Verificación: tests de modelo (unique, cascade al borrar agente, modos).
- [x] 2.3 Config de deployment: `fs_base`/`fs_host_base` + topes de upload/zip en `config/taap.php`; bind `$TAAP_FS_ROOT:/data/fs` en orquestador y queue en `deploy/compose.yaml`; `TAAP_FS_ROOT` en `deploy/env.example`. Verificación: `docker compose config` válido y `smoke-e2e.sh` pasa con el nuevo env.
- [x] 2.4 Asegurado idempotente del directorio de usuario (slug → `<root>/<slug>`) + comando `taap:fs:ensure-user-dirs` para backfill. Verificación: ejecutar el comando sobre la base de prueba y verificar que existe el directorio de cada usuario en la raíz (incluido el de los administradores).

## 3. FilesystemService (núcleo)

- [x] 3.1 `FilesystemService::resolve(user, path)`: raíz por rol (admin = raíz física; usuario = su `<fs_slug>`), normalización canónica (barra inicial, sin barra final, sin `..`/duplicadas) y contención por `realpath` (incluido symlink que sale de la raíz). Verificación: tests unitarios cubriendo cada caso de las specs (Raíz, Directorio de usuario, Formato Unix: "ruta no canónica").
- [x] 3.2 Operaciones `list` (dirs primero, name/type/size/mtime), `mkdir` (recursivo, nombre validado), `rename` (dentro de la base, 409 si el destino existe) y `delete` (recursivo); symlinks se listan pero no se siguen si salen de la base. Verificación: tests unitarios sobre un FS temporal cubriendo "Explorador web de ficheros" (creación, renombrar, borrar, nombres inválidos, escape por symlink).
- [x] 3.3 `upload` (streaming a temporal + rename atómico, tope de tamaño, nombre saneado), `download` (stream) y `zip` (carpeta completa con rutas relativas, topes de tamaño/ficheros → 413). Verificación: tests sobre FS temporal: upload+download idénticos, zip extraíble al árbol esperado, 413 al superar topes, nombre saneado en casos límite.
- [x] 3.4 Protección de directorios de usuario en la vista de admin: los `<fs_slug>` identificados con el nombre del usuario (dato para badge), no renombrables, borrado exige flag de confirmación explícita. Verificación: tests unitarios (rename → error; delete sin confirmación → error; con confirmación → borrado recursivo).

## 4. API v1 (contrato para iOS futura)

- [x] 4.1 Rutas `/api/v1/files*` (GET list, POST folders, POST rename, DELETE, POST uploads, GET download) sobre `FilesystemService`, auth Sanctum, raíz resuelta en servidor por rol. Verificación: tests de API: usuario no admin lista `/` = su home; admin = raíz física; usuario no alcanza a otro usuario (404); códigos de error 404/409/413.
- [x] 4.2 Fijar el contrato: fixtures JSON de las respuestas (listado y metadatos de descarga/zip) como base de paridad para la app iOS. Verificación: test que compara las respuestas reales contra los fixtures.

## 5. Web: sección "Archivos"

- [ ] 5.1 Componente Livewire `Files` + route `/files` + entrada en la navbar + strings localizados (es/en/eu) + visualización de `TAAP_FS_ROOT` en solo lectura para admin. Verificación: tests Livewire: el menú y la página renderizan; usuario ve su home como `/`; admin ve el árbol completo con la ubicación física.
- [ ] 5.2 UI del explorador sobre `FilesystemService`: navegación con breadcrumbs, listado, acciones de crear carpeta/subir (con progreso)/renombrar/borrar (confirmación recursiva)/descargar (fichero o .zip) y badge de "dir de usuario" en la vista de admin. Verificación: tests Livewire de cada acción incluyendo flujos de confirmación y de error, y smoke manual con `agent-browser` (crear carpeta, subir, renombrar, borrar, descargar .zip) con captura como fixture.

## 6. Agente: montajes y permisos de runtime

- [ ] 6.1 `ConfigRenderer`: render de la arena + binds por path (modo `disabled` = sin montaje; `read_only` = `:ro`; `read_write` = rw; destino = arena + path canónico) con la forma validada en el spike 1.1. Verificación: tests unitarios que afirman las cadenas `--volume` exactas para los tres modos y para un path externo.
- [ ] 6.2 `AgentManager::provision` consume los mounts nuevos (los agentes sin carpetas reciben solo la arena, sin efecto observable). Verificación: test de integración con `DockerClient` (doble en unitario; si el entorno lo permite, contenedor real con transcripción `docker inspect` como fixture).
- [ ] 6.3 Bloque de FS compartido en la configuración nativa de cada runtime (design D8): system prompt con paths+modos y reglas de permiso por runtime (Pi, Claude `permissions`, Codex writable roots, OpenCode `permission`; Hermes solo kernel). Verificación: tests por adapter que afirman el contenido renderizado (fixtures por runtime, sin red).
- [ ] 6.4 Extensión `taap-approval.ts` de Pi: auto-aprobar sin `approval.request` cuando el comando solo afecta paths conectados respetando el modo (ro = solo lecturas); fuera de ellos, flujo de aprobación actual. Verificación: test a través del adapter Pi: escritura en path rw no emite aprobación; escritura en path ro falla y se reporta en el chat; comando fuera emite `approval.request`.
- [ ] 6.5 Aplicación de cambios de modo: guardar la config del agente → `provision()` (recreación) con aviso en UI, sesión reanudada y mensajes en curso encolados durable. Verificación: test de flujo: cambio de modo → contenedor recreado con los nuevos mounts, estado `starting`, un mensaje enviado durante el cambio se entrega exactamente una vez y la sesión continúa.

## 7. Web: configuración de carpetas por agente

- [ ] 7.1 En `Agents/Detail`: sección de carpetas del FS compartido — añadir (picker de directorios), editar modo (`desactivada`/`solo lectura`/`lectura/escritura`) y borrar; el picker del propietario navega desde su home, el del admin desde la raíz; paths externos se muestran canónicos con marca "externa". Verificación: tests Livewire: el propietario no puede añadir un path fuera de su home; el admin sí; guardar crea/actualiza `agent_fs_mounts`; el cambio de modo dispara el flujo 6.5 con su aviso.

## 8. Rollout

- [ ] 8.1 Comando `taap:agents:reprovision`: aplica la nueva forma de mounts a todos los agentes (sin carpetas = solo arena; con carpetas = arena + binds) reanudando las sesiones. Verificación: ejecutarlo contra el despliegue de desarrollo y verificar con transcripción `docker inspect` (fixture) que cada contenedor lleva los mounts esperados y los agentes vuelven a `idle`.

## 9. Integración y documentación

- [ ] 9.1 E2E sobre `deploy/` (compose): usuario sube un fichero desde "Archivos"; un agente con la carpeta en rw lo lee/escribe sin confirmación en el chat; un segundo agente conectado ve los cambios; el admin ve todo en su explorador; un path en ro se niega a escribir. Verificación: secciones añadidas a `deploy/smoke-e2e.sh` que pasan y su transcripción queda como fixture en el cambio.
- [ ] 9.2 Documentación: `docs/deployment.md` (qué es `TAAP_FS_ROOT`, que su cambio requiere recreate de orquestador+cola+agentes, y el flujo de rollout 8.1) + nota en los release notes de la versión. Verificación: los comandos documentados son los que usa realmente el despliegue (revisión contra `deploy/`).

## Workflow follow-up

- Archivar el cambio cuando la revisión del proyecto lo exija (los artifacts quedan validados en `--strict`).
