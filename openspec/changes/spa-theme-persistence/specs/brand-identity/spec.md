# Spec Delta

## MODIFIED Requirements

### Requirement: Modo oscuro
El sistema SHALL ofrecer en todas las plataformas un tema de tres estados (automático, claro y oscuro): por defecto automático siguiendo el sistema, y la elección del usuario se persiste por dispositivo. El modo oscuro SHALL usar superficies oscuras frías (no negro puro) con la misma semántica de colores que el modo claro, y en la web el tema SHALL aplicarse antes del primer pintado para que no haya parpadeo, adaptando todos los componentes mediante el mecanismo nativo del framework.
En la web, el tema vigente (la elección del usuario o la resolución del sistema) SHALL conservarse a través de la navegación client-side entre páginas de la aplicación: la página de destino se pintará con el tema vigente, sin parpadeo al modo opuesto, y el control de tema seguirá siendo usable tras esa navegación.

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

#### Scenario: Navegación interna con el tema vigente
- **WHEN** un usuario en modo oscuro navega a otra página de la aplicación mediante navegación interna (sin recarga completa, p. ej. click en la lista de agentes)
- **THEN** la página de destino se pinta en modo oscuro desde el inicio, sin parpadeo claro

#### Scenario: Control de tema tras la navegación interna
- **WHEN** un usuario navega internamente entre páginas y a continuación opera el control de tema
- **THEN** el control responde y la elección se aplica y se conserva en el dispositivo
