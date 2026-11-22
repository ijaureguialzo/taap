# model-management Specification

## Purpose

Permite al administrador definir proveedores de modelos de IA (endpoints compatibles con la API de OpenAI o de Anthropic) y a cada usuario elegir el modelo de sus agentes, con un proxy de modelos como único punto de salida de la inferencia.

## Requirements

### Requirement: Provider definition
El administrador SHALL poder definir proveedores de modelos con nombre, URL base, clave API (secreta) y lista de modelos, declarando el formato de la API del endpoint (compatible con OpenAI o con Anthropic).

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

### Requirement: Model proxy
El sistema SHALL enrutar las peticiones de inferencia de los agentes a través de un proxy dentro del orquestador, inyectando las credenciales del proveedor en la salida. Las claves API reales de los proveedores SHALL no ser visibles para los agentes, sus contenedores ni los usuarios.

#### Scenario: Key stays on the server
- **WHEN** un agente llama al Model Proxy
- **THEN** el proxy adjunta la clave del proveedor en la petición saliente y el contenedor del agente nunca ve la clave real

#### Scenario: Agent without Internet
- **WHEN** un agente sin acceso a Internet realiza una llamada de modelo
- **THEN** la llamada tiene éxito a través del proxy sobre la red interna

### Requirement: Responses wire passthrough
El Model Proxy SHALL servir passthrough del wire `responses` de la API OpenAI (`/v1/responses`): autenticar con el token del agente, registrar el request en el log, y reenviar la petición y la respuesta (JSON y `text/event-stream`) al proveedor sin transformación del cuerpo — el runtime Codex habla Responses nativo y el proxy no conoce su esquema. Los proveedores que no soportan `/v1/responses` devolverán un error claro del proxy (501/400) en lugar de silenciar el request.

#### Scenario: Codex streams through the proxy
- **WHEN** un agente con runtime Codex llama a `/v1/responses` con su token
- **THEN** el proxy reenvía al proveedor la petición intacta y regresa la respuesta (incluido el stream SSE) sin alterar el cuerpo

#### Scenario: Provider without Responses support
- **WHEN** un request `/v1/responses` llega a un proveedor cuyo upstream no implementa la ruta
- **THEN** el proxy devuelve un error legible (no un 200 vacío ni un hang) y lo registra en el log de peticiones

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

### Requirement: El sondeo de salud presenta la misma identidad que el proxy
La comprobación de salud de un proveedor SHALL usar la misma identidad
upstream que el proxy de modelos hacia ese proveedor: con un `base_url` en
`host.docker.internal`, el sondeo SHALL presentar como `Host`
`127.0.0.1[:puerto]` — la dirección por la que el gateway se ve a sí mismo,
igual que el proxy. Un gateway que sirva el tráfico de agentes a través del
proxy SHALL marcarse sano: el veredicto no depende del alias de red del
contenedor orquestador.

#### Scenario: Gateway con restricción de Host
- **WHEN** un gateway OpenAI-compatible (p. ej. llama.cpp con
  `--allowed-host`) solo acepta `Host: 127.0.0.1` y su `base_url` está
  declarada con `host.docker.internal`
- **THEN** la comprobación de salud presenta `Host: 127.0.0.1:puerto`,
  recibe 200 y el catálogo muestra el proveedor como sano

#### Scenario: Sincronía probe/proxy
- **WHEN** el proxy de modelos enruta peticiones de un agente al upstream
  con éxito
- **THEN** la comprobación de salud del mismo proveedor devuelve el mismo
  veredicto que el tráfico real (no sano solo si el gateway también falló
  para el proxy)
