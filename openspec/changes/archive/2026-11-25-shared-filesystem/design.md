# Design

## Context

Ver `proposal.md` para la motivación. Restricciones de la arquitectura actual que condicionan el diseño:

- El orquestador (Laravel) corre **dentro de un contenedor** con el socket de Docker y crea/recrea los contenedores de agente vía API (`AgentManager` + `DockerClient`, mounts como strings `host:destino`). Ya existe el patrón de **dualidad de bases** (`data_base` en el contenedor del orquestador vs `data_host_base` en el host, `ConfigRenderer::bind()`): el FS compartido necesita el mismo (`fs_base` vs `fs_host_base`).
- Los contenedores de agente están **endurecidos**: rootfs `--read-only` + tmpfs `/tmp` y `/rpc`, y corren como usuario no root (Hermes es la excepción: s6 como root). Los mounts son el único medio de entrada/salida de datos.
- Hay **5 runtimes** con mecanismos de permisos/aprobaciones nativos distintos (Pi: extensión `taap-approval.ts` propia; Claude: `permissions`; Codex: `sandbox_mode`/writable roots; OpenCode: `permission`; Hermes: terminal local). La entrega de configuración nativa por runtime ya existe (`ConfigRenderer`).
- La web es Livewire; la iOS consume la API v1 (Sanctum) + Reverb. La spec `agent-lifecycle` declara un requisito de "montajes de directorios del host" que **no está implementado** en el código: este cambio lo sustituye.

## Goals / Non-Goals

**Goals:**
- Un único árbol físico compartido, con tres vistas virtuales (admin = raíz física; usuario = su directorio; agente = sus paths) derivadas por un único modelo de coordenadas.
- Aislamiento del agente **a nivel kernel** (binds por path sobre una arena de solo lectura), uniforme para los 5 runtimes.
- Un solo núcleo de lógica de archivos (`FilesystemService`) consumido por la web y por la API v1, con contrato estable para la iOS futura.
- Sin pérdida de sesión del agente al cambiar los modos (re-provisioning + resume, patrón existente).

**Non-Goals:**
- Cuota de disco por usuario.
- Gestión de ficheros en la app iOS (solo el contrato de API, sin UI).
- Cambio de la raíz física desde la UI (`.env` + visualización en solo lectura).
- Notificación en tiempo real de cambios de ficheros a los clientes (v1: refresco manual; el canal Reverb se deja preparado).
- Compartir ficheros explícitamente entre usuarios (la colaboración es a través de agentes, no de permisos entre usuarios).

## Decisions

### D1. Un árbol físico, tres vistas por raíz
Un único directorio físico (`$TAAP_FS_ROOT`) y una sola abstracción de "raíz por sujeto": admin → raíz física; usuario → `<root>/<fs_slug>`; agente → los paths configurados. Alternativa descartada: almacenamiento privado por usuario + área "compartida" aparte — duplicaría el almacenamiento y haría imposible/cosa distinta la carpeta compartida entre agentes.

### D2. Aislamiento del agente: bind por path sobre una arena read-only
Montar el árbol entero en el agente no cumple "no puede leer ni escribir otra ruta": rw deja crear entradas a nivel raíz junto a los directorios de usuario, y ro deja **leer** los ficheros de otros usuarios. Por tanto:

```
contenedor del agente (rootfs readonly)
 /fs        <- arena: dir scratch del agente (en su área persistente),
               montada BIND :ro            (mount ro: ni root la escribe)
 /fs/ana/proyectos    <- BIND rw (modo lectura/escritura)
 /fs/ana/informes     <- BIND :ro (modo solo lectura)
 /fs/carlos/datos     <- BIND rw (añadida por admin: path externo)
 (modo desactivada = sin montaje: invisible)
```

- El destino en el contenedor es siempre `arena + path canónico`; los parents (`/fs/ana/…`) son creados por el daemon **en el lado host** de la arena, de modo que el agente (usuario no root, y la arena en ro) no puede plantar nada fuera de los mounts.
- Alternativas descartadas: uid/gid + ACLs por agente (frágil entre 5 runtimes con usuarios distintos y sin enforcement de solo-lectura); overlay/whiteouts por agente (complejidad de mantenimiento); filtro en proceso (los runtimes usan sus tools propias: un `cat` arbitrario no es fiable de filtrar). El bind es el enforcement del kernel y es uniforme.
- La arena vive en el área persistente del agente (`<agent_data>/<id>/fs-arena`): no aparece nunca en el FS compartido (no la ve el admin en el explorador) y se limpia al borrar el agente.

