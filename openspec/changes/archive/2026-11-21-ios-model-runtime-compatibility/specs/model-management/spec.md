# Spec Delta

## MODIFIED Requirements

### Requirement: Per-agent model selection
El usuario SHALL poder elegir por agente un modelo de entre los proveedores cuyo formato sea compatible con el runtime del agente según las capacidades declaradas en el registro; el sistema SHALL excluir de la selección los modelos de proveedores con formato incompatible en todas las superficies de selección (web, app y API). El modelo seleccionado SHALL mostrarse en el agente. Cambiar el modelo SHALL surtir efecto en el agente sin perder su sesión.

#### Scenario: Switch model
- **WHEN** un usuario cambia el modelo de un agente y guarda
- **THEN** el agente usa el modelo nuevo a partir de la siguiente interacción conservando su sesión

#### Scenario: Incompatible format filtered
- **WHEN** un usuario elige modelo para un agente cuyo runtime consume solo el formato Anthropic y existen proveedores disponibles en ambos formatos
- **THEN** el selector ofrece únicamente los modelos de los proveedores de formato Anthropic

#### Scenario: Dual-format runtime
- **WHEN** un usuario elige modelo para un agente cuyo runtime consume ambos formatos y existen proveedores en OpenAI y en Anthropic
- **THEN** el selector ofrece los modelos de todos los proveedores disponibles

## ADDED Requirements

### Requirement: Lista de modelos filtrable por runtime
La API de lista de modelos SHALL aceptar un filtro opcional por runtime, devolviendo solo los modelos de proveedores con formato compatible con ese runtime. Sin filtro, SHALL devolver todos los modelos.

#### Scenario: Filtrado por runtime
- **WHEN** un cliente consulta la lista de modelos con filtro por un runtime de solo formato Anthropic
- **THEN** la API devuelve únicamente los modelos de proveedores de formato Anthropic

#### Scenario: Sin filtro
- **WHEN** un cliente consulta la lista de modelos sin parámetro de runtime
- **THEN** la API devuelve todos los modelos

### Requirement: Compatibilidad modelo-runtime en la API
La creación o actualización de agentes SHALL validar el formato del modelo contra el runtime del agente. Si son incompatibles, la API SHALL devolver un error de validación claro y el agente no quedará con ese modelo.

#### Scenario: La API rechaza un par incompatible
- **WHEN** un cliente crea o actualiza un agente con un modelo cuyo proveedor tiene un formato incompatible con el runtime del agente
- **THEN** la API devuelve un error de validación claro y el agente no queda con ese modelo

### Requirement: Información de proveedor en la lista de modelos
La respuesta de la lista de modelos SHALL incluir, para cada modelo, el formato de su proveedor y el estado de salud del proveedor, para que los clientes puedan mostrarlos.

#### Scenario: El payload identifica formato y salud
- **WHEN** un cliente consulta la lista de modelos
- **THEN** cada modelo incluye el formato de su proveedor (OpenAI, Anthropic u otro) y su estado de salud
