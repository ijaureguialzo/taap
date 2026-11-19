# Spec Delta

## Purpose

Permite al administrador definir proveedores de modelos de IA (endpoints compatibles con la API de OpenAI o de Anthropic) y a cada usuario elegir el modelo de sus agentes, con un proxy de modelos como único punto de salida de la inferencia.

## ADDED Requirements

### Requirement: Provider definition
El administrador SHALL poder definir proveedores de modelos con nombre, URL base, clave API (secreta) y lista de modelos, declarando el formato de la API del endpoint (OpenAI-compatible en v1.0; Anthropic reservado para runtimes futuros).

#### Scenario: Add provider
- **WHEN** el admin guarda un proveedor nuevo con endpoint y clave válidos
- **THEN** el proveedor y sus modelos quedan disponibles para la configuración de agentes

#### Scenario: Invalid endpoint
- **WHEN** el admin guarda un proveedor cuyo endpoint no supera la comprobación de conectividad del sistema
- **THEN** el sistema reporta el fallo y marca el proveedor como no sano

### Requirement: Model list discovery
Para los proveedores OpenAI-compatible, el sistema SHALL obtener la lista de modelos desde la ruta de modelos del endpoint cuando el admin lo guarda, permitiendo al admin editar manualmente la lista obtenida.

#### Scenario: Auto-discovery
- **WHEN** el admin guarda un proveedor OpenAI-compatible
- **THEN** los modelos disponibles se pueblan desde el endpoint y el admin puede añadir o quitar modelos de la lista

### Requirement: Per-agent model selection
El usuario SHALL poder elegir por agente un modelo de entre los proveedores disponibles. El modelo seleccionado SHALL mostrarse en el agente. Cambiar el modelo SHALL surtir efecto en el agente sin perder su sesión.

#### Scenario: Switch model
- **WHEN** un usuario cambia el modelo de un agente y guarda
- **THEN** el agente usa el modelo nuevo a partir de la siguiente interacción conservando su sesión

### Requirement: Model proxy
El sistema SHALL enrutar las peticiones de inferencia de los agentes a través de un proxy dentro del orquestador, inyectando las credenciales del proveedor en la salida. Las claves API reales de los proveedores SHALL no ser visibles para los agentes, sus contenedores ni los usuarios.

#### Scenario: Key stays on the server
- **WHEN** un agente llama al Model Proxy
- **THEN** el proxy adjunta la clave del proveedor en la petición saliente y el contenedor del agente nunca ve la clave real

#### Scenario: Agent without Internet
- **WHEN** un agente sin acceso a Internet realiza una llamada de modelo
- **THEN** la llamada tiene éxito a través del proxy sobre la red interna

### Requirement: Per-agent entitlement
Cada agente SHALL poder llamar al Model Proxy únicamente con su propio token y únicamente para el proveedor y modelo configurados para ese agente.

#### Scenario: Foreign token rejected
- **WHEN** un agente presenta al Model Proxy el token de otro agente
- **THEN** la petición se rechaza

### Requirement: Provider failure surfacing
Cuando el proveedor configurado sea inalcanzable o devuelva errores, el sistema SHALL hacer visible el fallo al propietario del agente en su estado y en su chat.

#### Scenario: Provider down
- **WHEN** el endpoint del proveedor de un agente deja de responder
- **THEN** el agente muestra estado «error» y el siguiente mensaje del usuario muestra el error de proveedor en el chat
