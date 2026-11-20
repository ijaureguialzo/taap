# Tasks

## 1. Spike: API y stream de opencode (resuelve los Open Questions del design)

- [ ] 1.1 Fijar el paquete npm y la versión de opencode y mapear contra ellos: layout de `serve` (puerto, endpoints de sesión/mensaje/permisos/config), catálogo de eventos del stream `/event` (nombres y payloads), almacenamiento y directorios (`XDG_*`, hash de proyecto), soporte de override de modelo por mensaje y de config en caliente. Verificación: transcripciones reales de la API y del stream fijadas como fixtures + respuestas en `notes/spike-opencode.md` del cambio, cerrando los Open Questions del design.

## 2. Adapter `OpenCodeAdapter` (modo `api-events`)

- [ ] 2.1 Mecánica del modo `api-events`: coproceso vigilante del stream SSE (loop `curl -N` → `$dir/out`, re-arranque con el watchdog) + drenado con la lógica offset/buffer; ajuste transparente de composición de `StdioRpcAdapter` si el spawn de coprocesos va entrelazado (sin cambio de comportamiento en Pi/Claude). Verificación: unitarios — stream scriptado produce `AgentEvent` en el `EventSink`; coproceso caído → re-attach; `isAttached` refleja la vida del coproceso. `php artisan test` y `pint --test` en verde.
- [ ] 2.2 Ciclo de sesión y comandos HTTP: crear/reutilizar sesión (id en el `config` del agente), envío de mensajes (con override de modelo según spike), re-sincronización tras re-attach (consulta de pendientes si el API lo permite), sondeo de salud por HTTP, `status` y `destroy`. Verificación: unitarios con HTTP simulado (fichero de fixtures del spike) — flujo crear→mensajes→permisos→reinicio→reanudación.
- [ ] 2.3 Aprobaciones por API: evento `permission.asked` → `approval.request` (categoría + detalle), decisión del usuario → `POST …/permission/:id` (+ «permitir siempre» según spike) → `approval.resolved`. Verificación: unitarios con fixtures — aprobar, denegar y «permitir siempre» producen la secuencia exacta de eventos.

## 3. Registro, imagen y plano de configuración

- [ ] 3.1 Caso `OpenCode` en `AgentRuntime` + entrada en el registro (label, imagen `taap/agent-opencode:local`, `mode: api-events`, `api_port`, volumes data/config/workspace, capabilities openai+anthropic / native / según spike) + env `TAAP_OPENCODE_IMAGE` + objetivo `images` en el Makefile. Verificación: unitario de completitud del registro y `make images` construye la imagen.
- [ ] 3.2 `renderOpenCode` en `ConfigRenderer`: `opencode.json` (provider `taap` en la forma que fije el spike según el `type` del proveedor — openai o anthropic —, `model`, `mcp`, `permission` con `ask` para efectos y `allow` para lectura, `instructions`); semántica de aplicación según el spike (en caliente o reinicio); brazos en `render()` y `RuntimeAdapters`. Verificación: unitarios — contenido renderizado para un agente OpenCode con proveedor OpenAI y con proveedor Anthropic, MCP y prompt; provision completo con Docker simulado.
- [ ] 3.3 Imagen `agents/opencode/`: `node:24-bookworm` + opencode fijado por `ARG`, python3 para MCP stdio, usuario no root, `XDG_DATA_HOME=/data` / `XDG_CONFIG_HOME=/config`, entrypoint `opencode serve`, compatible con rootfs readonly. Verificación: `docker build` OK; `docker run` manual: la API responde (salud) y el stream `/event` emite el primer evento.
- [ ] 3.4 Etiquetas y textos de la UI para OpenCode. Verificación: feature test — el formulario ofrece los cinco runtimes y las etiquetas se localizan es/en/eu.

## 4. Integración extremo a extremo (versión fijada)

- [ ] 4.1 Flujo completo con Docker real y gateway local: provisionar agente OpenCode (proveedor OpenAI-compatible) → mensaje → `run.*`/`message.*`/`tool.*` en la pipeline → solicitar y resolver un permiso → reinicio del contenedor → sesión reanudada. Verificación: test de integración (skipped sin Docker; CI con servicio Docker) en verde.
- [ ] 4.2 Variante de proveedor Anthropic (mismo flujo con un proveedor `type: anthropic`) y fallos: proveedor no configurado (evento `error` en el chat) y permiso sin responder (timeout → denegado si el runtime lo soporta). Verificación: cubierto en la misma suite de integración.

## 5. Documentación y release

- [ ] 5.1 Actualizar README (tabla de runtimes, línea «próximamente» — si este cambio cierra el plan de D12, se elimina) y `docs/` de despliegue (build de la imagen). Verificación: lectura — los documentos describen el estado final.
- [ ] 5.2 Release notes v1.1: extender `docs/release-notes-v1.1.md` con el runtime OpenCode. Verificación: el fichero cubre los runtimes entregados.

## Workflow follow-up

- Tras el apply: `openspec archive add-opencode-runtime` (consolida los deltas en `openspec/specs/`).
