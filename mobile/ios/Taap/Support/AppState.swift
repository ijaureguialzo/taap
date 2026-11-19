import Foundation
import SwiftUI

/// Estado global de la app: sesión, URL del servicio, agentes, idioma.
@MainActor
final class AppState: ObservableObject {
    @Published var isAuthenticated: Bool
    @Published var currentUser: UserDTO?
    @Published var baseURLString: String {
        didSet {
            UserDefaults.standard.set(baseURLString, forKey: "base_url")
            api.baseURL = URL(string: baseURLString) ?? api.baseURL
        }
    }
    @Published var locale: String {
        didSet { UserDefaults.standard.set(locale, forKey: "locale") }
    }
    @Published var agents: [AgentDTO] = []
    @Published var agentsLoading = false
    @Published var selectedAgentId: Int?
    @Published var banner: Banner?
    @Published var serverReachable: Bool?

    struct Banner: Identifiable, Equatable {
        let id = UUID()
        let text: String
        let kind: Kind
        enum Kind { case info, error, success }
    }

    let api: ApiClient
    let channelManager: AgentChannelManager

    init() {
        let savedURL = UserDefaults.standard.string(forKey: "base_url") ?? "http://127.0.0.1:8011"
        api = ApiClient(baseURL: URL(string: savedURL)!)
        channelManager = AgentChannelManager(api: api)
        self.baseURLString = savedURL

        // Comodidad de desarrollo y tests: token por argumento de arranque,
        // UserDefaults o keychain. `--taap-clean` fuerza arrancar sin
        // sesion (vacia keychain y UserDefaults).
        let args = ProcessInfo.processInfo.arguments
        let clean = args.contains("--taap-clean")
        if clean {
            TokenStore.shared.token = nil
            UserDefaults.standard.removeObject(forKey: "dev_token")
            UserDefaults.standard.removeObject(forKey: "locale")
        }

        // Idioma: preferencia del usuario (por dispositivo) > idioma del
        // dispositivo si esta soportado (es/en/eu) > castellano (spec
        // localization: Per-user language preference). Se resuelve DESPUES
        // de `--taap-clean` para que la limpieza tenga efecto.
        let stored = UserDefaults.standard.string(forKey: "locale")
        if let stored, LocaleStrings.isSupported(stored) {
            self.locale = stored
        } else {
            self.locale = LocaleStrings.deviceDefault()
        }
        if let i = args.firstIndex(of: "--taap-token"), i + 1 < args.count,
           !args[i + 1].isEmpty {
            // El argumento explicito manda, aunque se pida --taap-clean
            // (clean vacio el storage; el token nuevo lo rellena).
            api.token = args[i + 1]
            TokenStore.shared.token = args[i + 1]
        } else if !clean, let udToken = UserDefaults.standard.string(forKey: "dev_token"), !udToken.isEmpty {
            api.token = udToken
            TokenStore.shared.token = udToken
        } else if api.token == nil {
            api.token = TokenStore.shared.token
        }
        if let i = args.firstIndex(of: "--taap-open-agent"), i + 1 < args.count,
           let id = Int(args[i + 1]) {
            selectedAgentId = id
        }
        if let i = args.firstIndex(of: "--taap-locale"), i + 1 < args.count,
           LocaleStrings.isSupported(args[i + 1]) {
            // Argumento explicito: manda incluso con --taap-clean (tests).
            locale = args[i + 1]
        }

        self.isAuthenticated = api.token != nil
        if api.token != nil {
            Task { await loadReverbConfig() }
        }

        // Estado en vivo de la lista: `status.change` por canal `agent.{id}`
        // actualiza la fila sin refrescar.
        channelManager.onAgentStatus = { [weak self] id, status in
            guard let self, let i = self.agents.firstIndex(where: { $0.id == id }) else { return }
            self.agents[i].status = status
        }
    }

    func flash(_ text: String, kind: Banner.Kind = .info) {
        banner = Banner(text: text, kind: kind)
        Task {
            try? await Task.sleep(nanoseconds: 4_000_000_000)
            if banner?.text == text { banner = nil }
        }
    }

    func t(_ key: String, vars: [String: String] = [:]) -> String {
        LocaleStrings.t(locale, key, vars: vars)
    }

    // MARK: - Sesión

    func login(email: String, password: String) async -> Bool {
        do {
            let res = try await api.login(email: email, password: password)
            TokenStore.shared.token = res.token
            currentUser = res.user
            isAuthenticated = true
            await refreshAgents()
            await loadReverbConfig()
            return true
        } catch {
            flash(error.localizedDescription, kind: .error)
            return false
        }
    }

    func loadReverbConfig() async {
        guard let cfg = try? await api.reverbConfig() else { return }
        channelManager.configureWith(config: cfg)
    }

    func logout() {
        api.logout()
        TokenStore.shared.token = nil
        isAuthenticated = false
        currentUser = nil
        agents = []
    }

    // MARK: - Agentes

    func refreshAgents() async {
        agentsLoading = true
        defer { agentsLoading = false }
        do {
            agents = try await api.agents()
        } catch {
            banner = Banner(text: error.localizedDescription, kind: .error)
        }
        // Estado en vivo: trazar los canales de la lista.
        channelManager.track(agents.map(\.id))
    }

    func refreshAgent(id: Int) async {
        do {
            let updated = try await api.agentStatus(id: id)
            if let idx = agents.firstIndex(where: { $0.id == id }) {
                agents[idx] = updated
            }
        } catch {
            // La vida la da el canal Reverb; el poll es red de seguridad.
        }
    }
}
