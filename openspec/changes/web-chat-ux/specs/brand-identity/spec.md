# Spec Delta

## MODIFIED Requirements

### Requirement: Tokens de color de la marca
La paleta de la marca (escala aqua como color primario, acento ink, colores de estado y neutros fríos) SHALL definirse como tokens: variables CSS en la web que reescriben las variables del framework para que todos sus componentes las adopten, y color sets en el asset catalog de iOS. Toda la interfaz (web e iOS) SHALL usar exclusivamente esos tokens: ningún elemento tomará un color ad hoc que no exista en la tabla de tokens.
El alcance incluye las utilidades de color literal del framework.

#### Scenario: Un cambio de paleta global
- **WHEN** se modifica un valor de la escala primaria en la tabla de tokens
- **THEN** todos los elementos de la web y de la app cambian de color sin tocar vistas individuales

#### Scenario: Una pantalla nueva
- **WHEN** se desarrolla una nueva pantalla en web o en iOS
- **THEN** usa colores de la tabla de tokens y no hexadecimales sueltos

#### Scenario: Un área con color literal
- **WHEN** un área de la interfaz usa una utilidad de color literal del framework (p. ej. `bg-white`) en vez de un token
- **THEN** esa superficie adopta el color temático correspondiente de la tabla de tokens (en dark, no queda blanca)

### Requirement: Modo oscuro
El sistema SHALL ofrecer en todas las plataformas un tema de tres estados (automático, claro y oscuro): por defecto automático siguiendo el sistema, y la elección del usuario se persiste por dispositivo. El modo oscuro SHALL usar superficies oscuras frías (no negro puro) con la misma semántica de colores que el modo claro, y en la web el tema SHALL aplicarse antes del primer pintado para que no haya parpadeo, adaptando todos los componentes mediante el mecanismo nativo del framework.

#### Scenario: Conmutar en la web
- **WHEN** un usuario elige «oscuro» en su perfil y recarga la página
- **THEN** la página se pinta directamente en modo oscuro, sin parpadeo claro, y conserva la elección

#### Scenario: Conmutar en la app
- **WHEN** un usuario activa el modo oscuro en los ajustes de la app
- **THEN** todas las pantallas, incluido el chat, usan la variante oscura y la elección se conserva entre sesiones

#### Scenario: Seguir el sistema
- **WHEN** el usuario no ha elegido tema (automático) y su sistema pasa a modo oscuro
- **THEN** la web y la app adoptan el modo oscuro sin acción del usuario

#### Scenario: Ventana de chat en modo oscuro
- **WHEN** un usuario en modo oscuro abre el chat de un agente
- **THEN** el área de mensajes y las burbujas usan las superficies del tema oscuro (no un fondo blanco) y el texto mantiene su contraste AA
