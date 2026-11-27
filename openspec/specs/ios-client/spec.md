# ios-client Specification

## Purpose

Aplicación nativa iOS (SwiftUI) que da a los usuarios acceso desde el móvil a sus agentes: lista con estado en vivo, chat con paridad con la web, y configuración por agente.

## Requirements

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
La aplicación SHALL permitir configurar por agente el modelo, los servidores MCP, el acceso a Internet y los límites de recursos, con las mismas reglas de validación que la web: el selector de modelos ofrecerá únicamente los modelos compatibles con el runtime del agente, marcará los modelos de proveedores no sanos sin bloquearlos, y el guardado se validará con las mismas reglas que la web (el servidor las aplica en todo caso).

#### Scenario: Change model from phone
- **WHEN** un usuario cambia el modelo de un agente desde la aplicación
- **THEN** el cambio se aplica con el mismo efecto que desde la web

#### Scenario: Selector filtrado por el formato del agente
- **WHEN** un usuario abre la configuración de un agente cuyo runtime consume solo un formato y existen proveedores en varios formatos
- **THEN** el selector ofrece únicamente los modelos de los proveedores de ese formato

#### Scenario: Marca de proveedor no sano
- **WHEN** el proveedor de un modelo del selector está marcado como no sano
- **THEN** la aplicación lo muestra atenuado con la etiqueta «no sano», sin bloquear la selección

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

### Requirement: Creación de agentes desde la aplicación
La aplicación SHALL permitir crear agentes ofreciendo en el selector de runtime exactamente los runtimes soportados por el servicio (consultados a la API, no hardcodeados) y filtrando la lista de modelos según el formato del runtime elegido. Al cambiar de runtime, un modelo seleccionado incompatible se descarta con un aviso visible, y se re-restaura automáticamente si el usuario vuelve a un runtime compatible.

#### Scenario: Runtimes desde el servicio
- **WHEN** un usuario abre el formulario de creación de agentes
- **THEN** el selector de runtime ofrece exactamente los runtimes que el servicio soporta (los del registro)

#### Scenario: Modelos filtrados por el runtime elegido
- **WHEN** el usuario elige un runtime de solo un formato en el formulario de creación
- **THEN** el selector de modelos ofrece únicamente los modelos de proveedores de ese formato

#### Scenario: Descarte con aviso al cambiar de runtime
- **WHEN** el usuario cambia a un runtime con el que el modelo seleccionado es incompatible
- **THEN** la aplicación descarta el modelo avisando al usuario, y lo re-restaura si el usuario vuelve a un runtime compatible

### Requirement: File browser
La aplicación SHALL ofrecer un explorador de ficheros (navegable por la raíz del usuario) con el mismo contrato de la API v1 de ficheros: listar, crear carpeta, subir ficheros, descargar ficheros (y `.zip` de carpetas), renombrar y borrar, con confirmación destructiva y errores claros. La interfaz SHALL seguir las mismas reglas de localización (es/en/eu) y los tokens del sistema de diseño.

#### Scenario: Browse and navigate
- **WHEN** el usuario abre «Archivos» y entra en una carpeta
- **THEN** la vista muestra el contenido, los ficheros y carpetas, y el botón de volver

#### Scenario: Upload file
- **WHEN** el usuario sube un fichero desde el explorador
- **THEN** el fichero aparece en la lista y la subida devuelve la respuesta del servidor con los errores claros (413, 403…)

#### Scenario: Download file
- **WHEN** el usuario toca un fichero en el explorador
- **THEN** la descarga del fichero (o del .zip de la carpeta) se inicia y se comparte/guarda como en iOS

#### Scenario: Delete with confirmation
- **WHEN** el usuario borra un fichero o carpeta
- **THEN** la acción requiere confirmación y el sistema muestra el resultado (éxito o error)

### Requirement: Liquid Glass presentation
La aplicación SHALL presentar la interfaz nativa con los materiales de Liquid Glass de iOS 26 (superficies de vidrio, tarjetas, controles, header y tab bar), manteniendo el sistema de tokens `TaapColor`, el modo oscuro a tres estados y la localización (es/en/eu) existentes. La aplicación SHALL requerir iOS 26.

#### Scenario: Liquid surfaces visible
- **WHEN** un usuario abre cualquier pantalla de la app (lista de agentes, ficha, sheets de settings/timeline, login)
- **THEN** las superficies muestran materiales de vidrio con la estética Liquid Glass (profundidad, brillo especular) siguiendo los tokens `TaapColor`

#### Scenario: Dark mode with glass
- **WHEN** el usuario activa el modo oscuro
- **THEN** los materiales de vidrio se ajustan a la apariencia oscura manteniendo el contraste de texto AA

#### Scenario: iOS 26 required
- **WHEN** la app se instala en un dispositivo con iOS 26+
- **THEN** se presenta con la estética Liquid Glass; en dispositivos con iOS 17/18 la app no se instala (deployment target iOS 26)

### Requirement: Glass accessibility
Los materiales de Liquid Glass SHALL no degradar el contraste de texto (WCAG AA) ni la accesibilidad de los controles: tamaño de toque ≥ 44pt, respetar *Reduce Motion* y *Increase Contrast*.

#### Scenario: Text contrast over glass
- **WHEN** un usuario con accesibilidad activada lee el texto sobre una superficie de vidrio
- **THEN** el texto mantiene contraste AA (o la superficie se opaca para cumplirlo)

#### Scenario: Reduce motion
- **WHEN** el usuario tiene *Reduce Motion* activado
- **THEN** la animación de los materiales (transiciones, aparición) se reduce o elimina, manteniendo la usabilidad
