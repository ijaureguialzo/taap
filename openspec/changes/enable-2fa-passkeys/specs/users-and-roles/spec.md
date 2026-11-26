# Spec Delta

## MODIFIED Requirements

### Requirement: Local authentication
El sistema SHALL autenticar a los usuarios con email y contraseña mediante cuentas locales y sesiones seguras. Los usuarios que tengan 2FA habilitado SHALL requerir un reto de segundo factor (código TOTP o passkey) antes de que el sistema establezca la sesión web o API; un usuario con 2FA habilitado SHALL poder iniciar sesión también con un passkey registrado, sin el reto TOTP. Los usuarios SHALL poder cambiar su propia contraseña requiriendo la contraseña actual y el admin SHALL poder restablecer la contraseña de cualquier usuario. Al cambiar cualquier contraseña, el sistema SHALL revocar las sesiones web y los tokens API del usuario afectado, conservando la sesión/token con el que se ejecuta el cambio (en el restablecimiento por admin, no hay sesión del usuario que conservar).

#### Scenario: Login
- **WHEN** un usuario presenta credenciales válidas
- **THEN** el sistema establece una sesión para web y emite un token API para la aplicación móvil y accesos programáticos

#### Scenario: Login with TOTP enabled
- **WHEN** un usuario con 2FA TOTP habilitado presenta credenciales válidas y un código TOTP válido
- **THEN** el sistema establece la sesión web y emite un token API

#### Scenario: Login with TOTP challenge required
- **WHEN** un usuario con 2FA TOTP habilitado presenta credenciales válidas sin código TOTP (o con código incorrecto)
- **THEN** el sistema rechaza el acceso con un error de segundo factor y no establece sesión

#### Scenario: Login with passkey
- **WHEN** un usuario con passkeys registrados inicia sesión presentando uno de ellos
- **THEN** el sistema establece la sesión web sin reto TOTP

#### Scenario: Bad password
- **WHEN** un usuario presenta una contraseña incorrecta
- **THEN** el sistema rechaza el acceso con un error genérico

#### Scenario: Change own password
- **WHEN** un usuario presenta la contraseña actual correcta y una contraseña nueva válida
- **THEN** el sistema aplica el cambio, revoca la sesión web y los tokens API del usuario, y deja activa la sesión/token con el que se ejecuta el cambio

#### Scenario: Admin resets password
- **WHEN** el admin restablece la contraseña de otro usuario
- **THEN** el usuario afectado queda con la contraseña nueva y sus sesiones web y tokens API quedan revocados

## ADDED Requirements

### Requirement: 2FA factor management
El usuario autenticado SHALL poder habilitar y deshabilitar TOTP y gestionar sus passkeys (registrar y eliminar) desde su perfil; al habilitar TOTP, los recovery codes SHALL mostrarse una sola vez.

#### Scenario: Enable TOTP
- **WHEN** un usuario activa 2FA TOTP en su perfil y confirma el código
- **THEN** el sistema guarda el factor, muestra los recovery codes una sola vez y el próximo login requiere el reto TOTP

#### Scenario: Disable TOTP
- **WHEN** un usuario deshabilita 2FA desde su perfil tras confirmar su contraseña
- **THEN** el próximo login de ese usuario no requiere el reto TOTP

#### Scenario: Register and delete passkey
- **WHEN** un usuario registra un passkey y más tarde lo elimina desde su perfil
- **THEN** el passkey registrado queda listado y, tras eliminarlo, deja de servir para iniciar sesión

#### Scenario: Recovery codes single-use
- **WHEN** un usuario con 2FA habilita TOTP y pierde su teléfono y usa un recovery code válido
- **THEN** el sistema acepta el código para establecer la sesión y el código se invalida para usos futuros
