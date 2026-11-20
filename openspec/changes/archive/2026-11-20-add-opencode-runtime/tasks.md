# Tasks

## 1. Spike: API y stream de opencode (resuelve los Open Questions del design)

- [x] 1.1 Fijar el paquete npm y la versión de opencode y mapear contra ellos: layout de `serve` (puerto, endpoints de sesión/mensaje/permisos/config), catálogo de eventos del stream `/event` (nombres y payloads), almacenamiento y directorios (`XDG_*`, hash de proyecto), soporte de override de modelo por mensaje y de config en caliente. Verificación: transcripciones reales de la API y del stream fijadas como fixtures + respuestas en `notes/spike-opencode.md` del cambio, cerrando los Open Questions del design.

## 2. Adapter `OpenCodeAdapter` (modo `api-events`)

- [x] 2.1 Mecánica del modo `api-events`: coproceso vigilante del stream SSE (loop `curl -N` → `$dir/out`, re-arranque con el watchdog) + drenado con la lógica offset/buffer; ajuste transparente de composición de `StdioRpcAdapter` si el spawn de coprocesos va entrelazado (sin cambio de comportamiento en Pi/Claude). Verificación: unitarios — stream scriptado produce `AgentEvent` en el `EventSink`; coproceso caído → re-attach; `isAttached` refleja la vida del coproceso. `php artisan test` y `pint --test` en verde.
- [x] 2.2 Ciclo de sesión y comandos HTTP: crear/reutilizar sesión (id en el `config` del agente), envío de mensajes (con override de modelo según spike), re-sincronización tras re-attach (consulta de pendientes si el API lo permite), sondeo de salud por HTTP, `status` y `destroy`. Verificación: unitarios con HTTP simulado (fichero de fixtures del spike) — flujo crear→mensajes→permisos→reinicio→reanudación.
- [x] 2.3 Aprobaciones por API: evento `permission.asked` → `approval.request` (categoría + detalle), decisión del usuario → `POST …/permission/:id` (+ «permitir siempre» según spike) → `approval.resolved`. Verificación: unitarios con fixtures — aprobar, denegar y «permitir siempre» producen la secuencia exacta de eventos.

## 3. Registro, imagen y plano de configuración

- [x] 3.1 Caso `OpenCode` en `AgentRuntime` + entrada en el registro (label, imagen `taap/agent-opencode:local`, `mode: api-events`, `api_port`, volumes data/config/workspace, capabilities openai+anthropic / native / según spike) + env `TAAP_OPENCODE_IMAGE` + objetivo `images` en el Makefile. Verificación: unitario de completitud del registro y `make images` construye la imagen.
- [x] 3.2 `renderOpenCode` en `ConfigRenderer`: `opencode.json` (provider `taap` en la forma que fije el spike según el `type` del proveedor — openai o anthropic —, `model`, `mcp`, `permission` con `ask` para efectos y `allow` para lectura, `instructions`); semántica de aplicación según el spike (en caliente o reinicio); brazos en `render()` y `RuntimeAdapters`. Verificación: unitarios — contenido renderizado para un agente OpenCode con proveedor OpenAI y con proveedor Anthropic, MCP y prompt; provision completo con Docker simulado.
- [x] 3.3 Imagen `agents/opencode/`: `node:24-bookworm` + opencode fijado por `ARG`, python3 para MCP stdio, usuario no root, `XDG_DATA_HOME=/data` / `XDG_CONFIG_HOME=/config`, entrypoint `opencode serve`, compatible con rootfs readonly. Verificación: `docker build` OK; `docker run` manual: la API responde (salud) y el stream `/event` emite el primer evento.
- [x] 3.4 Etiquetas y textos de la UI para OpenCode. Verificación: feature test — el formulario ofrece los cinco runtimes y las etiquetas se localizan es/en/eu.

## 4. Integración extremo a extremo (versión fijada)

- [x] 4.1 Flujo completo con Docker real y gateway local: provisionar agente OpenCode (proveedor OpenAI-compatible) → mensaje → `run.*`/`message.*`/`tool.*` en la pipeline → solicitar y resolver un permiso → reinicio del contenedor → sesión reanudada. Verificación: `tests/Feature/Agents/OpenCodeRuntimeIntegrationTest.php::test_full_flow_with_approval_restart_and_resume` PASA (gateway local en `taap-internal`; evidencia de resume: el request post-reinicio lleva el historial de la sesión; el request incluye el token de proveedor inyectado por el Model Proxy).
- [x] 4.2 Variante de proveedor Anthropic (mismo flujo con un proveedor `type: anthropic`) y fallos: proveedor no configurado (evento `error` en el chat) y permiso sin responder (timeout → denegado si el runtime lo soporta). Verificación: `test_anthropic_provider_full_flow` + `test_provider_failure_surfaces_error_event` + `test_approval_timeout_denies_and_turn_continues` en la misma suite: 4/4 PASAN.
- [x] 4.3 (emergente) Transporte de pruebas del modo `api-events`: el adapter corre en el host (fuera de `taap-internal`) y el forward de puerto publicado de Docker Desktop se queda obsoleto tras un `docker restart` (verificado: el puerto deja de aceptar conexiones aunque el contenedor siga vivo). Solución: `taap.agents.test_api_publish` (solo tests) publica 4096 en 127.0.0.1 y el adapter enruta por `docker exec <contenedor> curl <loopback>` (`httpViaExec`); en producción el transporte es HTTP directo a la IP fija del agente.
- [x] 4.4 (emergente) Model Proxy `asSse()`: el chunk final sintetizado usaba `delta: []` (array), que los clientes estrictos (`@ai-sdk/openai-compatible` vía Zod) rechazan. Corregido a `delta: {}` (forma canónica OpenAI) + campo `usage` passthrough: los clientes v1.0 (Pi/Claude) lo aceptan sin cambio observable; los 11 tests de `ModelProxyTest` siguen en verde.

## 5. Documentación y release

- [x] 5.1 Actualizar README (tabla de runtimes, línea «próximamente» — si este cambio cierra el plan de D12, se elimina) y `docs/` de despliegue (build de la imagen). Verificación: lectura — los documentos describen el estado final.
- [x] 5.2 Release notes v1.1: extender `docs/release-notes-v1.1.md` con el runtime OpenCode. Verificación: el fichero cubre los runtimes entregados.

## Workflow follow-up

- Tras el apply: `openspec archive add-opencode-runtime` (consolida los deltas en `openspec/specs/`).
