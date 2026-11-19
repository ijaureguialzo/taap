# Release Notes — TAAP v1.0

**The Agentic AI Playground 1.0** — primera versión estable.

## Lo que es

Servicio local que ejecuta agentes de IA en contenedores aislados 24/7 y los
mantiene coordinados: los ves, configuras y chateas con ellos desde la web y
desde una app iOS, y siguen vivos cuando tú no.

### Runtimes de agente

- **Pi** (`@earendil-works/pi-coding-agent` en modo RPC): sesiones persistentes
  por agente, herramientas (bash, edit, read, write, codemode), MCP vía la
  puerta del orquestador y aprobaciones de comandos con confirmación del usuario.
- **Hermes** (gateway con s6): agente conversacional con API server propio;
  el orquestador sondea su estado y le inyecta los mensajes.

### Orquestador (Laravel 13 + Livewire 4 + Bootstrap 5 + Postgres)

- **Agent lifecycle**: crear, arrancar, parar, reiniciar y borrar agentes
  (un contenedor por agente, sin socket de Docker dentro, rootfs de solo
  lectura donde el runtime lo permite, tmpfs para `/tmp` y `/rpc`).
- **Model Proxy**: los agentes llaman a los modelos a través del orquestador
  con su propio token; las claves de proveedor solo viven en el orquestador.
  OpenAI-completions y Anthropic; normaliza streaming, razonamiento
  (`reasoning_content`) y tool-calls (fija `temperature`/`reasoning_effort`
  por defecto para modelos de razonamiento).
- **Modelos y proveedores**: CRUD por web/API con enmascaramiento de claves
  (nunca viajan a las vistas ni a los agentes).
- **Chat en tiempo real**: historial persistente + deltas, herramientas y
  aprobaciones por canal WSS (Reverb) `agent.{id}` — coherente entre web e
  iOS (mismo orden, mismos eventos), con resincronización al reconectar y
  timelines de "lo que hizo mientras no estabas".
- **Aprobaciones**: cualquier diálogo que el runtime del agente pide
  (`extension_ui_request` en Pi) aparece como aprobación en web/iOS/API y se
  resuelve de vuelta al agente.
- **MCP**: servidores MCP (stdio en el contenedor o URL) gestionables y
  conectables a agentes; el orquestador expone una puerta JSON-RPC por agente
  y reenvía, así el agente no necesita alcanzar al servidor MCP.
- **Egreso controlado (D5)**: red `taap-internal` sin default route; TODO el
  tráfico de red de los agentes pasa por un proxy squid con allowlist de
  hosts de modelo; el interruptor "Internet" no da salida libre.
- **Usuarios y roles**: admin y usuario; cada usuario ve solo sus agentes;
  cuota de agentes por usuario; localización es/en/eu en web e iOS.

### Cliente iOS (SwiftUI)

- Lista de agentes en vivo (estado por WSS), chat con streams y tools,
  aprobaciones inline, ficha de configuración (modelo, MCP, working dir,
  internet) y timelines de actividad.
- String Catalogs es/en/eu con detección del idioma del dispositivo
  (por defecto castellano) y modo avión con resincronización.

## Cómo empezar

```sh
make env build images up seed
# http://localhost:8011  (admin@taap.local / taap1234 — cámbiala)
```

Documento completo: [`docs/deployment.md`](deployment.md).

Verificación de la pila:

```sh
./deploy/smoke-e2e.sh       # E2E reproducIBLE: proveedor → Pi (aprobación+MCP) → Hermes
./deploy/audit-agents.sh    # auditoría de endurecimiento
```

## Cambios de seguridad de esta versión

- Auth por token en API (Sanctum) y en WSS (solo dueños/admin por canal; se
  rechazan canales ajenos).
- Claves de proveedor nunca en vistas ni respuestas de API (semaforos
  `key_set`/`api_key` con semántica omitir=null=borrar=string=reemplazar).
- CSRF en toda la web; agentes sin docker.sock ni puertos publicados.
- Suite `SecurityTest` (11 tests) que lo fija.

## Limitaciones conocidas

- Pi: los modelos pequeños de razonamiento (p. ej. Qwen 27B) son
  intermitentes invocando herramientas; el Model Proxy lo mitiga con
  `reasoning_effort=high` + `temperature=0`, pero un modelo mayor es más fiable.
- Hermes sin stream persistente: su estado se sondea (no hay deltas finos).
- Solo runtime "uno por agente" (no réplicas); sin migración de agentes entre hosts.
- Próximos cambios planeados: `taap-v1.1-claude` (adapter Claude Code) y
  `taap-v1.2-codex-opencode` (app-server Codex / HTTP OpenCode).

## Créditos

Construido sobre Laravel 13, Livewire 4, Fortify, Sanctum, Reverb,
`@earendil-works/pi-coding-agent` y el gateway Hermes (s6).
