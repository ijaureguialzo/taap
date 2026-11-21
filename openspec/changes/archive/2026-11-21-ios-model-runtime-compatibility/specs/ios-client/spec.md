# Spec Delta

## MODIFIED Requirements

### Requirement: Agent configuration
La aplicación SHALL permitir configurar por agente el modelo, los servidores MCP, el acceso a Internet y los límites de recursos, con las mismas reglas de validación que la web: el selector de modelos ofrecerá únicamente los modelos compatibles con el runtime del agente, marcará los modelos de proveedores no sanos sin bloquearlos, y el guardado se validará con las mismas reglas que la web (el servidor las aplica en todo caso).

#### Scenario: Change model from phone
- **WHEN** un usuario cambia el modelo de un agente desde la aplicación
- **THEN** el cambio se aplica con el mismo efecto que desde la web

#### Scenario: Selector filtrado por el formato del agente
- **WHEN** un usuario abre la configuración de un agente cuyo runtime consume solo un formato y existen proveedores en varios formatos
- **THEN** el selector ofrece únicamente los modelos de los proveedores de ese formato

#### Scenario: Marca de proveedor no sano
- **WHEN** el proveedor de un modelo del selector está marcado como no sano
- **THEN** la aplicación lo muestra atenuado con la etiqueta «no sano», sin bloquear la selección

## ADDED Requirements

### Requirement: Creación de agentes desde la aplicación
La aplicación SHALL permitir crear agentes ofreciendo en el selector de runtime exactamente los runtimes soportados por el servicio (consultados a la API, no hardcodeados) y filtrando la lista de modelos según el formato del runtime elegido. Al cambiar de runtime, un modelo seleccionado incompatible se descarta con un aviso visible, y se re-restaura automáticamente si el usuario vuelve a un runtime compatible.

#### Scenario: Runtimes desde el servicio
- **WHEN** un usuario abre el formulario de creación de agentes
- **THEN** el selector de runtime ofrece exactamente los runtimes que el servicio soporta (los del registro)

#### Scenario: Modelos filtrados por el runtime elegido
- **WHEN** el usuario elige un runtime de solo un formato en el formulario de creación
- **THEN** el selector de modelos ofrece únicamente los modelos de proveedores de ese formato

#### Scenario: Descarte con aviso al cambiar de runtime
- **WHEN** el usuario cambia a un runtime con el que el modelo seleccionado es incompatible
- **THEN** la aplicación descarta el modelo avisando al usuario, y lo re-restaura si el usuario vuelve a un runtime compatible
