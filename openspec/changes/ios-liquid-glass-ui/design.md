# Design: UI moderno iOS (Liquid Glass)

## Context
Ver proposal.md — Why. Estado actual (`mobile/ios/`): `project.yml` con `deploymentTarget: iOS 17.0`, `SWIFT_VERSION: 5.10`; `Theme.swift` con tres estados de tema; `TaapColor` con paleta de tokens (asset catalog); vistas `List` con `.insetGrouped`, botones planas, sheets y nav bar estándar. El README ya pide Xcode 27+.

## Goals / Non-Goals
**Goals:**
- Rediseño visual con los materiales Liquid Glass de iOS 26 en todas las pantallas.
- Mantener los tokens `TaapColor`, el dark mode a 3 estados, y la localización es/en/eu.

**Non-Goals:**
- Cambio de tokens de marca o de la identidad web; backend/API; animaciones físicas avanzadas; web. Passkeys iOS quedan fuera (ver `enable-2fa-passkeys`).

## Decisions

1. **Deployment target iOS 26.0** (Opción A) — en `project.yml` (`deploymentTarget: iOS: "26.0"`); `regenerate.sh` regenera `Taap.xcodeproj`. El camino único es Liquid Glass; sin fallback condicional ni código `#available(iOS 26, *)`.
2. **Librería de helpers de vidrio** — nueva fuente `Support/LiquidGlass.swift` con modificadores estáticos reutilizables (p. ej. `.liquidGlassSurface()`, `.liquidGlassButton()`, `.liquidGlassCard()`), centralizando los llamados a los APIs de vidrio de iOS 26 en un solo sitio — evita reventar los Materiales por cada vista y permite que toda la app reaccione al mismo cambio de look. Los APIs específicos (p. ej. `.glassEffect()` y variantes) se fijan durante la implementación contra las docs de Xcode 26 (hay que verificarlo — las APIs cambian entre betas; no se anclan nombres aquí).
3. **Navegación y chrome** — navigation bars, tab bar, sheets y backgrounds usan materiales de vidrio; los tokens `TaapColor` se mantienen como las opciones de color para tintes y acentos (los materiales no reemplazan la identidad de marca).
4. **Tarjetas y contenido** — burbujas de chat y tarjetas de lista: fondo de vidrio suavizado sobre tokens de superficie (`surfaceElevated`), no sobre fondos planos; contraste de texto se verifica contra el vidrio (fallback a color sólido `surface` si el contraste cae por debajo de AA 4.5:1 — accesibilidad, no estética).
5. **Botón y controles** — botones prominentes y de texto con la estética de vidrio (filled vs outline); 44pt mínimo de toque; el chrome de confirmaciones (`onDelete`, etc.) se mantiene como está (glass solo para look, no para romper semántica de seguridad).
6. **Dark mode** — iOS 26 materiales de vidrio siguen el appearance del sistema; el modo oscuro lo gestiona el `.colorScheme` existente (`TaapTheme`); los tokens de color ya declaran variante clara/oscura (no cambia el patrón).
7. **Preview y verificación** — SwiftUI previews para las vistas principales (lista, ficha, sheets) con glass; verificación manual en simulador iOS 26 (visionOS no aplica).
8. **Acceso a la visibilidad del stack** — `glassEffect` solo en iOS 26+ (deployment target garantiza), nada de guards condicionales; el `#available` no existe.

## Risks / Trade-offs
- [Liquid Glass API cambia entre betas] → verificar contra Xcode 26 beta/estable en implementación; centralizar en helpers hace el cambio de APIs trivial (unificar en `LiquidGlass.swift`).
- [Vidrio reduce contraste en modo claro] → fallback a color sólido cuando el contraste cae; WCAG AA es la restricción de diseño.
- [Regeneración del proyecto iOS 26] → `regenerate.sh` + verificación de que `Taap.xcodeproj` se regenera limpio; la app debe compilar con Xcode 26+ (toolchain de CI).
- [App silenciosa en iOS 17/18] → deployment target lo bloquea automáticamente (la app no se instala); documentado en release notes.

## Migration Plan
- Bump del deployment target + regeneración: `regenerate.sh` (crea `Taap.xcodeproj` desde `project.yml`).
- Sin migración de datos (solo UI). Rollout: feature flag-less; la UI nueva es el camino único. Rollback: revertir `project.yml` + regenerar.

## Open Questions
Ninguna diferible (las APIs exactas de Liquid Glass se fijan en implementación contra docs de Xcode 26; el diseño centraliza el cambio).
