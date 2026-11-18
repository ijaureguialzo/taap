# Design

## Context

Repositorio greenfield (solo README + OpenSpec). El sistema se auto-aloja en **un solo host** con Docker Engine + Docker Compose (v1; Linux o macOS con Docker Desktop). Ver proposal.md para la motivación.

Constraints que moldean el diseño:

- Cada agente debe correr **aislado** (filesystem, red, recursos) y estar disponible **24/7** (el patrón de uso es «dejo instrucciones y vuelvo a ver cómo va»).
- Runtimes de v1.0: **Pi** (TypeScript/Node; RPC JSONL por stdio; sesiones persistentes; MCP en `~/.pi/agent/mcp.json`) y **Hermes** (Python; API server OpenAI-compatible en `:8642` habilitado vía `.env`; config en `/opt/data/config.yaml` + `.env`; Docker oficial con volumen único).
- Los runtimes futuros (Claude Code, Codex, OpenCode) tienen superficies distintas (SDK TS, app-server JSON-RPC, HTTP completo): la costura de integración debe ser estable.
- Stack fijado por el equipo: **Laravel 13 + Livewire 4 + Bootstrap 5** (web), **SwiftUI** (iOS v1), **PostgreSQL**, es/en/eu.
- Los agentes consumen modelos por endpoints **OpenAI-compatible** (v1); Claude (v1.1) consumirá formato **Anthropic**.

## Goals / Non-Goals

**Goals:**

- Un plano de control (orquestador) que gestione el ciclo de vida de contenedores de agentes y sea la única puerta a modelos y MCP.
- Costura `AgentRuntimeAdapter` que permita añadir runtimes sin tocar el resto del sistema.
- Chat multi-dispositivo coherente (web + iOS) con streaming, cola, aprobaciones y línea de tiempo.
- Operable por un solo admin en un host, sin servicios gestionados externos.

**Non-Goals (v1.0):**

- Runtimes distintos de Pi y Hermes (v1.1: Claude Code; v1.2: Codex, OpenCode).
- App Android; multi-host; OIDC; cuotas de tokens/coste por usuario; GPU; modo voz; mercados de skills/MCP.

## Decisions

### D1. Dos planos: orquestador en contenedor + un contenedor por agente

El orquestador corre en su propio contenedor y gestiona los contenedores de agentes a través de la **Docker Engine API** sobre el socket montado. Alternativa descartada: orquestador en el host (el requisito explícito era contenedor independiente; además el host queda sin dependencias PHP/Node). Consecuencia aceptada: el socket de Docker equivale a root del host → v1 lo acepta para un servicio local de confianza (ver Risks); los contenedores de agente **no** reciben el socket.

### D2. Costura `AgentRuntimeAdapter` con superficies nativas (sin bridges propios en v1)

El orquestador define una interfaz común por runtime: `attach/detach`, `sendUserMessage(text)`, `events(): AgentEvent`, `status()`, `applyConfig(patch)`, y la semántica de entrega de configuración. Cada runtime se integra con su superficie nativa, sin código de bridge dentro del contenedor:

- **Pi**: el contenedor arranca `pi --mode rpc` como proceso principal; el orquestador se conecta a su stdin/stdout vía Docker attach. La RPC de Pi ya emite eventos ricos (run, message, tool, queue, compaction) y soporta colas y sesiones persistentes (JSONL).
- **Hermes**: el contenedor arranca `hermes gateway` con `API_SERVER_ENABLED=true` y `API_SERVER_KEY=<token>` (según su documentación oficial); el orquestador habla HTTP OpenAI-compatible contra `:8642`.

`AgentEvent` (modelo normalizado en el orquestador): `message.delta`, `message.final`, `tool.start`, `tool.result`, `approval.request`, `approval.resolved`, `status.change`, `run.start`, `run.end`, `error`. Alternativas consideradas:

1. *Protocolo cable propio + bridges en contenedor* (uno por runtime): más control y eventos ricos garantizados, pero 2+ bridges que construir y mantener en v1 → descartado para v1; la costura permite introducirlos después sin tocar el resto.
2. *Todo en formato OpenAI*: demasiado pobre para Pi (pierde aprobaciones, colas, eventos de herramientas).

Expectativa por runtime en v1: Pi = eventos ricos directos; Hermes = texto con streaming + progreso de herramientas «en línea» (separado por el adapter en `tool.*` cuando el formato lo permita; si no, se muestra como actividad genérica).

### D3. Plano de configuración por runtime (config plane)

El orquestador es la única fuente de verdad de la configuración de agente (modelo, MCP, credenciales, red, recursos) y **renderiza la configuración nativa** de cada runtime en su directorio persistente antes de arrancarlo:

| Runtime | Se renderiza en | Cambio de config |
|---|---|---|
| Pi | `~/.pi/agent/` (settings: modelo/endpoint, `mcp.json`, env de credenciales) | Comandos de la RPC (reload de MCP / cambio de modelo en sesión) |
| Hermes | `/opt/data/` (`config.yaml` con `mcp_servers` + modelo, `.env` con `API_SERVER_KEY` y credenciales) | Reinicio del gateway (el estado de sesión vive en el volumen y sobrevive) |

