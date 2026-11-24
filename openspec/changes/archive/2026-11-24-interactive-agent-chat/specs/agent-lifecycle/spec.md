# Spec Delta — agent-lifecycle

## MODIFIED Requirements

### Requirement: Real-time agent status
El sistema SHALL exponer el estado actual de cada agente (offline, starting, idle, working, waiting-approval, error, stopped) a los clientes autorizados en tiempo real: toda transición de estado que el sistema produce o detecta SHALL notificarse a través del canal de eventos del agente, sin que los clientes necesiten recargar o reconsultar.

#### Scenario: Agent starts working
- **WHEN** un agente empieza a procesar un trabajo encolado
- **THEN** los clientes conectados del usuario (web y móvil) muestran el cambio a «working» sin necesidad de refrescar

#### Scenario: Start transition visible
- **WHEN** un usuario arranca un agente detenido y un cliente está conectado
- **THEN** el cliente ve la transición a «starting» y, cuando el agente queda listo, a «idle», sin refrescar la página

#### Scenario: Status change while no client is open
- **WHEN** el estado del agente cambia mientras ningún cliente está conectado
- **THEN** al abrirse un cliente, el estado mostrado es el actual (coherencia por consulta inicial, no por eventos perdidos)
