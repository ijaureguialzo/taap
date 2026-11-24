# agent-runtimes Specification

## Purpose

Define el registro de runtimes de agente soportados y la integración de cada runtime (v1: Pi y Hermes) a través de una interfaz de adapter común que normaliza sus eventos y aplica su configuración nativa.

## Requirements

### Requirement: Runtime registry
El sistema SHALL mantener un registro de runtimes de agente disponibles para crear agentes. Cada entrada SHALL incluir nombre para mostrar, versión fijada, imagen y capacidades (formatos de alambre que el runtime consume, modelo de aprobaciones y mecanismo de aplicación de configuración). Esta versión SHALL incluir cinco runtimes: `pi`, `hermes`, `claude`, `codex` y `opencode`. El registro SHALL ser la única fuente de verdad del flujo de creación de agentes y los formularios de creación de agente SHALL ofrecer exactamente los runtimes del registro.

#### Scenario: Choose runtime at creation
- **WHEN** un usuario crea un nuevo agente
- **THEN** la selección de runtime ofrece exactamente los runtimes del registro (Pi, Hermes, Claude Code, Codex y OpenCode)

### Requirement: Pi adapter
El sistema SHALL integrar el runtime Pi a través de su interfaz RPC de larga vida (JSON sobre stdin/stdout), conduciendo el proceso del agente dentro del contenedor: entregar mensajes del usuario, recibir eventos normalizados y reanudar la sesión persistida del agente tras reinicios del contenedor. La reconexión IPC tras un reinicio la gestiona el daemon `TaapBridgeCommand`, no el adapter.

#### Scenario: Message via Pi
- **WHEN** un mensaje del usuario se entrega a un agente Pi
- **THEN** el adapter lo traslada por la interfaz RPC y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Pi se reinicia
- **THEN** el agente reanuda la sesión previa con su contexto intacto

#### Scenario: Pi reasoning mapped
- **WHEN** el modelo de un agente Pi emite reasoning durante el turno
- **THEN** el adapter lo expone como eventos de razonamiento normalizados (separados de los deltas de texto) y lo incluye en el mensaje final

### Requirement: Hermes adapter
El sistema SHALL integrar el runtime Hermes a través de su API server compatible con OpenAI, autenticándose con una clave por agente y conservando la continuidad de sesión entre reinicios gracias al directorio de datos persistente del agente.

#### Scenario: Message via Hermes
- **WHEN** un mensaje del usuario se entrega a un agente Hermes
- **THEN** el adapter llama al API server y transmite la respuesta y la actividad a la pipeline de chat

#### Scenario: Hermes reasoning mapped
- **WHEN** el modelo de un agente Hermes emite contenido de razonamiento en el stream
- **THEN** el adapter lo expone como razonamiento normalizado (separado del contenido) y lo incluye en el mensaje final

### Requirement: Claude Code adapter
El sistema SHALL integrar el runtime Claude Code a través de un proceso residente en el contenedor del agente basado en su Agent SDK oficial, comunicándose con él por la interfaz JSONL de los flujos estándar del contenedor: entregar mensajes del usuario, recibir eventos normalizados (emitidos por el propio proceso residente) y reanudar la sesión persistida del agente tras reinicios del contenedor.

#### Scenario: Message via Claude Code
- **WHEN** un mensaje del usuario se entrega a un agente Claude Code
- **THEN** el adapter lo traslada al runtime y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Claude Code se reinicia
- **THEN** el agente reanuda la sesión previa con su contexto intacto

#### Scenario: Claude reasoning mapped
- **WHEN** el modelo de un agente Claude Code emite bloques de razonamiento
- **THEN** el proceso residente lo expone como razonamiento normalizado y el adapter lo transmite a la pipeline (incluido en el mensaje final, sin descartarlo)

### Requirement: Codex adapter
El sistema SHALL integrar el runtime Codex a través de su app server (interfaz JSON-RPC de larga vida sobre los flujos estándar del contenedor): entregar mensajes del usuario, recibir eventos normalizados y reanudar el hilo de trabajo persistido del agente tras reinicios del contenedor.

#### Scenario: Message via Codex
- **WHEN** un mensaje del usuario se entrega a un agente Codex
- **THEN** el adapter lo traslada al app server y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Codex se reinicia
- **THEN** el agente reanuda el hilo de trabajo previo con su contexto intacto

#### Scenario: Codex reasoning mapped
- **WHEN** el modelo de un agente Codex emite razonamiento durante un turno
- **THEN** el adapter lo expone como razonamiento normalizado (separado de los deltas de texto) y lo incluye en el mensaje final

### Requirement: OpenCode adapter
El sistema SHALL integrar el runtime OpenCode a través de su servidor API (`opencode serve`) dentro del contenedor: comandos por HTTP y recepción de eventos a través del stream de eventos del servidor, gestionando una sesión explícita por agente que se reanuda tras reinicios del contenedor y resolviendo las solicitudes de permiso del runtime con las decisiones del usuario.

#### Scenario: Message via OpenCode
- **WHEN** un mensaje del usuario se entrega a un agente OpenCode
- **THEN** el adapter lo envía a la sesión del agente y transmite la actividad (mensajes, herramientas, permisos) del stream a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente OpenCode se reinicia
- **THEN** el agente reanuda su sesión previa con su contexto intacto

#### Scenario: Permission resolved via API
- **WHEN** el stream del agente emite una solicitud de permiso y el usuario decide
- **THEN** el adapter resuelve el permiso por la API del runtime y el agente continúa o se detiene según la decisión

