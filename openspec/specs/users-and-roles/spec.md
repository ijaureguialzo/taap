# users-and-roles Specification

## Purpose

Gestiona la autenticación con cuentas locales, los roles admin y usuario, la gestión de usuarios por el admin, y la propiedad privada de los agentes.

## Requirements

### Requirement: Local authentication
El sistema SHALL autenticar a los usuarios con email y contraseña mediante cuentas locales y sesiones seguras. Los usuarios SHALL poder cambiar su propia contraseña requiriendo la contraseña actual y el admin SHALL poder restablecer la contraseña de cualquier usuario. Al cambiar cualquier contraseña, el sistema SHALL revocar las sesiones web y los tokens API del usuario afectado, conservando la sesión/token con el que se ejecuta el cambio (en el restablecimiento por admin, no hay sesión del usuario que conservar).

#### Scenario: Login
- **WHEN** un usuario presenta credenciales válidas
- **THEN** el sistema establece una sesión para web y emite un token API para la aplicación móvil y accesos programáticos

#### Scenario: Bad password
- **WHEN** un usuario presenta una contraseña incorrecta
- **THEN** el sistema rechaza el acceso con un error genérico

#### Scenario: Change own password
- **WHEN** un usuario presenta la contraseña actual correcta y una contraseña nueva válida
- **THEN** el sistema aplica el cambio, revoca la sesión web y los tokens API del usuario, y deja activa la sesión/token con el que se ejecuta el cambio

#### Scenario: Admin resets password
- **WHEN** el admin restablece la contraseña de otro usuario
- **THEN** el usuario afectado queda con la contraseña nueva y sus sesiones web y tokens API quedan revocados

### Requirement: Perfil de usuario en web
El usuario autenticado SHALL poder editar su perfil en la web: su nombre, su idioma y su contraseña (requiriendo la contraseña actual) desde una página de perfil enlazada desde el navbar. El correo electrónico SHALL ser de solo lectura.

#### Scenario: Edita nombre y idioma
- **WHEN** el usuario guarda el cambio de nombre e idioma en la página de perfil
- **THEN** los datos quedan persistidos y reflejados en la app

#### Scenario: Correo de solo lectura
- **WHEN** el usuario abre la página de perfil
- **THEN** ve su correo, pero no puede modificarlo

### Requirement: Roles
El sistema SHALL definir exactamente dos roles: `admin` y `user`. Los administradores SHALL tener acceso a las áreas completas de gestión (proveedores de modelos, catálogo MCP, usuarios y todos los agentes). Los usuarios SHALL acceder solo a sus recursos y a las áreas generales.

#### Scenario: User sees no admin areas
- **WHEN** un usuario no admin navega la aplicación
- **THEN** las áreas de proveedores de modelos, catálogo MCP y gestión de usuarios no están disponibles para él

### Requirement: User management
El administrador SHALL poder crear, deshabilitar, reactivar, cambiar el rol y borrar usuarios. Deshabilitar un usuario SHALL invalidar de inmediato sus sesiones y detener sus agentes; reactivarlo SHALL reanudarlos.

#### Scenario: Disable user
- **WHEN** el admin deshabilita un usuario
- **THEN** el usuario ya no puede iniciar sesión y los agentes del usuario se detienen, reanudándose cuando se reactive al usuario

### Requirement: Registro público conmutable
El administrador SHALL poder activar o desactivar el registro de usuarios desde una tarjeta de configuración en la página de usuarios del panel. Mientras el registro esté desactivado, la home y la página de login SHALL no mostrar el link de registro (muestran un aviso de cierre) y `/register` SHALL ser rechazado con redirección a home y mensaje claro. El estado SHALL persistir entre reinicios.

#### Scenario: Registro cerrado
- **WHEN** el admin desactiva el registro y un invitado visita la home, la página de login o `/register`
- **THEN** la home y la página de login no muestran el link de registro y `/register` redirige a home con un mensaje de que el registro está cerrado

#### Scenario: Registro abierto
- **WHEN** el admin activa el registro y un invitado visita la home o la página de login
- **THEN** la home y la página de login muestran el link de registro y `/register` funciona normalmente

### Requirement: Borrado de usuario
El administrador SHALL poder borrar a un usuario. Antes del borrado, el sistema SHALL detener todos los agentes del usuario, revocar sus sesiones web y tokens API, y invalidar su acceso. El borrado es irreversible.

#### Scenario: Borrar un usuario activo
- **WHEN** el admin borra a un usuario con agentes en ejecución
- **THEN** los agentes se detienen, sus sesiones y tokens se revocan y el usuario queda eliminado

### Requirement: Guardias anti-auto-bloqueo
El sistema SHALL impedir que un administrador se deshabilite, se degradue a no admin o se borre a sí mismo, con un error claro y sin cambios en la cuenta.

#### Scenario: Baja a sí mismo
- **WHEN** el admin intenta deshabilitarse a sí mismo
- **THEN** el sistema rechaza la acción y la cuenta permanece activa

#### Scenario: Degradación o borrado a sí mismo
- **WHEN** el admin intenta degradarse a no admin o borrar la cuenta propia
- **THEN** el sistema rechaza la acción y la cuenta permanece intacta

### Requirement: Paridad API de gestión de usuarios
El administrador SHALL poder ejecutar la gestión completa de usuarios vía API pública: listar, crear, editar (rol, activo, cupo, idioma), borrar y resetear contraseña, conmutar el registro y bajar/reactivar usuarios — con las mismas reglas de negocio (guardias anti-auto-bloqueo, detención de agentes, revocación de sesiones y tokens) que por la web.

#### Scenario: Admin crea usuario vía API
- **WHEN** el admin presenta datos válidos en la API de usuarios
- **THEN** el sistema crea el usuario con las mismas reglas de validación que por web

#### Scenario: Toggle de registro vía API
- **WHEN** el admin conmuta el registro vía API
- **THEN** el home y `/register` reflejan el nuevo estado igual que tras el toggle por web

#### Scenario: Baja vía API
- **WHEN** el admin deshabilita a un usuario vía API
- **THEN** sus sesiones web y tokens API quedan revocados y sus agentes se detienen, igual que por web

#### Scenario: No-admin vía API
- **WHEN** un usuario no admin llama a cualquier endpoint de gestión de usuarios o de settings
- **THEN** el sistema responde con error 403 y no modifica datos

### Requirement: Private agents
Los agentes SHALL ser privados de su creador. Los usuarios que no sean el propietario ni administradores SHALL no ver, listar ni interactuar con un agente.

#### Scenario: Invisibility between users
- **WHEN** el usuario B lista agentes
- **THEN** los agentes del usuario A no aparecen

### Requirement: Admin oversight of all agents
El administrador SHALL poder listar cualquier agente, ver su chat y su actividad, y detener, arrancar o recrearlo, sin cambiar su propiedad. Borrar el agente de otro usuario SHALL requerir confirmación explícita.

#### Scenario: Admin inspects a user's agent
- **WHEN** el admin abre un agente propiedad de otro usuario
- **THEN** puede ver su estado, chat y configuración, y puede detenerlo o arrancarlo, mientras el agente sigue siendo propiedad del usuario original

### Requirement: Per-user agent quota
El administrador SHALL poder definir un máximo de agentes en ejecución simultáneos por usuario. El sistema SHALL rechazar la creación de un nuevo agente cuando el propietario exceda la cuota.

#### Scenario: Quota reached
- **WHEN** un usuario con la cuota agotada intenta crear otro agente
- **THEN** el sistema rechaza la creación y explica la cuota
