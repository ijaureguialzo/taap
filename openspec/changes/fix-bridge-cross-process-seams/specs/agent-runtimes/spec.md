# Spec Delta

## ADDED Requirements

### Requirement: Entrega de mensajes sin sesión IPC en memoria
Cuando un mensaje de usuario se entrega a un agente desde un proceso sin su sesión IPC adjunta (worker de cola, distinto del daemon bridge), el sistema SHALL entregarlo usando únicamente estado durable del agente (área IPC y configuración persistida, incluido el identificador de sesión/hilo). Si ese identificador aún no existe, el mensaje SHALL quedar encolado de forma durable y procesarse exactamente una vez, en orden, cuando el bridge establezca la sesión.

#### Scenario: Mensaje desde worker sin sesión adjunta
- **GIVEN** un agente con superficie stdio cuyo IPC está adjunto solo en el daemon bridge y cuyo identificador de hilo ya fue persistido
- **WHEN** un mensaje de usuario se entrega al agente desde el worker de cola (sin sesión IPC en ese proceso)
- **THEN** el mensaje se entrega al runtime y el turno se procesa, sin ningún error por falta de sesión en memoria

#### Scenario: Primer mensaje antes de establecerse la sesión
- **GIVEN** un agente cuyo runtime acaba de arrancar y cuyo identificador de sesión/hilo persistido todavía no existe
- **WHEN** un mensaje de usuario se entrega desde el worker de cola
- **THEN** el mensaje queda encolado de forma durable y el bridge lo emite exactamente una vez, en orden, cuando la sesión quede establecida, sin pérdida ni duplicado

### Requirement: Correlación del turno entre procesos
Cuando el inicio de un turno se registra en un proceso (worker de cola) y los eventos del turno se drenan del stream del agente en otro proceso (daemon bridge), el sistema SHALL correlacionar el turno a través de un identificador de ejecución compartido, de modo que el mensaje final se persista y se difunda exactamente una vez llevando el mismo identificador de ejecución que el cliente recibió al inicio del turno, y los deltas se asocien a ese mismo turno.

#### Scenario: Turno completado con el stream drenado en el bridge
- **GIVEN** un turno iniciado desde el worker de cola cuyos eventos llegan por el stream drenado en el daemon bridge
- **WHEN** el turno termina y el bridge drena el fin de turno
- **THEN** el mensaje final se persiste y se difunde exactamente una vez con el identificador de ejecución que el cliente recibió, y el historial del chat contiene la respuesta del agente

#### Scenario: Stream re-adjuntado a mitad de turno
- **GIVEN** un turno en curso cuyo stream de eventos fue re-adjuntado a mitad del turno
- **WHEN** el turno termina y el bridge drena el fin de turno
- **THEN** el mensaje final se entrega y se persiste exactamente una vez, sin duplicados en el historial

### Requirement: Recuperación de hilo Codex sin rollout
Cuando la reanudación del hilo de trabajo persistido de un agente Codex falle porque el runtime no tiene rollout de ese hilo (por ejemplo, el hilo fue creado pero nunca llegó a ejecutar un turno), el adapter SHALL descartar ese hilo, establecer uno nuevo y persistir su identificador, de modo que el agente siga aceptando mensajes sin intervención manual ni reintentos en bucle de un hilo que el runtime rechaza.

#### Scenario: Reanudación de un hilo vacío falla
- **GIVEN** un agente Codex cuyo hilo persistido fue creado pero nunca ejecutó ningún turno, y cuyo app-server rechaza su reanudación al no existir rollout
- **WHEN** el bridge adjunta el agente y completa la secuencia de arranque
- **THEN** el adapter abre un hilo nuevo, persiste su identificador y el siguiente mensaje del usuario se procesa en él sin error

### Requirement: Sin adjuntado de IPC desde el worker
El worker de cola que entrega mensajes, resuelve aprobaciones o aplica configuración de un agente SHALL no adjuntar el IPC del agente, ni spawnear, terminar ni re-adjuntar coprocesos, ni truncar su stream de eventos: el ciclo de vida del IPC es responsabilidad exclusiva del daemon bridge.

#### Scenario: Un envío desde el worker no toca el IPC
- **GIVEN** un agente con el IPC adjunto y estable en el daemon bridge (un único par de coprocesos vivo)
- **WHEN** el worker de cola entrega un mensaje al agente
- **THEN** el par de coprocesos del bridge sigue siendo el único vivo, el stream de eventos no se trunca y no quedan coprocesos huérfanos del worker
