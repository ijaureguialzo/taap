# Spec Delta

## MODIFIED Requirements

### Requirement: Per-agent model selection
El usuario SHALL poder elegir por agente un modelo de entre los proveedores cuyo formato sea compatible con el runtime del agente según las capacidades declaradas en el registro; el sistema SHALL excluir de la selección los modelos de proveedores con formato incompatible. El modelo seleccionado SHALL mostrarse en el agente. Cambiar el modelo SHALL surtir efecto en el agente sin perder su sesión.

#### Scenario: Switch model
- **WHEN** un usuario cambia el modelo de un agente y guarda
- **THEN** el agente usa el modelo nuevo a partir de la siguiente interacción conservando su sesión

#### Scenario: Incompatible format filtered
- **WHEN** un usuario elige modelo para un agente cuyo runtime consume solo el formato Anthropic y existen proveedores disponibles en ambos formatos
- **THEN** el selector ofrece únicamente los modelos de los proveedores de formato Anthropic

#### Scenario: Dual-format runtime
- **WHEN** un usuario elige modelo para un agente cuyo runtime consume ambos formatos y existen proveedores en OpenAI y en Anthropic
- **THEN** el selector ofrece los modelos de todos los proveedores disponibles
