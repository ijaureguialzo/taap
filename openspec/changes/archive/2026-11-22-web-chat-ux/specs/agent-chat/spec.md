# Spec Delta

## ADDED Requirements

### Requirement: Horas locales del visor
Las marcas de tiempo que la interfaz web muestra (instante de los mensajes
del chat y de las ventanas de la línea de tiempo) SHALL representarse en la
zona horaria del dispositivo del visor: el servidor transmite el instante
como valor absoluto (ISO 8601 con offset o Unix) y el cliente lo formatea.
El servidor NO SHALL formatear la hora en su propia zona horaria como texto
final: un visor a UTC+1 no debe ver una hora desplazada respecto a su
reloj.

#### Scenario: Visor con UTC+1
- **WHEN** un visor en zona UTC+1 envía un mensaje a las 23:56 locales y
  ve el chat
- **THEN** la marca de tiempo de su propio mensaje muestra 23:56 (la hora
  de su reloj), no la hora UTC del servidor

#### Scenario: Dos visores en zonas distintas
- **WHEN** dos usuarios en zonas horarias distintas ven el mismo mensaje
- **THEN** cada uno ve la hora equivalente a su zona local para el mismo
  instante absoluto

#### Scenario: Cambio de zona horaria sin recargar el orquestador
- **WHEN** el visor cambia de zona horaria (viaje, cambio de sistema) y
  vuelve a abrir el chat
- **THEN** las marcas de tiempo se muestran en la nueva zona sin cambio
  alguno en el servidor
