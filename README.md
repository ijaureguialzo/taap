# TAAP

**The Agentic AI Playground.**

Servicio local que ejecuta agentes de IA (Pi, Hermes, Claude Code, Codex y
OpenCode) de forma aislada y 24/7, con interfaz web y app móvil para verlos,
configurarlos y chatear con ellos.

## Arquitectura

```
   CLIENTES                      HOST
   +---------+        +----------------------------------------------+
   | Web     |  WSS   | ORQUESTADOR (Laravel 13 + Livewire 4 +       |
   | Livewire|<------>|  Bootstrap 5, Postgres)                     |
   +---------+        |  - Auth + roles admin/usuario               |
   +---------+        |  - Agent Manager (Docker Engine API)        |
   | iOS     |  WSS   |  - Model Proxy (OpenAI + Anthropic)         |
   | SwiftUI |<------>|  - Config Plane + Adapteres (Pi, Hermes, Claude, Codex) |
   +---------+        +---+-----------------------------------+-----+
   red taap-internal (sin default route)    red taap-egress (squid allowlist)
   +---------------------------+  +---------------------------+
   | AGENTE: contenedor propio |  | AGENTE: contenedor propio |
   | /workspace + datos        |  | ...                       |
   | restart unless-stopped    |  |                           |
   +---------------------------+  +---------------------------+
```

- **Orquestador**: contenedor que gestiona el ciclo de vida de los agentes
  (Docker Engine API), la autenticación, los modelos (Model Proxy), los MCP
  y el chat. Monta el socket de Docker (riesgo aceptado: servicio local).
- **Agentes**: un contenedor por agente con runtime + volumen persistente +
  red aislada. Internet opcional vía proxy squid con allowlist.
- **Claves de modelos** viven en el orquestador: los agentes llaman al
  Model Proxy con un token propio y nunca ven la clave real.

## Componentes

| Directorio      | Contenido                                              |
|-----------------|--------------------------------------------------------|
| `orchestrator/` | Aplicación Laravel 13 (API, web Livewire, Model Proxy) |
| `agents/pi/`    | Imagen del agente Pi (`pi --mode rpc` + Node/Python)   |
| `agents/hermes/`| Imagen del agente Hermes (sobre `nousresearch/hermes-agent`) |
| `agents/claude/`| Imagen del agente Claude Code (sidecar Node con Claude Agent SDK) |
| `agents/codex/`  | Imagen del agente Codex (`codex-app-server` nativo, sin sidecar) |
| `agents/opencode/`| Imagen del agente OpenCode (`opencode serve`, modo `api-events`) |
| `mobile/ios/`   | App nativa iOS (SwiftUI)                               |
| `deploy/`       | Docker Compose, Dockerfiles, proxy de egress           |
| `docs/`         | Documentación de despliegue y operación                |
| `openspec/`     | Especificaciones y cambios (OpenSpec)                  |

## Despliegue rápido

Prerrequisitos: Docker Engine + Docker Compose v2.

```sh
make env        # crea deploy/.env (ajusta contraseñas y APP_KEY)
make build      # construye orquestador + egress
make images     # construye las imágenes de agentes
make up         # arranca la pila
make shell      # crea el primer admin y configura
```

Ver [`docs/deploy.md`](docs/deploy.md) para el procedimiento completo.

## Tests y desarrollo

```sh
cd orchestrator
composer install && npm install
php artisan test       # PHPUnit/Pest
./vendor/bin/pint      # lint
npm run build          # assets
```

La app iOS se construye con Xcode desde `mobile/ios/` (requiere Xcode 27+).

## Estado

v1.0 (cambio OpenSpec [`taap-v1`](openspec/changes/taap-v1/)): orquestador,
Pi + Hermes, web + iOS, roles, es/en/eu. Ver `openspec/changes/taap-v1/tasks.md`
para el plan de implementación.

v1.1 (cambios OpenSpec [`add-claude-code-runtime`](openspec/changes/add-claude-code-runtime/),
[`add-codex-runtime`](openspec/changes/add-codex-runtime/) y [`add-opencode-runtime`](openspec/changes/add-opencode-runtime/)):
runtimes Claude Code (sidecar con Claude Agent SDK), Codex (`codex-app-server`
nativo, hilo `thread/resume` tras reinicios) y OpenCode (`opencode serve` 1.19.6,
comandos HTTP + stream de eventos; sesiones persistentes que sobreviven
reinicios), Model Proxy con rutas Anthropic y Responses, y registro de runtimes
por capacidad.
Ver `docs/release-notes-v1.1.md`.

v1.2: FS compartido (un árbol físico único montado por agente con modos
anclados al kernel: arena `:ro` + binds por path). Ver
`docs/release-notes-v1.2.md`.

v1.3 (esta release): Liquid Glass en la app iOS (iOS 26), 2FA/passkeys,
auditoría inmutable, telemetría de uso de modelos, panel de salud, backup/restore,
webhook de fallo, plantillas de agente, búsqueda/exportación del chat, visor de
logs del agente y explorador de ficheros en la app iOS.
Ver `docs/release-notes-v1.3.md`. Todos los cambios están archivados en
`openspec/changes/archive/`.
