import Foundation

/// Registra el nombre y motivo de la primera excepcion ObjC sin atrapar
/// (el proceso se aborta igual; solo deja pista en la consola).
enum CrashReporter {
    static func install() {
        NSSetUncaughtExceptionHandler { exception in
            NSLog("TAAP-EXC name=[%@] reason=[%@]", exception.name.rawValue, exception.reason ?? "?")
        }
    }
}
