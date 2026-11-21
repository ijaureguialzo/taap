# Proposal

## Why

La gestión de proveedores y modelos en la web está incompleta e inconsistente con la spec `model-management`: las páginas de catálogo limitan la visibilidad al creador (un admin solo ve los proveedores que él mismo creó), los modelos no se pueden editar (un error en `model_id` obliga a borrar y recrear, y los agentes que lo usan pierden el modelo en silencio), y los requisitos ya especificados de descubrimiento de modelos al guardar un proveedor y de marcado de proveedores no sanos no están implementados. El admin no puede gestionar el catálogo correctamente y el usuario no puede saber qué está eligiendo al crear un agente.

## What Changes

- **Catálogo compartido**: las páginas `providers` y `models` muestran todos los proveedores y modelos a cualquier usuario autenticado. Los no-administradores lo ven en solo lectura; solo los administradores ven y ejecutan las acciones de crear/editar/borrar/comprobar, guardadas también en el servidor (una escritura de un no-admin devuelve un error, no un resultado parcial).
- **Edición de modelos (web)**: nuevo formulario para `model_id`, nombre, ventana de contexto, tokens máximos y `capabilities` (checkboxes `tools`/`vision`/`streaming`). El proveedor del modelo es **inmodificable** (moverlo a otro proveedor = borrar y crear).
- **Descubrimiento de modelos al guardar proveedor**: para formato `openai`/`other`, el sistema consulta `GET {base_url}/models` y crea los modelos aún no registrados (conserva los curados manualmente; no borra). Si la consulta falla, se informa y el proveedor queda marcado no sano.
- **Salud de proveedor persistida**: nueva comprobación guardada en `model_providers` (estado + última fecha de comprobación); se ejecuta al guardar y bajo demanda (botón existente), y el catálogo muestra el estado.
- **Borrado seguro**: borrar un modelo o un proveedor muestra antes de confirmar el impacto (n.º de agentes que lo usan; en proveedores, modelos y agentes afectados). La FK `nullOnDelete` ya deja al agente sin modelo y el proxy lo hace visible con un 422 hasta que se le asigna otro.
- **Selector de creación de agentes**: los modelos de proveedores marcados no sanos se muestran atenuados con etiqueta «no sano» (informativo: no bloquea la selección).
- **Sin cambios** en la API v1, la app iOS ni el Model Proxy: `GET /models` y `GET /providers` de la API ya son compartidos y coherentes con este modelo; el problema de compatibilidad del selector iOS se difiere a un cambio aparte.

## Capabilities

### New Capabilities

(ninguna)

### Modified Capabilities

- `model-management`: añade requisitos de catálogo compartido (solo-lectura para no-admins, gestión por admin), visibilidad del estado de salud en el catálogo, edición de modelos, borrado con aviso de impacto en agentes y marcado de modelos de proveedores no sanos en el selector.

## Impact

- **Web (Livewire)**: `app/Livewire/Providers/Index.php` y `app/Livewire/Models/Index.php` (scopes, guardas de rol, descubrimiento, salud, edición), `app/Livewire/Agents/Create.php` + sus vistas blade (marca de no sano).
- **Datos**: migración que añade `status` y `last_checked_at` a `model_providers`; `app/Models/ModelProvider.php` (campos nuevos).
- **Idiomas**: nuevas cadenas en `lang/{es,en,eu}/{providers,models,agents}.php` (spec `localization`).
- **Tests**: Feature tests de Livewire para el flujo admin/no-admin de ambas páginas y del guardado con descubrimiento.
- No toca: API v1 (`ModelController`, `AgentController`), app iOS, Model Proxy, runtimes.
