# Spec Delta

## MODIFIED Requirements

### Requirement: Provider definition
El administrador SHALL poder definir proveedores de modelos con nombre, URL base, clave API (secreta) y lista de modelos, declarando el formato de la API del endpoint (compatible con OpenAI o con Anthropic).

#### Scenario: Add provider
- **WHEN** el admin guarda un proveedor nuevo con endpoint y clave válidos
- **THEN** el proveedor y sus modelos quedan disponibles para la configuración de agentes

#### Scenario: Invalid endpoint
- **WHEN** el admin guarda un proveedor cuyo endpoint no supera la comprobación de conectividad del sistema
- **THEN** el sistema reporta el fallo y marca al proveedor como no sano

### Requirement: Per-agent model selection
El usuario SHALL poder elegir por agente un modelo de entre los proveedores cuyo formato sea compatible con el runtime del agente; el sistema SHALL excluir de la selección los modelos de proveedores con formato incompatible. El modelo seleccionado SHALL mostrarse en el agente. Cambiar el modelo SHALL surtir efecto en el agente sin perder su sesión.

#### Scenario: Switch model
- **WHEN** un usuario cambia el modelo de un agente y guarda
- **THEN** el agente usa el modelo nuevo a partir de la siguiente interacción conservando su sesión

#### Scenario: Incompatible format filtered
- **WHEN** un usuario elige modelo para un agente cuyo runtime consume solo el formato Anthropic y existen proveedores disponibles en ambos formatos
- **THEN** el selector ofrece únicamente los modelos de los proveedores de formato Anthropic
