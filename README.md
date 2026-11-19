# TAAP

**The Agentic AI Playground.**

Servicio local que ejecuta agentes de IA (Pi, Hermes, y próximamente Claude
Code, Codex y OpenCode) de forma aislada y 24/7, con interfaz web y app móvil
para verlos, configurarlos y chatear con ellos.

## Arquitectura

```
   CLIENTES                      HOST
   +---------+        +----------------------------------------------+
   | Web     |  WSS   | ORQUESTADOR (Laravel 13 + Livewire 4 +       |
   | Livewire|<------>|  Bootstrap 5, Postgres)                     |
   +---------+        |  - Auth + roles admin/usuario               |
   +---------+        |  - Agent Manager (Docker Engine API)        |
   | iOS     |  WSS   |  - Model Proxy (OpenAI + Anthropic)         |
   | SwiftUI |<------>|  - Config Plane + Adapteres (Pi, Hermes)    |
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