Criterio: el cambio de configuración **no debe perder la sesión**. Cuando el runtime solo lo haga con reinicio de proceso (Hermes), el adapter orquesta *parar→reescribir→arrancar*; cuando puede en caliente (Pi), lo hace sin parar. Los runtimes v1.2 (OpenCode se reconfigura por su API HTTP; Codex via `config.toml` + app-server) encajarán en la misma costura.

### D4. Model Proxy: único punto de egress de inferencia, dual-formato

El orquestador expone un proxy interno (`taap-orchestrator:8081` en `taap-internal`):

- `POST /proxy/{agentId}/v1/chat/completions` (+ `/models`): formato OpenAI (Pi, Hermes en v1).
- `POST /proxy/{agentId}/v1/messages`: formato Anthropic (reservado para Claude en v1.1).

Cada agente recibe `base_url` = su ruta de proxy + un **token por agente** (inyectado por env); el proxy inyecta la clave real del proveedor al salir. Beneficios: las API keys nunca entran en contenedores de agente ni son visibles a usuarios; los agentes **sin Internet** usan el modelo igualmente; el proxy es el punto central para logging/uso por agente-modelo (base de cuotas futuras). Los runtimes Pi y Hermes consumen endpoints OpenAI-compatible nativamente, así que no requieren cambios. Alternativa descartada: inyectar clave real en cada contenedor (filtración a los agentes y a sus procesos hijos: prompt-injection → `curl`).

### D5. Aislamiento de red: redes duales

Dos redes de Compose:

- `taap-internal` (`internal: true`, sin default route): orquestador, Postgres, proxy de egress y todos los agentes.
- `taap-egress` (NAT): solo el **proxy de egress** (squid/envoy con allowlist) y los agentes con Internet activo.

Todo agente está en `taap-internal` siempre; está en `taap-egress` únicamente si tiene Internet activado. Patrón tomado de la documentación oficial de Hermes (aislamiento contra exfiltración por prompt-injection: un comando inyectado no sale de la allowlist aunque el agente tenga Internet). Allowlist por defecto: hosts de los endpoints de modelo configurados (defensa en profundidad, redundante con el Model Proxy pero barata).

### D6. Semántica 24/7: sesión de larga vida + cola + línea de tiempo

- `restart: unless-stopped` + healthcheck por contenedor; el orquestador vigila y registra transiciones de estado.
- **1 agente = 1 sesión** (la sesión viva del runtime) + **cola FIFO de turnos** en el orquestador: los mensajes llegan al adapter, que los encola según el runtime (Pi natively; Hermes serializa ejecuciones) y los procesa en orden.
- La pipeline de eventos persiste `AgentEvent` en Postgres y sintetiza la **línea de tiempo** (ventanas de trabajo: qué hizo el agente y a qué hora) → el patrón «vuelvo a ver cómo va» sin estado en cliente.

### D7. Multiusuario: agentes privados + supervisión admin

Modelo: `agents.owner_id → users.id`. El usuario ve/gestiona solo los suyos; el admin ve todos y puede detener/arrancar/recrearlos (no cambia la propiedad); el borrado ajeno exige confirmación. Cuota de agentes en ejecución por usuario (configurable por el admin, default 3). Usuario deshabilitado → sus agentes se detienen; reactivado → reanudan. Alternativa descartada: agentes compartidos con listas de colaboradores (permisos granulares; se revisa en v1.1+ junto con Claude, donde el SDK soporta sesiones múltiples por instalación).

### D8. Stack y layout de monorepo

```
taap/
├── openspec/            # specs y cambios
├── orchestrator/        # Laravel 13: API, web (Livewire 4 + Bootstrap 5),
│                        #   Model Proxy, Agent Manager, adapteres
├── agents/
│   ├── pi/              # Dockerfile (node + pi + dependencias stdio: node/python)
│   └── hermes/          # Dockerfile sobre imagen oficial (volumen /opt/data)
├── mobile/ios/          # Proyecto Xcode (SwiftUI)
├── deploy/
│   ├── compose.yaml     # orquestador + postgres + egress-proxy + redes
│   └── env.example
└── docs/
```

El orquestador monta `/var/run/docker.sock` (solo lectura de la API de gestión) y expone: UI web + API (JSON, para iOS) + canales WSS (**Reverb**) + Model Proxy + proxy de egress interno. Alternativa: stack Node/TS (adapter Pi in-process más directo) — el equipo decide PHP/Laravel; el impacto es solo la forma del cliente Docker y del adapter (ambos triviales: HTTP/JSONL).

### D9. Pipeline de chat

```
AgentEvent (adapter) ─> Normalizador ─> Persistencia (Postgres)
                                          │
                                          ├─> Reverb (canal agent.{id},
                                          │    autorizado: owner + admins)
                                          │        ├─ Web (Livewire/Alpine, WSS)
                                          │        └─ iOS (cliente WSS + Bearer)
                                          └─> Síntesis de línea de tiempo
```