### D3. Modelo de coordenadas: path canónico como única fuente
- **Canónico** (base: raíz física, Unix, sin barra final, sin `..`): `/ana/proyectos`. Se guarda en la DB, lo usan la vista de admin y el agente.
- **Container**: `/fs` + canónico (el punto de montaje es el "/" virtual para el agente, según el brief).
- **Vista del propietario**: canónico relativo a su `<fs_slug>` → `/proyectos`; el picker navega desde su raíz (igual que "Archivos").
- **Path externo** (añadido por admin, fuera del subárbol del propietario): no tiene raíz desde la cual relativizar → se muestra canónico, marcado "externa".
- Una sola regla de derivación alimenta: mounts del contenedor, prompt del agente, UI de config, UI de admin, API. Alternativa descartada: guardar paths relativos al usuario — imposible de interpretar para paths ajenos y rompería la referencia común agente/orquestador.

### D4. Datos: tabla `agent_fs_mounts` + `users.fs_slug`
- `agent_fs_mounts(id, agent_id FK cascade, path string, mode enum[disabled,read_only,read_write], timestamps)`, única por `(agent_id, path)`. Es **tabla** (como `agent_mcp_servers`), no JSON en `agent.config`: es consultable ("¿qué agentes tienen conectada esta carpeta?" → badge en el explorador, coordinación multiagente) y valida mejor.
- Las carpetas **no son filas**: un "montaje" referencia un directorio real que debe existir (y ser directorio) al guardar; la validación de existencias vive en el servicio, no en la DB.
- `users.fs_slug` (unique): slug del nombre con desambiguación `ana`, `ana-2`; estable ante cambio de nombre. El directorio se asegura idempotente (primer uso + backfill en migración).
- El admin puede conectar **cualquier** carpeta del árbol; el propietario solo dentro de su subárbol (validado contra su base en `FilesystemService`).

### D5. Aplicar cambios de modo = `provision()`
Docker no admite añadir/quitar un bind a un contenedor en marcha: el cambio de modo se aplica con recreación del contenedor (`AgentManager::provision()`), exactamente el patrón ya usado por `internet_enabled` en `Detail::saveConfig()`. La sesión del agente sobrevive (resume por diseño de `agent-runtimes`) y los mensajes en curso están encolados durable. La UI avisa: "el agente se reiniciará para aplicar los cambios".

### D6. `FilesystemService`: un solo núcleo, dos fachadas
Toda la lógica en un servicio del orquestador; el componente Livewire y los controllers de la API v1 son fachadas delgadas sobre él.

- **`resolve(user, path)`**: raíz por rol (D1) + normalización canónica (D3) + **contención por `realpath`** (el path resuelto debe quedar dentro de la base; bloquea escapes por `..` y symlinks que salgan de la raíz).
- **Operaciones**: `list` (dir primero; name/type/size/mtime), `mkdir` (recursivo; nombre validado), `rename` (dentro de la base; destino no existe), `delete` (recursivo), `upload` (multipart → fichero temporal en la base → `rename` atómico; tope de tamaño por config; nombre saneado a `basename` + juego seguro), `download` (stream), `zip` (carpeta completa a `.zip` con rutas relativas; tope de tamaño/número de ficheros por config → 413).
- **Symlinks**: el servicio no sigue enlaces que salgan de la base (navegación, descarga, zip); se muestran como entrada. Los symlinks creados **por el agente** dentro de sus paths se tratan como confianza del propietario (el kernel no los restringe): se documenta como límite de confianza, no como fallo.
- Los **directorios de usuario en el explorador de admin** se identifican (`fs_slug` → nombre); no se pueden renombrar (el slug es la fuente de verdad del mapa usuario→directorio), solo borrar con confirmación explícita.

### D7. Contrato API v1 (el que consumirá la iOS futura)
Sobre el `FilesystemService`, auth Sanctum, raíz resuelta **servidor** por rol (la app no sabe de slugs):

```
GET    /api/v1/files?path=/            -> {path, entries:[{name,type,size,mtime}]}
POST   /api/v1/files/folders           {path}
POST   /api/v1/files/rename            {path, newName}
DELETE /api/v1/files?path=…            (recursivo)
POST   /api/v1/files/uploads?path=…    (multipart)
GET    /api/v1/files/download?path=…   (fichero, o .zip si es carpeta)
```

Mismos errores/validaciones que la web (404 fuera de la raíz, 409 conflicto de nombre, 413 tope). La web los consume vía Livewire (el componente llama al servicio directamente: misma semántica); la existencia de la API garantiza la paridad y la adopción iOS sin cambios.

### D8. Permisos por runtime: sin confirmación dentro de lo conectado
`ConfigRenderer` añade a la configuración nativa de cada runtime un bloque de FS compartido: paths + modos, con la convención `/fs` como punto de montaje, y las reglas de permiso nativas equivalentes a "auto-aprobar dentro de los paths conectados":