#### Scenario: OpenCode reasoning mapped
- **WHEN** el stream del agente emite partes de razonamiento (separadas de las partes de texto)
- **THEN** el adapter las expone como razonamiento normalizado y el contenido final del mensaje no las incluye fundidas con el texto

### Requirement: Normalized agent events
Todos los runtimes SHALL exponerse a la pipeline de chat a través de un modelo común de eventos que incluya al menos: delta de mensaje, delta de razonamiento, mensaje final, inicio de herramienta, resultado de herramienta, solicitud de aprobación, cambio de estado, inicio de ejecución, fin de ejecución y error. El mensaje final SHALL llevar además el razonamiento completo del turno. Las aplicaciones cliente SHALL consumir solo el modelo normalizado, nunca formatos específicos de cada runtime.

#### Scenario: Identical event shape
- **WHEN** un agente Pi y un agente Hermes realizan la misma clase de acción (por ejemplo, ejecutar un comando)
- **THEN** la pipeline de chat recibe eventos de los mismos tipos normalizados y la interfaz los renderiza de forma idéntica

#### Scenario: Reasoning parity across runtimes
- **WHEN** los modelos de un agente de un runtime y de otro runtime emiten razonamiento durante el mismo tipo de turno
- **THEN** ambos producen los mismos tipos de eventos normalizados de razonamiento (mismo shape, mismo canal) y la interfaz lo muestra de forma idéntica (sección «razonamiento» separada del texto)

#### Scenario: Reasoning never mixed with text
- **WHEN** un modelo emite razonamiento y texto en el mismo turno
- **THEN** la pipeline recibe razonamiento y texto como flujos separados y el contenido final del mensaje no contiene el razonamiento fundido en él

### Requirement: Per-runtime configuration delivery
El sistema SHALL renderizar la configuración nativa de cada runtime (endpoint de modelo, servidores MCP, credenciales) en el directorio persistente del agente y aplicar los cambios de configuración por el mecanismo que el runtime soporte, sin perder el estado de la sesión del agente. Para el runtime Pi, la aplicación de configuración consiste exclusivamente en re-renderizar la config nativa y reanudar la sesión: la reconexión IPC es responsabilidad del daemon bridge.

#### Scenario: Model change without session loss
- **WHEN** un usuario cambia el modelo de un agente
- **THEN** la nueva configuración se aplica al agente y el historial de la conversación y la sesión se conservan

### Requirement: Runtime health and versioning
El sistema SHALL reportar por agente si su runtime está sano y SHALL fijar la versión del runtime en las imágenes de agente. La actualización de un runtime SHALL ser una acción explícita del administrador que recrea los agentes afectados conservando su estado persistido.

#### Scenario: Runtime upgrade
- **WHEN** el admin actualiza la versión de la imagen de Pi
- **THEN** el admin puede recrear los agentes Pi afectados y estos reanudan con sus sesiones intactas

#### Scenario: Runtime unhealthy
- **WHEN** el proceso de un agente no está sano
- **THEN** el agente muestra estado «error» y el sistema intenta su recuperación automática

### Requirement: Registro expuesto a los clientes
El sistema SHALL exponer el registro de runtimes a los clientes a través de la API pública: una lista que contenga exactamente los runtimes del registro, cada uno con su identificador, su nombre para mostrar y los formatos de alambre de modelo que consume.

#### Scenario: Un cliente consulta los runtimes
- **WHEN** un cliente autenticado consulta la lista de runtimes por la API
- **THEN** recibe exactamente los runtimes del registro (cinco en esta versión) con identificador, etiqueta y formatos

#### Scenario: Runtime nuevo en el registro
- **WHEN** un runtime se añade al registro del sistema y se despliega
- **THEN** aparece en la lista que la API expone, sin cambios adicionales en los clientes

### Requirement: Ciclo de vida de la sesión IPC del bridge
El daemon bridge de 24/7 SHALL mantener exactamente un par vivo de coprocesos de IPC (un lector y, si el runtime expone superficie de escritura, un escritor) por cada agente en ejecución con superficie stdio. La recuperación por «lector stale» SHALL solo dispararse cuando haya entrada realmente pendiente de entregar o el agente esté activo, y cada re-adjuntado SHALL terminar los coprocesos previos, sin duplicar el mensaje final de un turno.

#### Scenario: Agente en reposo sin churn
- **GIVEN** un agente en reposo con más de un minuto sin salida y sin entrada pendiente de entregar
- **WHEN** el bridge hace su ciclo de reconciliación de sesiones
- **THEN** no se produce ningún re-adjuntado y el agente conserva su único par de coprocesos IPC vivos

#### Scenario: Sin acumulación de huérfanos
- **GIVEN** un agente cuyo IPC ha sido re-adjuntado varias veces (reinicios o recuperaciones)
- **WHEN** el administrador inspecciona los procesos del orquestador
- **THEN** solo existe un lector y un escritor por agente en ejecución; los coprocesos de re-adjuntados anteriores han sido terminados

#### Scenario: Mensaje final único
- **GIVEN** un agente que completa un turno cuyo IPC fue re-adjuntado a mitad del turno
- **WHEN** el agente emite su mensaje final
- **THEN** el mensaje final se entrega y se persiste exactamente una vez, sin duplicados en el historial

#### Scenario: Lector realmente mudo se recupera
- **GIVEN** un lector cuyo flujo dejó de entregar salida y hay entrada pendiente de entregar al agente
- **WHEN** transcurre la ventana del watchdog
- **THEN** el bridge re-adjunta el IPC y los eventos del agente vuelven a fluir hacia la pipeline de chat
