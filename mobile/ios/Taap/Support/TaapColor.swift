import SwiftUI

/// Paleta TAAP (brand-identity D3/D5): accessors a los color sets del
/// asset catalog (cada uno declara Any + Dark; el sistema aplica la
/// variante según el appearance). Mismos valores que la tabla de tokens
/// de la web (`orchestrator/resources/css/app.css`):
///
///   primary        aqua-500 #299A94 / aqua-400 #4AB4AE
///   primaryText    slate-950 (AA sobre aqua en ambos temas)
///   ink            #125E7A / #67C3E0 (acento informativo)
///   background     slate-50 / slate-950 (pagina)
///   surface        blanco / slate-900 (tarjetas)
///   surfaceElevated slate-100 / slate-800 (chips, burbujas)
///   textPrimary    slate-900 / slate-100
///   textSecondary  slate-600 / slate-400
///   success        #157A42 / #34C97B
///   warning        #B45309 / #E8A33D
///   danger         #C2333B / #F06A6A
enum TaapColor {
    static let primary = Color("TaapPrimary")
    static let primaryText = Color("TaapPrimaryText")
    static let ink = Color("TaapInk")
    static let background = Color("TaapBackground")
    static let surface = Color("TaapSurface")
    static let surfaceElevated = Color("TaapSurfaceElevated")
    static let textPrimary = Color("TaapTextPrimary")
    static let textSecondary = Color("TaapTextSecondary")
    static let success = Color("TaapSuccess")
    static let warning = Color("TaapWarning")
    static let danger = Color("TaapDanger")

    /// Mapa semantico estado -> color, identico al de la web (badge.status-*):
    /// neutro para parado/reposo/desconectado, acento para arrancando,
    /// verde para trabajando, ambar para aprobacion, rojo para error.
    static func status(_ value: String) -> Color {
        switch value {
        case "working": return success
        case "waiting-approval": return warning
        case "starting": return ink
        case "idle", "stopped", "offline": return textSecondary
        default: return textSecondary
        }
    }
}
