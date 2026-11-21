# Proposal

## Why

La app iOS incumple tres spec sobre creación y configuración de agentes: `agent-runtimes` exige que "los formularios de creación de agente ofrezcan **exactamente** los runtimes del registro" pero la hoja de creación hardcodea solo Pi y Hermes (el registro no está expuesto por la API, así que la app no puede); `model-management` exige que el sistema excluya de la selección los modelos de formato incompatible con el runtime, pero el selector iOS muestra **todos** los modelos y el payload de `GET /models` ni siquiera incluye el formato del proveedor; y `ios-client` exige "las mismas reglas de validación que la web", pero la API `POST/PUT /agents` solo valida `exists:models,id` — un cliente puede emparejar un agente Pi con un modelo Anthropic y el agente falla en cada llamada (el proxy devuelve 404/501) sin que nada se lo impida. La web ya filtra y valida en el componente Livewire, pero la lógica está atrapada en él: la API no la comparte.

## What Changes

- **Registro expuesto por API** (nueva ruta `GET /v1/runtimes`): lista exactamente los runtimes del registro con `id`, `label` y `formats` (formatos de alambre que consume). Creación gobernada por el registro para cualquier cliente; un runtime nuevo llega a iOS sin re-release.
- **Lista de modelos filtrable por runtime**: `GET /v1/models` acepta `?runtime=` opcional; el servidor filtra por compatibilidad de formato (misma semántica que la web: `other` se trata como OpenAI; runtimes duales como OpenCode admiten todos). Sin parámetro devuelve todos (retrocompatible). El payload añade `provider_type` y `provider_status` de forma aditiva (`provider` sigue siendo string, como el DTO de iOS ya exige).
- **Validación de formato en servidor**: `POST/PUT /agents` valida la compatibilidad modelo↔runtime y devuelve un error claro (422). La lógica se extrae a un helper compartido que también usa el componente Livewire existente: una sola fuente de verdad para web y API.
- **App iOS**: `NewAgentSheet` pide los runtimes al servicio, filtra los modelos por el runtime elegido (re-query al cambiarlo) y replica el descarte con aviso + re-restauración de la web; `AgentSettingsSheet` filtra por el runtime del agente. Ambas marcan atenuados + «no sano» los modelos de proveedores no sanos (usa `provider_status`, columna del cambio `improve-provider-model-management`).
- **Orden de aterrizaje**: este cambio asume que `improve-provider-model-management` aterriza antes (depende de su columna `model_providers.status`).
- **Sin cambios**: web Livewire (el otro cambio), Model Proxy, MCP, flujo de borrado de agentes en iOS.

## Capabilities

### New Capabilities

(ninguna)

### Modified Capabilities

- `agent-runtimes`: el registro se expone a los clientes por la API (exactamente los runtimes del registro, con etiqueta y formatos).
- `model-management`: "Per-agent model selection" — la exclusión de formatos incompatibles también la aplica la API (filtro `?runtime=`, validación en crear/actualizar, payload con tipo y salud del proveedor).
- `ios-client`: "Agent configuration" (selector filtrado por formato del agente, marca de no sanos, mismas reglas de validación) y nueva requirement de creación de agentes desde la app (runtimes desde el servicio, modelos filtrados, descarte con aviso al cambiar de runtime).

## Impact

- **API v1**: nueva ruta `GET /v1/runtimes`; `ModelController::index` (filtro + campos de payload); `AgentController::store/update` (validación de formato); helper de compatibilidad compartido (reutilizado por `Livewire\Agents\Create`).
- **iOS**: `ApiClient.swift` (endpoint de runtimes, parámetro de filtro, nuevos DTO), `Dtos.swift`, `NewAgentSheet.swift`, `AgentSettingsSheet.swift`, cadenas en `LocaleStrings.swift` (es/en/eu).
- **Tests**: feature tests de API (filtro, validación 422, registro); tests de decodificación de DTOs en el target `ios/Tests`; verificación manual de las dos hojas.
- **Sin migración de esquema** (reutiliza `model_providers.status` del otro cambio).
