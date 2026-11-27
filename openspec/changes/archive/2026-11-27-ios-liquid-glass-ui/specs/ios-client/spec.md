# Spec Delta

## ADDED Requirements

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
