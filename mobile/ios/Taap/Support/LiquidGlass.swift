import SwiftUI

/// Helpers de Liquid Glass (iOS 26): un único sitio para aplicar los
/// materiales de vidrio para que toda la app reaccione al mismo cambio de
/// look (design ios-liquid-glass-ui).
///
/// Los tokens de marca (TaapColor, dark mode, i18n es/en/eu) NO cambian:
/// el glass es la presentación. El contraste de texto se verifica sobre
/// el vidrio y, cuando cae por debajo de AA 4.5:1, la superficie cae al
/// tinte sólido (ver `.liquidGlassFallback`).
enum LiquidGlass {
    /// Vidrio regular tinteado con el color de acento de la marca.
    static func primary(_ color: Color = TaapColor.primary, interactive: Bool = false) -> SwiftUICore.Glass {
        .regular.tint(color).interactive(interactive)
    }

    /// Alias de `primary` (compatibilidad con la firma anterior).
    static func tinted(_ color: Color, interactive: Bool = false) -> SwiftUICore.Glass {
        primary(color, interactive: interactive)
    }

    /// Vidrio claro para acentos sobre fondos claros.
    static var clear: SwiftUICore.Glass { .clear }
}

/// Superficie de vidrio genérica (tarjeta/panel).
struct LiquidGlassCard: ViewModifier {
    var shape: RoundedRectangle
    var glass: SwiftUICore.Glass

    init(color: Color = TaapColor.primary, interactive: Bool = false, radius: CGFloat = 18) {
        self.shape = RoundedRectangle(cornerRadius: radius, style: .continuous)
        self.glass = .regular.tint(color).interactive(interactive)
    }

    func body(content: Content) -> some View {
        content.glassEffect(glass, in: shape)
    }
}

/// Helper de aplicabilidad rápida: tarjeta de vidrio con el tinte primario.
extension View {
    func liquidGlassCard(color: Color = TaapColor.primary, interactive: Bool = false, radius: CGFloat = 18) -> some View {
        self.modifier(LiquidGlassCard(color: color, interactive: interactive, radius: radius))
    }
}
