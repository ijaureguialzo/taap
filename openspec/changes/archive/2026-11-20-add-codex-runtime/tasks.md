# Tasks

## 1. Spike: protocolo app-server (resuelve los Open Questions del design)

- [x] 1.1 Fijar la versión de Codex (release binaria) y mapear contra ella el protocolo app-server: handshake, `thread/start|resume`, `turn/start`, notificaciones de items (agente, comandos, cambios de fichero, tools MCP), peticiones de aprobación y su respuesta (incl. «permitir siempre»), `usage` y almacenamiento de sesiones (`CODEX_HOME`). Verificación: transcripción JSON-RPC real (fijada como fixture) + respuestas en `notes/spike-codex.md` del cambio, cerrando los Open Questions del design (p. ej. si el hilo fija el modelo).

## 2. Adapter `CodexAdapter`

- [x] 2.1 `CodexAdapter extends StdioRpcAdapter`: framing JSON-RPC en `normalize()` (notificaciones → eventos `AgentEvent`; respuestas con `error` → evento error; ACKs se descartan), ciclo de thread en `attach` (resume si hay id persistido, `thread/start` si no; handshake `ready`), `sendUserMessage`/`writeMessage` → `turn/start`, `resolveApproval` → respuesta de aprobación correlacionada, `status` (contenedor + turnos en vuelo), `destroy`. Verificación: unitarios con `DockerClient` simulado y `$dir/out` scriptado desde las fixtures del spike — un transcript completo produce la secuencia exacta de eventos esperada.
- [x] 2.2 Rotación de thread por cambio de modelo (si el spike 1.1 la requiere; regla `model_key` de Pi aplicada al thread id) o sin rotación (si el modelo va por `turn/start`). Verificación: unitario del comportamiento elegido según el spike.

## 3. Registro, imagen y plano de configuración

- [x] 3.1 Caso `Codex` en `AgentRuntime` + entrada en el registro (label, imagen `taap/agent-codex:local`, `mode: rpc-stdio`, volumes data+workspace+config, capabilities openai/native/restart) + env `TAAP_CODEX_IMAGE` + objetivo `images` en el Makefile. Verificación: unitario de completitud del registro y `make images` construye la imagen.
- [x] 3.2 `renderCodex` en `ConfigRenderer`: `codex.env` (`TAAP_API_KEY`, `CODEX_HOME=/data`) + `codex-args.env` (los `-c key=value`, uno por línea: provider `taap` → Model Proxy con `wire_api = "responses"` — el spike 1.1 demostró que `chat` fue eliminado en codex 0.128 —, `env_key`, MCP stdio/http, `sandbox_mode`, `approval_policy`); brazos en los `match` del `render()` y `RuntimeAdapters`. Verificación: unitarios — contenido renderizado para un agente Codex con modelo + MCP + dials (ConfigRendererTest).
- [x] 3.3 Imagen `agents/codex/`: base slim + binario fijado por `ARG`, python3+node para MCP stdio, usuario no root, entrypoint FIFO (patrón Pi), `CODEX_HOME=/data`, compatible con rootfs readonly. Verificación: `docker build` OK; `docker run` manual: `codex app-server` hace el handshake y responde a un `thread/start`.
- [x] 3.4 Etiquetas y textos de la UI para Codex (los mecanismos de formulario/capacidades los aporta `add-claude-code-runtime`). Verificación: feature test — el formulario ofrece los cuatro runtimes y las etiquetas se localizan es/en/eu.

## 4. Integración extremo a extremo (versión fijada)

- [x] 4.1 Flujo completo con Docker real y gateway OpenAI-compatible local: provisionar agente Codex → mensaje → `run.start`/`message.*`/`tool.*` en la pipeline → aprobar y denegar una ejecución → reinicio del contenedor → `thread/resume` con contexto intacto. Verificación: test de integración (skipped sin Docker; CI con servicio Docker) en verde.
- [x] 4.2 Fallos: proveedor no configurado (evento `error` visible en el chat) y aprobación con timeout si el protocolo no la soporta (el agente no se queda colgado). Verificación: cubierto en la misma suite de integración.

## 5. Documentación y release

- [x] 5.1 Actualizar README (tabla de runtimes, «próximamente») y `docs/` de despliegue (build de la imagen Codex). Verificación: lectura — los documentos describen el estado final.
- [x] 5.2 Release notes v1.1: crear `docs/release-notes-v1.1.md` si aún no existe (lo crea `add-claude-code-runtime`), extendiéndolo con el runtime Codex. Verificación: el fichero cubre ambos runtimes.

## Workflow follow-up

- Tras el apply: `openspec archive add-codex-runtime` (consolida los deltas en `openspec/specs/`).
