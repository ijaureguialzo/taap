# Design

## Context

La web ya implementa la exclusión por formato en el componente Livewire `Agents\Create` (filtro de lista + aviso de descarte + validación en submit), pero la lógica vive ahí dentro: la API v1 no la comparte (`POST/PUT /agents` solo valida `exists:models,id`), `GET /models` no filtra ni devuelve el formato del proveedor, y el registro de runtimes (`config/taap.php` + `AgentRuntime`) no está expuesto a los clientes — la app iOS hardcodea `pi`/`hermes` e incumple la spec `agent-runtimes` ("los formularios SHALL ofrecer exactamente los runtimes del registro"). Ver proposal.md para el why completo.

Dependencia: usa la columna `model_providers.status` del cambio `improve-provider-model-management` (debe aterrizar antes).

## Goals / Non-Goals

**Goals:**

- Selección de runtime gobernada por el registro en iOS (y para cualquier cliente futuro).
- Selección de modelo filtrada por formato en ambas hojas iOS (creación y settings).
- Una sola fuente de verdad de la compatibilidad formato↔runtime: la aplica el filtro de la API, la validación de la API y el componente web.
- Paridad de la marca «no sano» en iOS.

**Non-Goals:**

- CRUD de proveedores/modelos en iOS (la web lo cubre; no lo pide el usuario).
- Cambios en el Model Proxy, MCP, borrado de agentes ni reconnect en iOS.
- Lógica de compatibilidad en Swift: el cliente nunca decide qué es compatible (evita deriva con runtimes nuevos).
- Localización de los `label` del registro por `Accept-Language` (ver Open Questions).

## Decisions

**D1 — Helper compartido `AgentRuntime::compatibleProviderTypes()` + `Model::isCompatibleWith(AgentRuntime)`.**
Se extrae la lógica actual de `Livewire\Agents\Create` al enum `AgentRuntime` (ya es el dueño de `modelFormats()`), y se añade `Model::isCompatibleWith()`. Semántica: tipo `anthropic` del provider es compatible solo si el runtime declara el formato `anthropic`; tipo `openai`/`other` si el runtime declara `openai` o no declara formatos (design v1.0: `other` se trata como OpenAI). Lo usan: filtro de `GET /models`, validación de `POST/PUT /agents`, y el componente Livewire (refactor sin cambio de comportamiento).
*Alternativa*: duplicar la closure en `AgentController` → dos fuentes de verdad que divergen.

**D2 — `GET /v1/runtimes` con campos mínimos.**
Ruta nueva (`auth:sanctum`) que devuelve `[{id, label, formats}]` desde `AgentRuntime::supported()` + registry: `formats` = formatos de alambre que consume (`openai`/`anthropic`). No se expone el resto de la entrada del registro (imagen, volumes, command): detalles de despliegue internos.
*Alternativa*: exponer la entrada entera → fuga de detalles internos; o hardcodear en Swift (incumple la spec).

**D3 — `GET /models?runtime=` con parámetro opcional + payload aditivo.**
`ModelController::index` lee `?runtime=` opcional, validado contra `supported()` (valor desconocido → 422, no se ignora: protege typos del cliente) y filtra con `whereHas('provider', whereIn(type, compatibleProviderTypes($runtime)))`. Sin parámetro, todos (retrocompatible: un cliente viejo no lo envía). El payload añade `provider_type` y `provider_status` (nullable) como campos nuevos; `provider` sigue siendo el nombre en string (el `ModelDTO` de iOS documenta que convertirlo en objeto hundió la lista antes).
*Alternativa*: endpoint aparte `GET /models/compatible` → más superficie por el mismo resultado.

**D4 — Validación de formato en `POST/PUT /agents` con mensaje claro.**
La regla de `model_id` añade el check de `Model::isCompatibleWith()` contra el runtime (el del body en `store`; el actual del agente en `update`, que no es editable por la API) y devuelve 422 con un mensaje translatable que nombra formato y runtime. Es defensa en profundidad: con el filtro, el cliente rara vez la ve, pero protege a cualquier cliente (y a la web, por si el Livewire se elude).
*Alternativa*: mensaje genérico → el cliente no puede explicar por qué.

**D5 — iOS: DTOs tolerantes y re-query en lugar de lógica local.**
`Dtos.swift`: nuevo `RuntimeDTO` + envelope; `ModelDTO` gana `providerType: String?` / `providerStatus: String?` decodificadas con `decodeIfPresent` (un orquestador viejo sin los campos no rompe la app). `ApiClient`: `runtimes()` y `models(runtime:)`. `NewAgentSheet`: el picker de runtime se construye desde `runtimes()` (fallo de carga → mensaje de error y creación deshabilitada, sin fallback hardcodeado); `onChange(of: runtime)` re-fetch de `models(runtime:)` + descarte con aviso y re-restauración replicando la lógica web (que opera sobre ids de modelo, no sobre formato). `AgentSettingsSheet`: `models(runtime: agent.runtime)` + marca de salud. La marca «no sano» (atenuado + etiqueta) se aplica donde `providerStatus == "error"`.
*Alternativa*: filtrar en Swift con `formats` → duplica la semántica del registry en Swift.

**D6 — Orden de despliegue.**
(1) orquestador de `improve-provider-model-management` (columna `status`), (2) orquestador de este cambio (API aditiva: ruta nueva, parámetro opcional, campos nuevos — clientes viejos no se ven afectados), (3) build iOS. Un iOS nuevo contra un orquestador viejo degrada con gracia (sin `GET /runtimes` → error visible; sin `provider_status` → sin marca).

## Risks / Trade-offs

- [Build iOS nuevo contra orquestador viejo] → `runtimes()` devuelve 404 → el formulario muestra el error y deshabilita la creación (no inventa runtimes); el resto de la app sigue funcionando (decodificación tolerante).
- [Refactor del componente web al helper compartido] → riesgo de cambio de comportamiento silencioso → lo cubre `tests/Feature/Agents/CreateFormTest.php` existente (filtro por formato, descarte, validación de submit).
- [Labels del registro en inglés (`Claude Code`, `OpenCode`…)] → se muestran tal cual (igual que la web vía `label()`); la localización por `Accept-Language` queda como mejora posterior (Open Questions).
- [Runtimes sin formato declarado (path defensivo)] → se tratan como OpenAI (design v1.0); hoy los cinco runtimes declaran formato, así que es solo robustez.
- [El 422 por formato aparece en iOS como `error.localizedDescription`] → se añade la cadena localizada del error para que el usuario vea un mensaje útil, no el crudo de Laravel.

## Migration Plan

- Sin migraciones de esquema (reutiliza `model_providers.status` del otro cambio).
- La API es aditiva: desplegable en cualquier momento; la retrocompatibilidad la garantiza el parámetro opcional y los campos nuevos opcionales.
- Rollback: revertir la rama del orquestador; la build iOS nueva degrada con gracia (D6) y el comportamiento vuelve al actual.

## Open Questions

- Localizar los `label` de runtimes por `Accept-Language` (o campos `label_<locale>` en el registry): mejora posterior que no cambia specs ni el contrato del endpoint.
