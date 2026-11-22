# Spec Delta

## ADDED Requirements

### Requirement: Modo oscuro: persistencia en la navegación interna
En la web, el tema vigente (la elección del usuario o la resolución del sistema) SHALL conservarse a través de la navegación client-side entre páginas de la aplicación: la página de destino se pintará con el tema vigente desde el inicio, sin parpadeo al modo opuesto, y el control de tema seguirá siendo usable tras esa navegación.

#### Scenario: Navegación interna con el tema oscuro
- **WHEN** un usuario en modo oscuro navega a otra página de la aplicación mediante navegación interna (sin recarga completa, p. ej. click en la lista de agentes)
- **THEN** la página de destino se pinta en modo oscuro desde el inicio, sin parpadeo claro

#### Scenario: Navegación interna con el tema claro
- **WHEN** un usuario que eligió «claro» navega internamente a otra página
- **THEN** la página de destino se pinta en modo claro, conservando la elección

#### Scenario: Control de tema tras la navegación interna
- **WHEN** un usuario navega internamente entre páginas y a continuación opera el control de tema
- **THEN** el control responde, la elección se aplica y se conserva en el dispositivo
