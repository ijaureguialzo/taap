# Tasks

## 1. Salud de proveedores (persistencia y comprobación)

- [x] 1.1 Migración que añade `status` string(16) nullable y `last_checked_at` timestamp nullable a `model_providers` (down: drop), y añadir ambos a `ModelProvider` (fillable + cast datetime). Verificar: `php artisan migrate` corre en la base de pruebas y `vendor/bin/phpstan analyse` pasa.
- [ ] 1.2 En `Providers\Index`: `check()` persiste el resultado (`status` ok/error + `last_checked_at`); `save()` ejecuta la comprobación al crear y actualizar y persiste el resultado (el provider se guarda en ambos casos); la ruta comprobada depende del formato (`/models` para openai/other, `/v1/models` para anthropic, según D3). Verificar: `tests/Feature/Providers/IndexTest.php` nuevo con `Http::fake()` — check exitoso → `status=ok` + fecha; check fallido (HTTP 500/timeout) → `status=error`; save con endpoint caído → provider persistido + `status=error` + flash de aviso.
- [ ] 1.3 Badge de salud en la tabla de `providers/index.blade.php` (sano / no sano / sin comprobar + última fecha), usando las cadenas localizadas. Verificar: Livewire test que asierte el badge en los tres estados (factories con cada `status`).

## 2. Descubrimiento de modelos al guardar proveedor

- [ ] 2.1 En `Providers\Index::save()` para tipo `openai`/`other`: tras el guardado, `GET` del endpoint de modelos con la clave, parsear `data[].id` y `firstOrCreate(['provider_id','model_id'], ['name' => id])` por cada id; no borrar filas existentes; en fallo de la consulta, flash de aviso sin deshacer el guardado (D4). Verificar: feature test con `Http::fake()` — respuesta con 3 ids (1 ya registrado) → se crean 2 y el existente no se toca; respuesta 500 → provider guardado y sin modelos nuevos.

## 3. Catálogo compartido (solo-lectura para no-admins)

- [ ] 3.1 Quitar el scope por `created_by` en `render()` de `Providers\Index` y `Models\Index` (ver todos) y condicionar los botones de crear/editar/borrar/comprobar con `isAdmin()`. Verificar: Livewire tests — no-admin ve proveedores y modelos creados por otros sin botones de acción; admin ve todos con botones.
- [ ] 3.2 Guardas de rol en el servidor al inicio de cada método de escritura de ambos componentes (`save`, `delete`, `check`): no-admin → flash de error y sin mutación. Verificar: feature test que dispare cada método como no-admin y aserte (a) mensaje de error y (b) la base sin cambios (n.º y valores de filas).

## 4. Edición de modelos

- [ ] 4.1 En `Models\Index`: estado `edit_id` + formulario de edición con `model_id`, `name`, `context_window`, `max_tokens` y checkboxes `capabilities` (`tools`/`vision`/`streaming`); `provider_id` oculto fijo con el valor original (etiqueta visible, inmodificable — D5); unique `(provider_id, model_id)` excluyendo el propio registro; precarga de `capabilities` desde el cast array. Verificar: Livewire test — editar un modelo usado por un agente → campos actualizados, `provider_id` intacto; duplicar `model_id` del mismo provider → error de validación sin guardar.

## 5. Borrado seguro

- [ ] 5.1 Impacto calculado en `render()`: `withCount('agents')` por modelo y, por proveedor, n.º de modelos y n.º de agentes que usan sus modelos; texto de `data-confirm` dinámico («…¿N agentes quedarán sin modelo?») con `json_encode`/`e()` en el atributo (D6), en ambos componentes. Verificar: Livewire tests — borrar un modelo usado por 2 agentes → el confirm incluye «2»; borrar un proveedor con 2 modelos usados por 3 agentes → el aviso incluye 2 y 3.

## 6. Marcado de no sanos en la creación de agentes

- [ ] 6.1 En `Agents\Create::render()` cargar el `status` del provider junto al modelo y, en `agents/create.blade.php`, aplicar clase atenuada + etiqueta «no sano» a los modelos de providers con `status=error` (selección y submit no bloqueados, D7). Verificar: añadir a `tests/Feature/Agents/CreateFormTest.php` — proveedor con `status=error` → su modelo aparece con la etiqueta y sigue seleccionable; proveedor `ok`/null → sin etiqueta.

## 7. Localización

- [ ] 7.1 Añadir todas las cadenas nuevas en `lang/{es,en,eu}/{providers,models,agents}.php` (estados de salud y fecha, aviso de descubrimiento fallido, etiquetas de edición, textos de confirmación con impacto, «no sano»). Verificar: cada clave `__()` usada en los blade/components modificados existe en los tres idiomas (búsqueda sistemática) y los tests de `tests/Feature/Settings/LanguageTest.php` siguen en verde.

## 8. Integración y regresión

- [ ] 8.1 Prueba de flujo completo (nuevo en `tests/Feature/Providers/` o `Models/`): admin crea proveedor (check ok + descubrimiento) → no-admin consulta el catálogo en solo-lectura → admin edita un modelo → se crea un agente con un modelo sano → el provider pasa a `error` → el modelo queda marcado en el formulario de creación. Verificar: el test pasa de punta a punta.
- [ ] 8.2 Ejecutar `php artisan test`, `vendor/bin/phpstan analyse` y `vendor/bin/pint --test` en el orquestador y corregir cualquier regresión. Verificar: las tres suites salen en verde.

## Workflow follow-up

- Archivar el cambio cuando el flujo de revisión del proyecto esté satisfecho.
- Verificar el resultado del archivo (los requisitos del delta quedan en `openspec/specs/model-management/spec.md`).
