# Tasks

## 1. Spike: Agent SDK y Model Proxy (resuelve los Open Questions del design)

- [ ] 1.1 Fijar versiones de `@anthropic-ai/claude-code` y `@anthropic-ai/claude-agent-sdk` y mapear contra la versión fijada: messages crudos del SDK (system/assistant/result), `resume`, directorio de sesiones (env exacto) y forma de retorno de `canUseTool` (incl. «permitir siempre»). Verificación: script spike local contra un gateway local que imprime los messages crudos; respuestas redactadas en `notes/spike-claude.md` del cambio y Open Questions del design cerrados.
- [ ] 1.2 Verificar la ruta `/v1/messages` del Model Proxy contra el SDK/CLI fijado desde un contenedor de red `taap-internal` (streaming, campos inyectados, `anthropic-beta`). Verificación: llamada real desde un contenedor de prueba devuelve respuesta stream correcta; lista de gaps concretos que alimenta la grupo 3.

## 2. Fundamento: base `StdioRpcAdapter` y capacidades

- [ ] 2.1 Extraer `StdioRpcAdapter` de `PiAdapter` (estado de sesión, coprocesos reader/writer, `write()` FIFO, `drain()` offset+buffer, watchdog `readerStale`, `attach/detach/isAttached/ensureAttached`; plantilla `probePayload/normalize/probeInterval`) y hacer que `PiAdapter` la herede sin cambio de comportamiento. Verificación: `php artisan test` y `./vendor/bin/pint --test` en verde, incluyendo unitarios nuevos de la lógica drain/offset/buffer de la base.
- [ ] 2.2 Declarar `capabilities` en el registro (`config/taap.php`: pi = openai/native/restart, hermes = openai/turn/restart) y helper `AgentRuntime::capabilities()`. Verificación: unitario que aserta los valores declarados de las dos entradas v1.0.
- [ ] 2.3 Formularios gobernados por el registro: el Create ofrece `AgentRuntime::supported()` (adieu `in:pi,hermes` hardcodeado) y el Detail muestra las capacidades localizadas (nuevas entradas `lang/es|en|eu`). Verificación: feature test — el formulario lista solo runtimes del registro; etiquetas renderizadas en los tres idiomas.

## 3. Model Proxy: endurecimiento de la ruta Anthropic

- [ ] 3.1 Añadir `asAnthropicSse`: síntesis de stream en shape Anthropic (`message_start`, `content_block_*`, `message_delta`, `message_stop`) para `/v1/messages` cuando el cliente pide `stream: true`. Verificación: feature test con upstream simulado — la respuesta es un stream Anthropic válido (consumible por el CLI según spike 1.2).
- [ ] 3.2 Inyección de campos condicional al tipo de proveedor (`reasoning_effort` y lo que el spike 1.2 marque) para no enviar campos ajenos a la API Anthropic. Verificación: feature tests del proxy para proveedor openai y anthropic.

## 4. Registro del runtime Claude

- [ ] 4.1 Caso `Claude` en `AgentRuntime` + entrada en el registro (label, imagen `taap/agent-claude:local`, `mode: rpc-stdio`, volumes config/data/workspace, `config_files: [claude.json]`, capabilities anthropic/native/next-turn) + variable de entorno `TAAP_CLAUDE_IMAGE` + objetivo `images` en el Makefile. Verificación: unitario del registro (completitud de la entrada) y `make images` construye la imagen.

## 5. Sidecar e imagen Claude

- [ ] 5.1 App del sidecar en `agents/claude/` (Node): protocolo TAAP v1 por JSONL stdin/stdout — `prompt` (query SDK con `resume` y config re-leída de `/config/claude.json` por turno), `approval_response` (resolución de la Promise `canUseTool` con timeout configurable), `get_state`; mapeo SDK→líneas `AgentEvent` (`protocol: 1`); persistencia del `session_id` en el volumen. Verificación: suite de tests Node (SDK falsificado) en el directorio de la imagen: prompt→events, aprobación allow/deny/timeout, reanudación tras «reinicio» (kill+restart del sidecar), línea malformada→evento error.
- [ ] 5.2 Dockerfile `agents/claude/`: `node:24-bookworm`, SDK+CLI fijados por `ARG`, `COPY` sidecar, entrypoint FIFO+holder (patrón `agents/pi`), `CLAUDE_CONFIG_DIR`→volumen datos (valor según spike), usuario no root, compatible con rootfs readonly. Verificación: `docker build` OK; `docker run` manual: el sidecar responde a `get_state` con evento `ready` y sobrevive sin cliente.

## 6. Adapter PHP y plano de configuración

- [ ] 6.1 `ClaudeAdapter extends StdioRpcAdapter`: `normalize` = pase-through validado del protocolo v1, `probePayload` = `get_state`, `sendUserMessage`/`writeMessage` (FIFO, cola follow-up), `resolveApproval`, `applyConfig` (re-render; aplica siguiente turno, sin reinicio), `status`, `destroy`; brazo en `RuntimeAdapters`. Verificación: unitarios con `DockerClient` simulado y `$dir/out` scriptado — eventos con shape exacto de `AgentEvent` en el `EventSink`.
- [ ] 6.2 `renderClaude` en `ConfigRenderer`: `/config/claude.json` (modelo, endpoint del Model Proxy, token, MCP por la puerta HTTP, prompt de sistema como `append` sobre el preset, listas de permisos del design D3); sin rotación de `session_id`; brazo en el `match` del `render()`. Verificación: unitarios — contenido del fichero para un agente Claude con modelo+MCP+prompt; y provision completo en test con Docker simulado (mounts = los del registro).

## 7. Integración extremo a extremo (imagen fijada)

- [ ] 7.1 Flujo completo con Docker real y gateway local de modelo: provisionar agente Claude → mensaje → `run.start`/`message.*`/`tool.*` en la pipeline → aprobar y denegar una aprobación → reinicio del contenedor → sesión reanudada. Verificación: test de integración (skipped sin Docker; se ejecuta en CI con servicio Docker) en verde.
- [ ] 7.2 Fallos: timeout de aprobación (agente continúa tras denegado automático) y proveedor no configurado (evento `error` visible en el chat). Verificación: cubierto en la misma suite de integración.

## 8. Documentación y release

- [ ] 8.1 Actualizar README (tabla de runtimes y la línea «próximamente»), comentarios de `config/taap.php` y `docs/` de despliegue (build de la imagen Claude). Verificación: lectura — los documentos describen el estado final del cambio.
- [ ] 8.2 Release notes v1.1 (`docs/release-notes-v1.1.md`): runtime Claude, filtro de modelos por formato, capacidades en el registro. Verificación: el fichero existe y cubre las capacidades entregadas.

## Workflow follow-up

- Tras el apply: `openspec archive add-claude-code-runtime` (consolida los deltas en `openspec/specs/`).
