# Spec Delta

## Purpose

Aplicación nativa iOS (SwiftUI) que da a los usuarios acceso desde el móvil a sus agentes: lista con estado en vivo, chat con paridad con la web, y configuración por agente.

## ADDED Requirements

### Requirement: Native iOS application
El sistema SHALL incluir una aplicación nativa iOS (SwiftUI) en la v1.0 como cliente de primera clase del servicio. Una aplicación Android SHALL quedar fuera del alcance de la v1.0.

#### Scenario: Install and connect
- **WHEN** el usuario instala la aplicación e introduce la URL del servicio y sus credenciales
- **THEN** la aplicación se autentica contra las mismas cuentas locales que la web

### Requirement: Service configuration
La aplicación SHALL permitir configurar la URL del servicio en el primer arranque y SHALL mostrar un estado claro cuando el servicio sea inalcanzable.

#### Scenario: Service unreachable
- **WHEN** el servicio no es alcanzable
- **THEN** la aplicación muestra un estado sin conexión con una acción de reintentar

### Requirement: Agent list with live status
La aplicación SHALL listar los agentes del usuario con su estado en vivo y su actividad reciente, actualizados en tiempo real sin refrescado manual.

#### Scenario: Status change
- **WHEN** un agente empieza a trabajar
- **THEN** su entrada en la lista se actualiza a «working» en tiempo real

### Requirement: Chat parity
La aplicación SHALL ofrecer la misma experiencia de chat que la web: envío de mensajes, streaming de la actividad del agente, respuesta a solicitudes de aprobación, consulta del historial y consulta de la línea de tiempo.

#### Scenario: Approval from iPhone
- **WHEN** un agente solicita una aprobación mientras el usuario está en la aplicación
- **THEN** el usuario puede aceptarla o rechazarla y el agente continúa en consecuencia

### Requirement: Agent configuration
La aplicación SHALL permitir configurar por agente el modelo, los servidores MCP, el acceso a Internet y los límites de recursos, con las mismas reglas de validación que la web.

#### Scenario: Change model from phone
- **WHEN** un usuario cambia el modelo de un agente desde la aplicación
- **THEN** el cambio se aplica con el mismo efecto que desde la web

### Requirement: Reconnection and resync
La aplicación SHALL reconectarse automáticamente tras una interrupción de red y SHALL resincronizar los mensajes, estados y aprobaciones ocurridos mientras estaba desconectada.

#### Scenario: Return from airplane mode
- **WHEN** el usuario vuelve a tener red tras estar en modo avión
- **THEN** la aplicación resincroniza y muestra los eventos ocurridos mientras estaba desconectada

### Requirement: iOS localization
La interfaz de la aplicación SHALL estar disponible en castellano, inglés y euskera, siguiendo por defecto el idioma del dispositivo y permitiendo al usuario cambiarlo.

#### Scenario: Basque device
- **WHEN** el usuario abre la aplicación en un dispositivo en euskera
- **THEN** la interfaz se muestra en euskera
