# Spec Delta

## ADDED Requirements

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
