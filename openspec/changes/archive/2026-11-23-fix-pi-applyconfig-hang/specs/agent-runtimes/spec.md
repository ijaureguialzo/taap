# Spec Delta

## MODIFIED Requirements

### Requirement: Pi adapter
El sistema SHALL integrar el runtime Pi a través de su interfaz RPC de larga vida (JSON sobre stdin/stdout), conduciendo el proceso del agente dentro del contenedor: entregar mensajes del usuario, recibir eventos normalizados y reanudar la sesión persistida del agente tras reinicios del contenedor. La reconexión IPC tras un reinicio la gestiona el daemon `TaapBridgeCommand`, no el adapter.

#### Scenario: Message via Pi
- **WHEN** un mensaje del usuario se entrega a un agente Pi
- **THEN** el adapter lo traslada por la interfaz RPC y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Pi se reinicia
- **THEN** el agente reanuda la sesión previa con su contexto intacto

### Requirement: Per-runtime configuration delivery
El sistema SHALL renderizar la configuración nativa de cada runtime (endpoint de modelo, servidores MCP, credenciales) en el directorio persistente del agente y aplicar los cambios de configuración por el mecanismo que el runtime soporte, sin perder el estado de la sesión del agente. Para el runtime Pi, la aplicación de configuración consiste exclusivamente en re-renderizar la config nativa y reanudar la sesión: la reconexión IPC es responsabilidad del daemon bridge.

#### Scenario: Model change without session loss
- **WHEN** un usuario cambia el modelo de un agente
- **THEN** la nueva configuración se aplica al agente y el historial de la conversación y la sesión se conservan
