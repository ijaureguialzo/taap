# Spec Delta

## ADDED Requirements

### Requirement: Agent templates
El sistema SHALL permitir a cada usuario crear, editar y borrar plantillas de agente (nombre, runtime, modelo sugerido, servidores MCP precargados, Internet, límites de CPU/memoria) y SHALL ofrecerlas como punto de partida en el flujo de creación de agentes (web y iOS). Al usar una plantilla, el formulario se rellena con los valores de la plantilla; el usuario SHALL poder modificarlos antes de crear. Las validaciones de creación de agente (runtime, modelo, MCP) SHALL seguir aplicándose tal cual.

#### Scenario: Create from template
- **WHEN** un usuario abre la creación de agente y selecciona una plantilla
- **THEN** el formulario se rellena con los valores de la plantilla y el usuario puede confirmar o modificar antes de crear

#### Scenario: Modify from template
- **WHEN** el usuario modifica un campo pre-relleno y crea el agente
- **THEN** el agente se crea con los valores modificados

#### Scenario: Template validation
- **WHEN** el usuario crea una plantilla cuyo modelo es incompatible con el runtime elegido
- **THEN** la plantilla se rechaza con un error claro en el momento de la creación de la plantilla

#### Scenario: Template deletion
- **WHEN** el usuario borra una plantilla
- **THEN** la plantilla deja de estar disponible en el flujo de creación
