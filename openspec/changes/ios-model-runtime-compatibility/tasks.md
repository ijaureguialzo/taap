# Tasks

Prerrequisito: el cambio `improve-provider-model-management` debe estar aterrizado (entrega la columna `model_providers.status` que usa el payload de modelos).

## 1. Helper de compatibilidad compartido (PHP)

- [x] 1.1 Extraer la lógica de `Livewire\Agents\Create::compatibleProviderTypes()` a `AgentRuntime::compatibleProviderTypes()` y añadir `Model::isCompatibleWith(AgentRuntime)` (semántica: `anthropic` ⟺ el runtime declara `anthropic`; `openai`/`other` ⟺ declara `openai` o no declara formatos — D1). Verificar: `tests/Unit/ModelCompatibilityTest.php` nuevo con tabla de los 5 runtimes × 3 tipos de proveedor (pi/hermes/codex rechazan anthropic y aceptan openai+other; claude acepta solo anthropic; opencode acepta todo; runtime sin formatos → openai+other).
- [x] 1.2 Refactorizar `Livewire\Agents\Create` para usar el helper (sin cambio de comportamiento observable). Verificar: `tests/Feature/Agents/CreateFormTest.php` completo en verde (filtro, descarte, validación de submit).

## 2. Registro expuesto por API

- [x] 2.1 Ruta `GET /v1/runtimes` (`auth:sanctum`) que devuelve `[{id, label, formats}]` desde `AgentRuntime::supported()` + registry (campos mínimos, D2). Verificar: feature test nuevo (`tests/Feature/Api/RuntimesTest.php`) — usuario autenticado recibe exactamente los 5 runtimes con id/label/formats; sin token → 401.

## 3. Lista de modelos: filtro por runtime + payload

- [x] 3.1 En `ModelController::index`: parámetro `?runtime=` opcional validado contra `supported()` (valor desconocido → 422) y filtro `whereHas('provider', whereIn(type, compatibleProviderTypes))`; sin parámetro → todos (D3). Verificar: feature test — `?runtime=claude` solo modelos anthropic; `?runtime=opencode` todos; sin parámetro todos; `?runtime=marco` → 422.
- [x] 3.2 Añadir `provider_type` y `provider_status` (nullable) al payload de cada modelo de `GET /models`, conservando `provider` como string. Verificar: feature test — el payload incluye el tipo y el estado (incluido `null` cuando no hay comprobación).

## 4. Validación de formato en la API de agentes

- [x] 4.1 En `AgentController::store`: regla de `model_id` con `Model::isCompatibleWith(runtime)` que devuelve 422 con mensaje translatable que nombra formato y runtime (D4). Verificar: feature test — pi + modelo anthropic → 422 con mensaje claro; par válido → 201; `model_id` nulo → 201.
- [x] 4.2 En `AgentController::update`: misma validación contra el runtime actual del agente. Verificar: feature test — actualizar un agente pi con un modelo anthropic → 422 y el agente conserva su modelo anterior.

## 5. iOS: cliente API y DTOs

- [x] 5.1 `Dtos.swift`: `RuntimeDTO` + envelope; `ModelDTO` con `providerType: String?` / `providerStatus: String?` vía `decodeIfPresent` (decodificación tolerante con orquestador viejo — D5). Verificar: unit tests nuevos en `ios/Tests` que decoden payloads de ejemplo con y sin los campos nuevos; ejecutar `xcodebuild test` (o el equivalente del proyecto vía `regenerate.sh`).
- [x] 5.2 `ApiClient.swift`: `runtimes()` y `models(runtime: String? = nil)` (añade `?runtime=` solo si no es nil). Verificar: los tests de 5.1 cubren la serialización del parámetro; verificación manual en simulador contra el orquestador local.

## 6. iOS: NewAgentSheet (registro + filtro + descarte + marca)

- [ ] 6.1 Picker de runtime construido desde `runtimes()` en `.task` (fallo de carga → mensaje de error y botón de creación deshabilitado; sin fallback hardcodeado). Verificar: manual en simulador — el picker ofrece los 5 runtimes del registro con sus labels.
- [ ] 6.2 `onChange(of: runtime)`: re-fetch de `models(runtime:)` + descarte con aviso visible del modelo incompatible y re-restauración al volver a un runtime compatible (misma semántica que la web, sobre ids de modelo). Verificar: manual — elegir pi + modelo anthropic → cambiar a claude: aviso y descarte; volver a pi: el modelo se re-restaura.
- [ ] 6.3 Marca de salud: modelos con `providerStatus == "error"` atenuados + etiqueta «no sano», seleccionables (paridad con la web). Verificar: manual — proveedor con `status=error` → su modelo aparece marcado y se puede elegir.

## 7. iOS: AgentSettingsSheet + localización

- [ ] 7.1 `models(runtime: agent.runtime)` en la carga del sheet + misma marca de salud que 6.3. Verificar: manual — agente claude → solo modelos anthropic; agente con proveedor en error → marca visible.
- [ ] 7.2 Cadenas nuevas en `LocaleStrings.swift` en los tres idiomas (es/en/eu): aviso de descarte, etiqueta «no sano», error de carga de runtimes, y la cadena localizada del 422 de formato. Verificar: compila y se muestra en los tres locales (manual).

## 8. Integración, regresión y orden de despliegue

- [ ] 8.1 Suite completa del orquestador: `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`. Verificar: las tres en verde.
- [ ] 8.2 E2E manual con la build iOS nueva contra el orquestador desplegado: crear un agente por cada uno de los 5 runtimes eligiendo modelos compatibles; cambiar el modelo desde settings; reproducir un 422 por formato con `curl` a la API. Verificar: checklist completo en el README del cambio o en la issue.
- [ ] 8.3 Retrocompatibilidad cruzada: build iOS nueva contra un orquestador sin este cambio (404 en `GET /runtimes` → error visible y creación deshabilitada; resto de la app operativa). Verificar: manual contra un entorno de staging con el orquestador anterior.

## Workflow follow-up

- Archivar el cambio cuando el flujo de revisión del proyecto esté satisfecho.
- Verificar el resultado del archivo (los deltas quedan en `openspec/specs/{agent-runtimes,model-management,ios-client}/spec.md`).