Los mensajes del usuario entran por API/WSS (autenticados) → cola del orquestador → adapter. La autorización de canal garantiza que solo owner + admin reciben eventos. El estado de la cola y los «indicators» viajan también por el canal (mismo orden que la persistencia).

### D10. Autenticación

Cuentas locales: email + contraseña (argon2id). Web: sesión de cookie + CSRF. API/WSS/iOS: **token opaco por usuario** (la app lo muestra para copia o vía QR en v1). El admin gestúa usuarios (crear/deshabilitar/rol/reset). Sin envío de email en v1 (reset por el admin); OIDC: cambio futuro.

### D11. Localización

Web: ficheros de idioma Laravel `es`/`en`/`eu` (default `es`, fallback `en`), preferencia por usuario persistida y aplicada a la sesión; Livewire/Alpine re-renderizan con `wire:locale`/`setLocale`. iOS: String Catalogs es/en/eu, default = idioma del dispositivo, override del usuario. El API devuelve contenido sin localizar (solo la UI traduce).

### D12. Fases y extensión de runtimes

Añadir un runtime R en una fase futura = 4 piezas, sin tocar el resto: (1) adapter `R` implementando `AgentRuntimeAdapter`; (2) imagen de agente `agents/r/`; (3) renderer de config nativa de R; (4) entrada en el registro. v1.1: Claude Code (el SDK TS de Claude Agent SDK probablemente como sidecar Node en el contenedor — es el primer caso de «bridge en contenedor»; la costura ya lo soporta) + ruta Anthropic del Model Proxy. v1.2: Codex (app-server) y OpenCode (HTTP).

## Data model (resumen)

- `users`: id, email, password_hash, role (admin|user), language (es|en|eu), active, max_agents_running, timestamps.
- `agents`: id, owner_id, name (único por owner), runtime (pi|hermes), image, status, model_provider_id, model_id, internet (bool), cpu_limit, memory_limit, created_by_admin?, timestamps.
- `agent_mounts`: agent_id, host_path, container_path, read_only.
- `model_providers`: id, name, api_format (openai|anthropic), base_url, api_key (secret), enabled.
- `models`: id, provider_id, model_id, display_name, enabled.
- `mcp_servers`: id, name, description, transport (stdio|http), command/args/env (JSON), url/headers (JSON, secretos marcados), enabled.
- `agent_mcp_servers`: agent_id, mcp_server_id, enabled.
- `chat_messages`: id, agent_id, author (user|agent|system), author_user_id?, type, content (JSON/text), created_at.
- `agent_events`: id, agent_id, type, payload (JSON), created_at — alimenta la línea de tiempo.
- `agent_tokens`: agent_id, token (Model Proxy); `user_api_tokens`: user_id, token.

## Risks / Trade-offs

- [docker.sock = root del host] → servicio local de confianza; los agentes no reciben el socket; el orquestador corre sin privilegios extra y en la red interna; se documenta como riesgo aceptado de v1.
- [Volatilidad de los runtimes] (Hermes evoluciona rápido; OpenCode marca «V2 latest») → imágenes con versión fijada; los adapteres son la única superficie de contacto; test de integración contra la imagen fijada en CI.
- [Eventos de Hermes más pobres que los de Pi] (formato OpenAI: progreso de herramientas en línea) → la UI los renderiza como actividad genérica; si la UX lo pide, bridge/ACP en fase posterior (la costura lo permite).
- [Agotamiento de recursos en un host] → cuota por usuario + límites por agente; los agentes sin Internet no consumen ancho de banda saliente.
- [Livewire 4 nuevo] → el chat usa un cliente WSS fino (Reverb) independiente de Livewire; Livewire/Bootstrap solo para CRUD, minimizando riesgo.
- [Exfiltración por prompt-injection en agentes con Internet] → proxy de egress con allowlist; sin Internet, el agente solo alcanza red interna.
- [Un solo host = SPOF] → v1 lo acepta (estado en volúmenes; restauración por backup de volúmenes + Postgres); multi-host es cambio futuro.

## Migration Plan

Greenfield: no hay migración de datos. Despliegue inicial: `docker compose up` (orquestador + Postgres + proxy egress + redes) → crear el primer admin (command) → configurar un endpoint de modelo → crear agentes. Rollback de una versión: Compose con tags de imagen; los volúmenes de agentes son estables entre versiones del orquestador.

## Open Questions

- Herramienta del proxy de egress (squid vs. envoy) y formato exacto de allowlist: decide el orquestador en tareas, sin impacto en specs.
- Imagen base de Pi: `node:24-bookworm` + `pi` global (patrón de sus docs) con node/python para MCP stdio: confirmar en tareas.
- Valor exacto de la cuota default de agentes (propuesta: 3) y de los límites de recursos por defecto: ajustar en la UI de admin al primer despliegue.
- Distribución de la app iOS v1 (TestFlight/ad-hoc/sideloading): decisión de equipo, sin impacto en specs.