| Runtime | Mecanismo |
|---|---|
| Pi | Extensión `taap-approval.ts` (propia): si el comando solo afecta paths conectados (y respeta el modo ro) → auto-aprobar sin `approval.request`; si no → flujo actual |
| Claude | `permissions.allow`: `Read(/fs/**)` + `Edit(<path>/**)` por path rw (writable roots del SDK); fuera → `ask` como hoy |
| Codex | `sandbox_workspace_write.writable_roots` += paths rw (sobre el `sandbox_mode=workspace-write` actual); ro lo fuerza el kernel |
| OpenCode | `permission` con globs por path (read/edit allow dentro; resto mantiene los defaults actuales) |
| Hermes | Terminal local: el kernel (ro/rw de los binds) ya lo enforcea; sin superficie de aprobación adicional |

Escribir en un mount `:ro` falla en el kernel (EROFS) con independencia del runtime: el agente ve el error y lo reporta en el chat, sin rondas de aprobación. La spec solo exige el resultado observable (sin `approval.request` dentro; con fuera) — el mapeo exacto de cada runtime se afina en el spike.

### D9. Montaje en orquestador y queue
`TAAP_FS_ROOT:/data/fs` (rw) añadido al orquestador y al worker de cola en `deploy/compose.yaml`; en `taap.php`: `fs_base=/data/fs` (vía para el proceso) + `fs_host_base=$TAAP_FS_ROOT` (vía para los binds de agentes), mismo patrón que `data_base`/`data_host_base`. La UI de admin muestra `TAAP_FS_ROOT` en solo lectura.

## Risks / Trade-offs

- **[Docker] rootfs `--read-only` + arena `:ro` + destinos anidados rw** — es el único punto con incertidumbre técnica (¿el daemon crea los parents en el lado host de la arena ro y el bind interno rw es totalmente escribible?). → **Spike previo obligatorio** (patrón del proyecto: transcripción de `docker create/inspect` como fixture). Fallback si no cupiera: arena como bind rw de un dir del agente + los parents creados por el orquestador **antes** del create (el orquestador tiene la vía host), con la arena en modo `555` para runtimes no root y verificación extra para Hermes (root).
- **[Symlinks del agente]** un enlace creado en un path rw puede apuntar fuera de la base del propietario → el explorador/API no lo siguen (realpath containment); para el agente es un ampliación de lectura que se acepta como confianza del propietario (mitigable por el usuario con el modo de la carpeta).
- **[Recreación por cambio de modo]** interrumpe un turno en curso → la UI lo avisa y el encolado durable de mensajes (`agent-runtimes`) garantiza que nada se pierde; la sesión se reanuda.
- **[.zip de carpetas grandes]** memoria/tiempo → topes por config (`TAAP_FS_ZIP_MAX_SIZE`, `TAAP_FS_ZIP_MAX_FILES`) con 413 descriptivo; los topes de upload análogos.
- **[Cambio de `TAAP_FS_ROOT`]** es un bind inmutable: requiere recreate del orquestador, la cola y de todos los agentes → operación de despliegue documentada; la UI solo la muestra.
- **[Colisiones de slug]** → unique index + sufijo `-N`; el backfill de la migración resuelve existentes con el mismo algoritmo.

## Migration Plan

1. **Spike Docker** (D2): validar arena ro + binds anidados + rootfs ro; fijar la forma exacta de los `--volume` y el contenido de la arena.
2. **Migraciones**: `users.fs_slug` (backfill: slug del nombre con desambiguación + crear directorios) y `agent_fs_mounts` (vacía al desplegar: los agentes no tienen carpetas hasta que se configuren).
3. **Compose**: añadir el bind `/data/fs` a orquestador y cola; `env.example` con `TAAP_FS_ROOT`.
4. **Rollout de agentes**: comando artisan `taap:agents:reprovision` que aplica los mounts nuevos (arena; sin paths = solo arena, sin efecto observable) a todos los agentes; quienes ya tengan carpetas configuradas se re-provisionan con ellas.
5. **Despliegue**: los cambios son aditivos (menú, API, mounts); no hay ventana de incompatibilidad. **Rollback**: quitar mounts y UI; los datos del árbol físico quedan intactos (el rollback no toca datos); las migraciones tienen `down()`.

## Open Questions

- Mapeo exacto de las reglas de permiso de Codex/Hermes para paths compartidos (se resuelve en el spike D8; no cambia specs ni el enfoque).
- Implementación del .zip (ZipArchive a fichero temporal vs binario `zip`): decisión de rendimiento durante el spike, sin impacto en el contrato.
- Badge "agentes conectados a esta carpeta" en el explorador: nicety v1.1 (la consulta existe con `agent_fs_mounts`); no bloquea v1.
- Subida por chunks para ficheros muy grandes: adición de API futura (el contrato v1 se mantiene estable).
