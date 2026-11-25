# Spike: montaje Docker del FS compartido (tarea 1.1, design D2)

## Objetivo

Validar el aislamiento del agente (design D2) con un contenedor desechable:
raíz física `--read-only` + arena scratch del agente + binds anidados rw/ro
bajo ella. Preguntas a responder:

- **Q1** ¿El daemon crea los destinos anidados en el *lado host* de la arena?
- **Q2** ¿Un bind rw bajo la arena `:ro` es totalmente escribible (usuario no
  root, como el proceso de los runtimes)?
- **Q3** ¿La arena `:ro` es absoluta también para un proceso **root**
  (caso Hermes: s6 corre como root, rootfs escribible)?

Preguntas extra que surgieron durante el spike (Q4–Q6) y un caso de borde
(bind con *source* ausente en el host).

## Entorno

- Docker **29.9.3** (client y server), Docker Desktop en macOS: los binds de
  `/tmp/*` resuelven en la VM como `/host_mnt/private/tmp/*`, montados con
  `type fakeowner` (fileshare FUSE de Docker Desktop).
- Imagen: `taap/pi:local` (base `node:24-bookworm`; `USER agent`, uid 1001;
  tiene `sh`/`mkdir`/`touch`). Para el caso Hermes se usa `docker exec -u 0`.
- Capacidades del contenedor: `CapEff 00000000a80425fb` (set por defecto de
  Docker: incluye `CAP_DAC_OVERRIDE`, **no** incluye `CAP_SYS_ADMIN`).
- Estructura en el host: `arena/` (scratch del agente) + `fsroot/ana/proyectos`
  (bind rw), `fsroot/carlos/datos` (bind `:ro`) y `fsroot/diego`
  (usuario **no** montado: el agente no debe verlo).

