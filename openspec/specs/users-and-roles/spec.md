# users-and-roles Specification

## Purpose

Gestiona la autenticación con cuentas locales, los roles admin y usuario, la gestión de usuarios por el admin, y la propiedad privada de los agentes.

## Requirements

### Requirement: Local authentication
El sistema SHALL autenticar a los usuarios con email y contraseña mediante cuentas locales y sesiones seguras. Los usuarios SHALL poder cambiar su propia contraseña y el admin SHALL poder restablecer la contraseña de cualquier usuario.

#### Scenario: Login
- **WHEN** un usuario presenta credenciales válidas
- **THEN** el sistema establece una sesión para web y emite un token API para la aplicación móvil y accesos programáticos

#### Scenario: Bad password
- **WHEN** un usuario presenta una contraseña incorrecta
- **THEN** el sistema rechaza el acceso con un error genérico

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
