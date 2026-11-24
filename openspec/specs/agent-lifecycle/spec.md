# agent-lifecycle Specification

## Purpose

Gestiona el ciclo de vida completo de los agentes de IA: creación en contenedores aislados, disponibilidad 24/7 con auto-reinicio, y recursos por agente (sistemas de archivos, red, CPU y memoria).

## Requirements

### Requirement: Agent creation
El sistema SHALL permitir a cualquier usuario autenticado crear un agente especificando: un nombre único, un runtime del registro, un directorio de trabajo (workspace), un modelo, el conjunto de servidores MCP conectados, el acceso a Internet y los límites de recursos. El sistema SHALL arrancar el contenedor del agente tras su creación.

#### Scenario: Successful creation
- **WHEN** un usuario crea un agente con parámetros válidos
- **THEN** el sistema crea el agente, arranca su contenedor y el agente aparece en la lista del usuario con estado «starting» que evoluciona a «idle»

#### Scenario: Duplicate name
- **WHEN** un usuario crea un agente con un nombre ya usado por uno de sus agentes
- **THEN** el sistema rechaza la creación y muestra un error que nombra el conflicto

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

### Requirement: Per-agent filesystem access
El usuario SHALL poder definir por agente montajes de directorios del host en solo lectura o lectura-escritura. El workspace del agente y su directorio de datos de runtime SHALL persistir en volúmenes Docker que sobrevivan a la recreación del contenedor.

#### Scenario: Read-write mount
- **WHEN** un agente con un montaje lectura-escritura modifica un fichero dentro del directorio montado
- **THEN** el cambio es visible en el host

#### Scenario: Read-only mount
- **WHEN** un agente intenta escribir dentro de un montaje en solo lectura
- **THEN** la escritura falla y el agente reporta el error en el chat

### Requirement: Per-agent Internet access
El usuario SHALL poder activar o desactivar el acceso a Internet de cada agente. Un agente sin Internet SHALL poder alcanzar el Model Proxy a través de la red interna.

#### Scenario: Agent without Internet
- **WHEN** un agente sin Internet intenta conectar con un dominio externo
- **THEN** la conexión falla, mientras que sus llamadas al modelo a través del Model Proxy tienen éxito

### Requirement: Per-agent resource limits
El usuario SHALL poder definir por agente límites de CPU y memoria que se aplicarán a su contenedor.

#### Scenario: Memory limit applied
- **WHEN** un usuario establece un límite de memoria y guarda el agente
- **THEN** el contenedor queda reconfigurado con el límite y el límite es visible en los detalles del agente

### Requirement: Agent stop, start, recreate and destroy
El usuario SHALL poder detener, arrancar, recrear y borrar sus propios agentes. Recrear un agente SHALL conservar su estado persistido (workspace, sesión, memorias). Borrar un agente SHALL eliminar su contenedor y, con confirmación explícita del usuario, sus volúmenes.

#### Scenario: Stop and start
- **WHEN** un usuario detiene un agente en ejecución y más tarde lo arranca de nuevo
- **THEN** el agente vuelve con su sesión y workspace anteriores intactos

#### Scenario: Destroy with confirmation
- **WHEN** un usuario borra un agente y confirma la eliminación de volúmenes
- **THEN** el contenedor y sus volúmenes se eliminan y el agente desaparece de la lista

### Requirement: Agent listing
Cada usuario SHALL poder listar sus agentes con nombre, runtime, estado y modelo actual.

#### Scenario: List own agents
- **WHEN** un usuario abre la pantalla de agentes
- **THEN** ve únicamente sus propios agentes con su estado en vivo

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
