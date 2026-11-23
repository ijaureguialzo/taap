# Spec Delta

## ADDED Requirements

### Requirement: Configuración del endpoint de streaming
El endpoint del websocket por el que los clientes se conectan al servidor de eventos (host, esquema TLS y puerto) SHALL ser configurable por despliegue mediante variables de entorno del orquestador, sin cambios de código. El orquestador SHALL comunicar ese endpoint al cliente web y al cliente móvil, y SHALL mantener los defectos de desarrollo local (host local, sin TLS, puerto del servidor de eventos) cuando no se configure nada.

#### Scenario: Despliegue con endpoint público configurado
- **GIVEN** el despliegue define en el entorno del orquestador el host público `ws.taap.test`, esquema `https` y puerto `443`
- **WHEN** un usuario autenticado abre el cliente web o la aplicación móvil
- **THEN** ambos reciben ese endpoint y se conectan a `wss://ws.taap.test:443` y no a la dirección interna del servidor de eventos

#### Scenario: Cambio a otro servidor
- **GIVEN** un despliegue reconfigurado por variables de entorno al host público `ws.taap.itlent.eus` (esquema `https`, puerto `443`)
- **WHEN** los clientes obtienen la configuración del orquestador
- **THEN** se conectan al nuevo endpoint sin ningún cambio de código en el orquestador ni en los clientes

#### Scenario: Desarrollo local sin proxy inverso
- **GIVEN** un arranque local del orquestador sin variables de endpoint público definidas
- **WHEN** un cliente local obtiene la configuración del orquestador
- **THEN** usa los defectos de desarrollo (host `127.0.0.1`, esquema sin TLS y puerto del servidor de eventos) y el streaming local sigue funcionando

### Requirement: Endpoint público de eventos con TLS
En el despliegue con proxy inverso, el servidor de eventos SHALL ser alcanzable públicamente a través de un host dedicado, distinto del del orquestador, sobre TLS en el puerto 443, usando el certificado del despliegue. Los clientes conectados al frontend HTTPS del orquestador SHALL poder establecer la conexión del websocket y recibir los eventos del agente sin bloqueo por contenido mixto.

#### Scenario: Streaming de eventos a través del host público
- **GIVEN** el despliegue por defecto con un certificado válido que cubre el host del websocket (`ws.taap.test`, vía comodín `*.taap.test`)
- **WHEN** un usuario abre el chat de un agente desde el cliente web o la aplicación móvil servidos por HTTPS
- **THEN** el cliente establece una conexión de websocket con TLS en el puerto 443 y recibe en tiempo real los eventos del agente (cambios de estado, actividad)

#### Scenario: Certificado para el nuevo host
- **GIVEN** un despliegue reconfigurado a un dominio nuevo cuyo certificado actual no cubre el nuevo host del websocket
- **WHEN** se regenera el certificado del despliegue incluyendo el nuevo host del websocket
- **THEN** los clientes logran establecer la conexión TLS y el streaming funciona en el dominio nuevo
