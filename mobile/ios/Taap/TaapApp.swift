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
                .tint(.indigo)
        }
    }
}

struct RootView: View {
    @EnvironmentObject var appState: AppState

    var body: some View {
        if appState.isAuthenticated {
            MainTabView()
        } else {
            LoginView()
        }
    }
}
