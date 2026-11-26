# Proposal: UI moderno de la app iOS (Liquid Glass)

## Why
La app iOS es funcional pero está en `deploymentTarget: iOS 17.0` con estética de pre-iOS 26: `List` con `.insetGrouped`, botones estándar, tarjetas planas y chrome de navegación clásico. El sistema iOS 26 introduce **Liquid Glass**, el material translúcido con refracción y brillo especular del sistema operativo (superficies, botones, controles de navegación) — la estética que define la plataforma actual. Mantener la app en la estética «clásica» la deja atrás de la competencia de plataforma, sin que aporte nada por ello: los tokens de marca (`TaapColor`, dark mode, i18n es/en/eu) ya están alineados con la web y no cambian.

## What Changes
- Rediseño visual de la app iOS en torno a los materiales de Liquid Glass de iOS 26: superficies y tarjetas de vidrio, elementos de navegación (header/navigation bars/tab bar), controles y fondos con profundidad y brillo especular, manteniendo el sistema de tokens `TaapColor` y el modo oscuro a tres estados.
- Etapas de entrega por surface: lista de agentes, ficha de agente (chat, approvals, timeline), sheets (crear agente, settings, timeline) y login.
- Accesibilidad WCAG: contraste de texto sobre vidrio, respeto de *Reduce Motion* y *Increase Contrast*, tamaño de toque ≥ 44pt.
- **Decisión (Opción A): deployment target iOS 26** — la app sube de iOS 17.0 a iOS 26.0 en `project.yml`, sin fallback condicional; la estética nativa de Liquid Glass es el camino único. Deja fuera dispositivos iOS 17/18 (iPhone 11+ en iOS 26 aprox.); la app es privada/local, así que el salto es aceptable.

**Out of scope:** cambio de los tokens de marca (aqua/ink) ni de la identidad web — el glass se apoya sobre los mismos tokens; backend/API (nada cambia del lado del orquestador); animaciones físicas/3D más allá de los efectos nativos de SwiftUI; web (el look de la web se mantiene); passkeys/WebAuthn (ver `enable-2fa-passkeys`).

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
- `ios-client`: la presentación nativa pasa a Liquid Glass (superficies, navegación, controles) manteniendo las capacidades declaradas (lista con estado en vivo, chat con paridad, configuración, i18n, dark mode).

## Impact
- `mobile/ios/Taap/Views/*.swift` (todas las vistas) + `Support/TaapColor.swift`/`Theme.swift` (materiales), `project.yml` (deployment target: iOS 17.0 → 26.0, con regeneración de `Taap.xcodeproj` vía `regenerate.sh`).
- iOS tests (`Tests/Unit/*`) y SwiftUI preview tokens para los nuevos materiales.
- Requiere Xcode 26 para compilar/verificar Liquid Glass (memoria: el repo usa `regenerate.sh` para regenerar Xcode proyectos desde `project.yml`).
