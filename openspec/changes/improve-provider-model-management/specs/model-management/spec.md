# Spec Delta

## ADDED Requirements

### Requirement: Catálogo compartido de proveedores y modelos
El sistema SHALL mostrar a todo usuario autenticado el catálogo completo de proveedores y modelos. Los usuarios no administradores verán el catálogo en solo lectura: podrán consultarlo, pero no crear, editar ni borrar. Solo los administradores SHALL poder crear, editar y borrar proveedores y modelos, y cada acción de escritura SHALL estar protegida en el servidor: un no-administrador que intente una escritura recibe un error claro sin modificación de datos.

#### Scenario: No-admin consulta el catálogo
- **WHEN** un usuario no administrador abre la página de proveedores
- **THEN** ve todos los proveedores del sistema con su estado, sin acciones de crear, editar, borrar ni comprobar

#### Scenario: No-admin intenta escribir
- **WHEN** un usuario no administrador intenta guardar o borrar un proveedor o un modelo
- **THEN** el sistema rechaza la acción con un error claro y no se modifica ningún dato

#### Scenario: Admin gestiona el catálogo
- **WHEN** un administrador abre la página de proveedores o de modelos
- **THEN** ve todos los elementos con las acciones de crear, editar, borrar y comprobar conectividad

### Requirement: Salud de proveedor visible en el catálogo
El sistema SHALL mostrar en el catálogo de proveedores el estado de salud de cada uno (sano o no sano) y la fecha y hora de la última comprobación de conectividad. El estado se persiste: el catálogo lo muestra sin exigir una nueva comprobación en cada visita.

#### Scenario: Proveedor no sano visible
- **WHEN** un proveedor no supera una comprobación de conectividad
- **THEN** el catálogo lo muestra marcado como no sano con la fecha y hora de la última comprobación

#### Scenario: Proveedor sano visible
- **WHEN** un proveedor supera una comprobación de conectividad
- **THEN** el catálogo lo muestra como sano con la fecha y hora de la comprobación

### Requirement: Edición de modelos
El administrador SHALL poder editar un modelo existente: identificador en el proveedor, nombre, ventana de contexto, tokens máximos y capabilities. El proveedor al que pertenece un modelo SHALL ser inmodificable desde la edición: para moverlo a otro proveedor se elimina el modelo y se crea el nuevo. La edición de un modelo en uso por agentes no interrumpirá la sesión de los agentes.

#### Scenario: Editar un modelo en uso
- **WHEN** un administrador edita el nombre o la ventana de contexto de un modelo asignado a un agente y guarda
- **THEN** los cambios quedan persistidos y el agente sigue operando con los nuevos datos sin perder su sesión

#### Scenario: Proveedor inamovible
- **WHEN** un administrador abre la edición de un modelo
- **THEN** el proveedor no es modificable en el formulario (moverlo a otro proveedor requiere borrarlo y crearlo allí)

### Requirement: Borrado con aviso de impacto
Al borrar un modelo o un proveedor, el sistema SHALL mostrar al administrador el impacto antes de confirmar: el número de agentes que usan ese modelo, o el número de modelos y de agentes afectados en el caso de un proveedor. Tras el borrado, los agentes afectados quedarán sin modelo y el sistema seguirá permitiendo gestionarlos; el Model Proxy rechazará sus llamadas de modelo hasta que se les asigne uno nuevo.

#### Scenario: Borrar un modelo en uso
- **WHEN** un administrador borra un modelo usado por tres agentes
- **THEN** la confirmación le avisa de que tres agentes quedarán sin modelo y, al confirmar, los agentes siguen existiendo y gestionables

#### Scenario: Borrar un proveedor con modelos en uso
- **WHEN** un administrador borra un proveedor cuyos modelos están en uso por agentes
- **THEN** el aviso incluye el número de modelos y de agentes afectados antes de confirmar

### Requirement: Marcado de modelos de proveedores no sanos en el selector
En el selector de modelos de la página de creación de agentes, el sistema SHALL marcar los modelos cuyo proveedor esté marcado como no sano, para que el usuario elija con conocimiento de su estado. Los modelos de proveedores sanos se muestran sin marca, y todos los modelos (sanos o no) siguen siendo seleccionables: la marca es informativa, no bloqueante.

#### Scenario: Modelo de proveedor no sano marcado
- **WHEN** un proveedor está marcado como no sano y un usuario abre la página de creación de agentes
- **THEN** los modelos de ese proveedor aparecen atenuados con la etiqueta «no sano» en el selector

#### Scenario: Selección no bloqueada
- **WHEN** un usuario selecciona un modelo cuyo proveedor está marcado no sano
- **THEN** el sistema permite crear el agente con ese modelo
