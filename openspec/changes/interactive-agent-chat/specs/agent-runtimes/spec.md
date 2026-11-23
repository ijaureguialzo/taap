# Spec Delta — agent-runtimes

## MODIFIED Requirements

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