Transcripciones completas (fixture): `fixtures/spike-docker-fs-v1.txt`
(forma A), `fixtures/spike-docker-fs-v2.txt` (formas B/C),
`fixtures/spike-docker-fs-v3.txt` (forma B').

## Hallazgos

### H1. La forma primaria del design (arena `:ro` sin más) NO funciona

`docker create` + `docker start` con `--read-only -v arena:/fs:ro
-v fsroot/ana/proyectos:/fs/ana/proyectos -v fsroot/carlos/datos:/fs/carlos/datos:ro`
falla en el `start` (v1):

```
error mounting "…/fsroot/ana/proyectos" to rootfs at "/fs/ana/proyectos":
create mountpoint for /fs/ana/proyectos mount: make mountpoint "/fs/ana/proyectos":
mkdirat /var/lib/docker/rootfs/overlayfs/<id>/fs/ana: read-only file system
```

Dos hechos:

1. **El daemon NO pre-crea nada en el lado host de la arena** al `create`
   (la arena siguió vacía): el design D2 decía "los parents son creados por el
   daemon en el lado host de la arena" — no es así en este daemon.
2. **runc hace `MkdirAll` componente a componente** del destino del bind
   (parents **y** el propio destino) en el namespace de montaje del contenedor,
   *después* de montada la arena `:ro`. Cada componente que falte bajo la
   arena `:ro` = `mkdirat …: read-only file system` y el contenedor no arranca.

Por tanto los destinos anidados **deben existir ya** en el lado host de la
arena antes del `create`.

### H2. Forma B' (arena `:ro` + esqueleto completo precreado) FUNCIONA

Pre-crear en el lado host de la arena **el destino completo** de cada bind
anidado (`arena/ana/proyectos`, `arena/carlos/datos` — parents **y** destino)
resuelve el H1: el `start` funciona y todas las comprobaciones pasan (v3):

- **Q2** ✓ bind rw bajo arena ro: `mkdir -p /fs/ana/proyectos/nuevo &&
  echo hola > /fs/ana/proyectos/nuevo/f.txt` → OK; el fichero aparece en el
  lado host (`fsroot/ana/proyectos/nuevo/f.txt`). Totalmente escribible.
- **Q3** ✓ arena ro es **absoluta para root** (caso Hermes): `docker exec -u 0
  … mkdir /fs/…` → `Read-only file system` (un mount `:ro` no se esquiva sin
  `CAP_SYS_ADMIN`, ausente en el set por defecto — el rootfs escribible de
  Hermes no cambia nada para los mounts).
- **Q4** ✓ bind anidado `:ro`: `touch` → `Read-only file system` tanto para
  no root como para root; las lecturas van bien (`cat seed.txt` OK).
- **Q5** ✓ visibilidad: `/fs` muestra solo el esqueleto (`ana`, `carlos`) y
  dentro, solo el punto de montaje con su contenido real; `diego` (no
  montado) es invisible; nada del resto del árbol se filtra.
- **Q6** ✓ `docker restart` remonta todo y el contenido anterior sigue
  visible/escribible.

### H3. El fallback del design (arena rw + modo 555) funciona aquí, pero no es portable

Arena **rw** + `chmod 555` (v2): arranca y el top de la arena niega
`mkdir`/`touch`/`rmdir` **también a root** — pero el `EACCES` a root (que sí
tiene `CAP_DAC_OVERRIDE`) es un artefacto del fileshare FUSE `fakeowner` de
Docker Desktop. En un host Linux nativo, root escribiría a través de los bits
de permiso de un bind rw `555`. El `:ro` (H2) sí es garantía de kernel
portable → **se elige la arena `:ro` para todos los runtimes** (uniforme,
incluido Hermes), y el 555 queda descartado.

### H4. Un bind con *source* ausente en el host hace fallar el `start`

Con `fsroot/missing/folder` inexistente (v1-bis): el daemon no la autocrea en
esta plataforma y `docker start` falla con el mismo EROFS de H1 (runc intenta
crearse el mountpoint bajo la arena ro). Conclusión: el orquestador debe
**asegurar la existencia del source** (o del destino completo) antes del
`create`; si una carpeta configurada ha desaparecido del árbol, el
provisioning debe tratarlo explícitamente (ver conclusión C4).

## Conclusión: forma exacta de los `--volume` (para 6.1)

**Forma elegida: B'** — arena `:ro` (la primaria del design) **con el
esqueleto precreado por el orquestador** (la mecánica del fallback). Un
contenedor de agente lleva, en este orden:

```
--read-only                                     (como hoy)
--tmpfs /tmp:size=64m,mode=1777                (como hoy)
--tmpfs /rpc:size=8m,mode=1777                 (como hoy)
-v <arena_host>:/fs:ro                         (arena scratch, SIEMPRE :ro)
-v <fs_host_base><path-canónico>:/fs<path-canónico>          (modo read_write)
-v <fs_host_base><path-canónico>:/fs<path-canónico>:ro       (modo read_only)
# modo disabled = sin montaje (invisible)
```

donde:

- `arena` = `<data_base>/<agent_id>/fs-arena` (vía proceso) /
  `<data_host_base>/<agent_id>/fs-arena` (vía bind) — el mismo dual
  `data_base`/`data_host_base` de `ConfigRenderer::bind()`.
- `<path-canónico>` = path canónico de `agent_fs_mounts`
  (p. ej. `/ana/proyectos` → destino `/fs/ana/proyectos`).

### Obligaciones del orquestador (pre-`create`, por agente)

- **C1. Esqueleto de la arena**: para cada mount no desactivado,
  `mkdir -p <arena_write_base>/<path-canónico>` (el destino **completo**:
  parents y destino; H1). La arena (`fs-arena`) misma se crea también.
- **C2. Sincronización del esqueleto**: al guardar la config del agente,
  `rmdir` los destinos ya no montados (si siguen vacíos) y podar parents
  vacíos: si no, un mount quitado deja un directorio fantasma visible
  (vacío) en la vista del agente.
- **C3. Existencia de los sources**: asegurar que
  `<fs_host_base><path-canónico>` existe como directorio antes del `create`
  (H4). Si el usuario lo borró del árbol, el orquestador lo recrea vacío
  (degradación: el agente ve la carpeta vacía; el explorador ya la muestra
  404/ausente).
- **C4. Faltante en runtime**: si el source desaparece con el contenedor
  vivo, el mount stale se ve como carpeta vacía (comportamiento aceptado;
  no hay nada que hacer sin recrear). Se documenta, no se trata.

### Notas para el resto de la implementación

- **Destino vs referencia virtual**: dentro del contenedor el path físico es
  `/fs<path-canónico>` (punto de montaje `/fs`, design D2/D8). El spec dice
  que "el agente lo referencia … como `/ana/proyectos/ideas.txt`": eso es el
  path **canónico visto desde la raíz compartida** (el `/` virtual del
  agente); el prompt de runtime (tarea 6.3) debe enumerar los paths con el
  prefijo `/fs` porque es lo que ve el shell del agente.
- **Hermes**: rootfs escribible (s6) se mantiene (`readonly: runtime !==
  Hermes`, ya en `AgentManager`); la arena `:ro` + binds le dan el mismo
  aislamiento que a los runtimes no root (Q3): **no se necesita trato
  especial de permisos de arena por runtime** — el modo 555 del fallback no
  se usa.
- La arena vive en el área persistente del agente: el admin no la ve en el
  explorador (fuera de `fs_host_base`) y `terminate()` ya la borra con el
  árbol del agente.
