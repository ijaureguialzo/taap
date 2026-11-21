# Design

## Context

El orquestador (Laravel + Livewire) expone dos páginas de catálogo: `Providers\Index` y `Models\Index` (punto 2 de taap-v1). Hoy ambas filtran por `created_by` —un admin solo ve lo que creó—, los modelos no tienen edición, no hay descubrimiento de modelos, la comprobación de conectividad no persiste y el borrado no avisa de agentes afectados. La spec `model-management` ya especifica descubrimiento al guardar y marcado de no sanos (no implementado). La API v1 (`ModelController`) ya es compartida (`GET /models`, `GET /providers` sin scope) y los writes ya son solo-admin, así que este cambio toca solo la superficie web.

## Goals / Non-Goals

**Goals:**

- Catálogo compartido: todo usuario autenticado ve todos los proveedores y modelos; no-admin en solo lectura, admin con CRUD + comprobar.
- Edición de modelos (incl. `capabilities`) con proveedor inamovible.
- Descubrimiento de modelos al guardar proveedor (openai/other) y salud persistida y visible.
- Borrado con aviso de impacto en agentes; marcado de no sanos en el selector de creación.

**Non-Goals:**

- App iOS (selector por formato y runtimes → cambio aparte) y API v1 (ya coherente).
- Model Proxy, runtimes, MCP, cuota de agentes.
- `is_default` de modelos (no lo consume ninguna superficie hoy).
- Migración automática de agentes huérfanos al borrar su modelo (el 422 del proxy ya lo hace visible, spec `Provider failure surfacing`).

## Decisions

**D1 — Visibilidad compartida por scopes, no por middleware.**
Las rutas `models`/`providers` ya son `auth` y la nav las muestra a todos; se elimina el scope por `created_by` en `render()` y se condiciona la renderización de los botones con `isAdmin()`. Cada método de escritura (`save`, `delete`, `check`) empieza con una guarda de rol (no-admin → flash de error, sin `update`/`create`): la UI no es la única barrera. `created_by` se conserva como campo de auditoría.
*Alternativa*: middleware `role:admin` en las rutas + página de solo-lectura aparte → dos vistas del mismo recurso y el no-admin perdería el catálogo (necesario para saber qué usa su agente).

**D2 — Salud: `status` con tres estados + `last_checked_at`.**
Migración que añade a `model_providers`: `status` string(16) nullable (`null` = sin comprobar, `'ok'`, `'error'`) y `last_checked_at` timestamp nullable. La UI muestra «sano» / «no sano» / «sin comprobar» + fecha.
*Alternativa*: boolean `healthy` → no distingue "nunca comprobado" (un proveedor recién creado no debe verse "no sano" sin haberse comprobado).

**D3 — La comprobación es de formato-dependiente y se persiste en el guardado.**
`check()` (y ahora también `save()`, que la ejecuta y persiste el resultado) consulta `GET {base_url}/models` para `openai`/`other` y `GET {base_url}/v1/models` para `anthropic` — la misma convención de `/v1` que usa `ModelProxyController::upstreamPath` (el base_url openai ya lo lleva; el anthropic no). El estado actual lo hace siempre sin `/v1`, lo que para un provider anthropic comprueba una ruta inexistente.
*Alternativa*: comprobar con `GET {base_url}` → menos informativo (no toca la ruta de modelos, que es la que el descubrimiento necesita).

**D4 — Descubrimiento como merge no destructivo, solo openai/other, en create y update.**
Al guardar un proveedor de formato `openai`/`other`: `GET` del endpoint de modelos con la clave → parsear `data[].id` → `firstOrCreate(['provider_id','model_id'], ['name' => id])` por cada id. No borra filas existentes (la lista curada del admin manda; el podado es acción manual en la página de modelos). Si la consulta falla (timeout/HTTP), el provider se guarda igualmente, se marca `status=error` y se avisa con flash; el admin añade los modelos a mano. Para `anthropic` no hay descubrimiento automático (la spec lo limita a OpenAI-compatible).
*Alternativa*: botón "obtener modelos" en la página de modelos → la spec dice "cuando el admin lo guarda"; un botón extra duplicaría la acción.

**D5 — Edición de modelos en la misma página, proveedor bloqueado.**
Mismo patrón que el formulario de providers: `edit_id` + campos (`model_id`, `name`, `context_window`, `max_tokens`, `capabilities` como checkboxes `tools`/`vision`/`streaming`). El campo `provider_id` se envía oculto con el valor original (visible como etiqueta no editable): la unique `(provider_id, model_id)` se valida excluyendo el propio registro. Al abrir la edición se precarga `capabilities` desde el cast array.
*Alternativa*: permitir cambiar de proveedor → rompería la semántica de "un modelo pertenece a un proveedor" (y la `fullId()` que usan los agentes).

**D6 — Aviso de impacto con el `confirm()` ya existente, texto dinámico desde el servidor.**
`render()` calcula `withCount('agents')` por modelo y, por proveedor, n.º de modelos + agentes que usan sus modelos; el blade inyecta el texto en `data-confirm` (el script inline que ya reacciona a ese atributo se mantiene). No se añade modal nuevo.
*Alternativa*: modal Livewire dedicado → más superficie de código para lo que hoy es un `window.confirm` con un mejor texto.

**D7 — Marcado de no sanos en `Agents\Create` informativo, no bloqueante.**
El `render()` ya carga modelos con `provider`; se añade `status` al select de columnas y el blade aplica clase atenuada + etiqueta «no sano» a los modelos de proveedores con `status='error'`. La selección y el submit no se ven afectados (spec: la marca es informativa).

**D8 — Localización en los tres idiomas.**
Todas las nuevas cadenas (`providers.*`, `models.*`, `agents.*`) se añaden en `lang/{es,en,eu}` (spec `localization`: es/en/eu con es por defecto).

## Risks / Trade-offs

- [Gateways con cientos de modelos inundan el catálogo] → el merge solo crea lo que falta; el podado sigue siendo manual (página de modelos con badges).
- [El check al guardar añade latencia y un gateway sin `/v1/models` marcaría "no sano" a un provider válido] → el provider se guarda de todos modos; el flash lo avisa; el botón ⇄ permite recomprobar sin re-guardar.
- [Borrar un modelo/proveedor deja agentes huérfanos (FK `nullOnDelete`)] → aviso con recuento antes de confirmar; el proxy ya devuelve 422 visible en el chat del agente (`Provider failure surfacing`). `ConfigRenderer` tolera `model=null` (rama `if ($model)`).
- [El no-admin ve `base_url` de todos los proveedores] → la base_url no es secreta (solo lo es la clave, que nunca viaja al navegador: patrón de campo enmascarado ya en producción). Mismos datos que `GET /providers` de la API ya expone.
- [Texto de confirm con acentos en el `data-confirm` inline] → se usa `e()`/`json_encode` al renderizar para evitar romper el atributo.

## Migration Plan

- La migración añade columnas nullable: sin backfill (filas existentes = «sin comprobar»), sin downtime, reversible con `down()`.
- No hay orden de despliegue con la API v1 ni iOS (sin cambios en ellas).
- Rollback: `migrate:rollback` de la migración + revert de la rama; las páginas degradan al comportamiento actual.
