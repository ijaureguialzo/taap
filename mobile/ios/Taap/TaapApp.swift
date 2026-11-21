import SwiftUI

@main
struct TaapApp: App {
    @StateObject private var appState = AppState()

    init() {
        let args = ProcessInfo.processInfo.arguments
        // Util en tests UI: vaciar el keychain para arrancar en login.
        if args.contains("--taap-clean") {
            TokenStore.shared.token = nil
        }
        if let i = args.firstIndex(of: "--taap-token"), i + 1 < args.count, !args[i + 1].isEmpty {
            TokenStore.shared.token = args[i + 1]
        }
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(appState)
                .environment(\.locale, Locale(identifier: appState.locale))
                .tint(TaapColor.primary)
        }
    }
}

struct RootView: View {
    @EnvironmentObject var appState: AppState
    // brand-identity D5: tema de 3 estados (mismo contrato que la web).
    // auto -> nil: el sistema decide; light/dark fuerzan.
    @AppStorage("taap-theme") private var theme = "auto"

    var resolved: TaapTheme { TaapTheme(resolving: theme) }

    var body: some View {
        Group {
            if appState.isAuthenticated {
                MainTabView()
            } else {
                LoginView()
            }
        }
        .preferredColorScheme(resolved.colorScheme)
    }
}
