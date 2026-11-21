import SwiftUI

/// Tema de la app (brand-identity D5): contrato de 3 estados, idéntico
/// al de la web (`taap-theme` en localStorage / `data-bs-theme`).
/// Resolución con el mismo orden: valor guardado -> auto (el sistema
/// decide); un valor desconocido o ausente resuelve a `auto`.
enum TaapTheme: String, CaseIterable, Sendable {
    case auto
    case light
    case dark

    init(resolving stored: String?) {
        self = TaapTheme(rawValue: stored ?? "") ?? .auto
    }

    /// nil = auto: SwiftUI sigue el appearance del sistema.
    var colorScheme: ColorScheme? {
        switch self {
        case .dark: return .dark
        case .light: return .light
        case .auto: return nil
        }
    }
}
