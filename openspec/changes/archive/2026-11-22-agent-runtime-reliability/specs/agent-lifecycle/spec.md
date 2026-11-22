# Spec Delta

## ADDED Requirements

### Requirement: Direccionamiento de red único y estable por agente
Cada agente con IP fija en la red interna SHALL recibir una dirección que
no esté reservada por ningún otro agente: la asignación SHALL excluir,
además de las IPs en uso por contenedores conectados a la red, las
direcciones ya asignadas en la base de datos a agentes existentes (sus
contenedores pueden estar creados sin arrancar y no aparecer en la red).
Ninguna IP SHALL estar asignada simultáneamente a dos agentes.

#### Scenario: Dos agentes, una red
- **WHEN** se provisiona un segundo agente mientras el primero tiene IP
  asignada pero su contenedor no está conectado a la red (estado `Created`)
- **THEN** el segundo agente recibe una dirección distinta de la del
  primero y ambos pueden arrancar sin conflicto

#### Scenario: IP reservada no se duplica
- **WHEN** el asignador evalúa el rango de direcciones disponibles
- **THEN** descarta las IPs en vivo de la red y las IPs ya guardadas en
  agentes de la base de datos

## MODIFIED Requirements

### Requirement: 24/7 availability with auto-restart
Los agentes en ejecución SHALL reiniciarse automáticamente cuando su proceso
o contenedor falle, cuando su contenedor exista sin haberse arrancado
(estado `created`, p. ej. por un fallo de arranque transitorio) o cuando el
host se reinicie, y SHALL reanudar su sesión y workspace persistidos. Los
agentes SHALL seguir trabajando aunque ningún cliente esté conectado.

#### Scenario: Container crash
- **WHEN** el contenedor de un agente termina de forma inesperada
- **THEN** el sistema lo reinicia, el agente reanuda su sesión anterior y
  el historial de estados registra el incidente

#### Scenario: Container created but never started
- **WHEN** el contenedor de un agente que debe estar vivo existe en estado
  `created` (creado sin arrancar, por ejemplo por un error transitorio de
  red en el arranque)
- **THEN** el sistema lo arranca y el agente vuelve a su ciclo normal de
  estados

#### Scenario: Host reboot
- **WHEN** el host se reinicia y Docker arranca
- **THEN** todos los agentes que estaban en ejecución vuelven a funcionar
  con su estado intacto
