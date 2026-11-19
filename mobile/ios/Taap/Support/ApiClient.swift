import Foundation

/// Cliente REST del orquestador TAAP (base URL configurable + Bearer token).
final class ApiClient {
    enum ApiError: LocalizedError {
        case http(Int, String)
        case network(String)
        case notAuthenticated

        var errorDescription: String? {
            switch self {
            case .http(let code, let msg): return "Error \(code): \(msg)"
            case .network(let msg): return msg
            case .notAuthenticated: return "Sesión no válida"
            }
        }
    }

    var baseURL: URL
    var token: String? {
        didSet { try? KeyVault.save(token: token) }
    }

    init(baseURL: URL = URL(string: "http://127.0.0.1:8011")!) {
        self.baseURL = baseURL
        self.token = try? KeyVault.loadToken()
    }

    static let session: URLSession = {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 30
        config.timeoutIntervalForResource = 600
        return URLSession(configuration: config)
    }()

    // MARK: - Peticiones

    func request<T: Decodable>(_ path: String, method: String = "GET", body: Encodable? = nil) async throws -> T {
        var req = URLRequest(url: baseURL.appendingPathComponent(path))
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try JSONEncoder().encode(AnyEncodable(body))
        }

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await Self.session.data(for: req)
        } catch {
            NSLog("[TAAP-API] fallo red \(method) \(req.url?.absoluteString ?? ""): \(error)")
            #if DEBUG
            try? "\(method) \(req.url?.absoluteString ?? "") => \(error)\n".write(
                toFile: FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0].appendingPathComponent("taap-net.log").path,
                atomically: true,
                encoding: .utf8
            )
            #endif
            throw ApiError.network("Sin conexión con el servidor")
        }

        guard let http = response as? HTTPURLResponse else {
            throw ApiError.network("Respuesta inválida")
        }

        if http.statusCode == 401 {
            token = nil
            throw ApiError.notAuthenticated
        }
        if http.statusCode >= 400 {
            let msg = (try? JSONDecoder().decode([String: String].self, from: data))?["message"]
                ?? (try? JSONDecoder().decode(ApiMessage.self, from: data))?.message
                ?? "error HTTP"
            #if DEBUG
            let errText = "\(method) \(req.url?.absoluteString ?? "") => HTTP \(http.statusCode): \(String(data: data, encoding: .utf8) ?? "")\n"
            try? errText.write(
                toFile: FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0].appendingPathComponent("taap-net.log").path,
                atomically: true,
                encoding: .utf8
            )
            #endif
            throw ApiError.http(http.statusCode, msg)
        }

        return try JSONDecoder().decode(T.self, from: data)
    }

    /// Peticion sin cuerpo de respuesta (DELETE etc.)
    func requestRaw(_ path: String, method: String = "GET", body: Encodable? = nil) async throws {
        var req = URLRequest(url: baseURL.appendingPathComponent(path))
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try JSONEncoder().encode(AnyEncodable(body))
        }
        let (data, response) = try await Self.session.data(for: req)
        guard let http = response as? HTTPURLResponse else { return }
        if http.statusCode == 401 {
            token = nil
            throw ApiError.notAuthenticated
        }
        if http.statusCode >= 400 {
            let msg = (try? JSONDecoder().decode([String: String].self, from: data))?["message"]
                ?? (try? JSONDecoder().decode(ApiMessage.self, from: data))?.message
                ?? "error HTTP"
            throw ApiError.http(http.statusCode, msg)
        }
    }

    // MARK: - Auth

    @discardableResult
    func login(email: String, password: String) async throws -> LoginResponse {
        let res: LoginResponse = try await request("api/v1/auth/login", method: "POST", body: LoginRequest(email: email, password: password))
        token = res.token
        return res
    }

    func logout() {
        token = nil
    }

    // MARK: - Agentes

    func agents() async throws -> [AgentDTO] {
        let res: AgentsEnvelope = try await request("api/v1/agents")
        return res.agents
    }

    func agent(id: Int) async throws -> AgentDTO {
        let res: AgentEnvelope = try await request("api/v1/agents/\(id)")
        return res.agent
    }

    func agentStatus(id: Int) async throws -> AgentDTO {
        let res: AgentEnvelope = try await request("api/v1/agents/\(id)/status")
        return res.agent
    }

    func createAgent(name: String, runtime: String, modelId: Int, internet: Bool = false, autoRestart: Bool = true, systemPrompt: String? = nil) async throws -> AgentDTO {
        var body: [String: AnyEncodable] = [
            "name": .init(name),
            "runtime": .init(runtime),
            "model_id": .init(modelId),
            "internet_enabled": .init(internet),
            "auto_restart": .init(autoRestart),
        ]
        if let systemPrompt, !systemPrompt.isEmpty {
            body["system_prompt"] = .init(systemPrompt)
        }
        let res: AgentEnvelope = try await request("api/v1/agents", method: "POST", body: body)
        return res.agent
    }

    func updateAgent(id: Int, _ update: AgentUpdate) async throws -> AgentDTO {
        // La ruta es PUT (no PATCH): con PATCH daba 405 y la guardada
        // parecia fallar sin error.
        let res: AgentEnvelope = try await request("api/v1/agents/\(id)", method: "PUT", body: update)
        return res.agent
    }

    func startAgent(id: Int) async throws -> AgentDTO {
        let res: AgentEnvelope = try await request("api/v1/agents/\(id)/start", method: "POST")
        return res.agent
    }

    func stopAgent(id: Int) async throws -> AgentDTO {
        let res: AgentEnvelope = try await request("api/v1/agents/\(id)/stop", method: "POST")
        return res.agent
    }

    func deleteAgent(id: Int) async throws {
        try await requestRaw("api/v1/agents/\(id)", method: "DELETE")
    }

    // MARK: - Chat

    func history(agentId: Int) async throws -> [ChatMessageDTO] {
        let res: MessagesEnvelope = try await request("api/v1/agents/\(agentId)/messages")
        return res.messages
    }

    @discardableResult
    func send(agentId: Int, content: String) async throws -> ChatMessageDTO {
        let res: MessageEnvelope = try await request("api/v1/agents/\(agentId)/messages", method: "POST", body: MessageRequest(content: content))
        return res.message
    }

    func resolveApproval(agentId: Int, approvalId: String, approved: Bool, note: String? = nil) async throws {
        var body: [String: AnyEncodable] = [
            "approval_id": .init(approvalId),
            "approved": .init(approved),
        ]
        if let note { body["note"] = .init(note) }
        try await requestRaw("api/v1/agents/\(agentId)/approvals/resolve", method: "POST", body: body)
    }

    // MARK: - Catálogos

    func models() async throws -> [ModelDTO] {
        let res: ModelsEnvelope = try await request("api/v1/models")
        return res.models
    }

    /// Catálogo de servidores MCP (para la seccion MCP de la config).
    func mcpServers() async throws -> [McpSummary] {
        struct Envelope: Decodable { let servers: [McpSummary] }
        let res: Envelope = try await request("api/v1/mcp")
        return res.servers
    }

    func providers() async throws -> [ProviderDTO] {
        let res: ProvidersEnvelope = try await request("api/v1/providers")
        return res.providers
    }

    // MARK: - Reverb config

    /// Config del canal WSS (la web la recibe en data-reverb; el móvil la
    /// pide en la ruta equivalente).
    func reverbConfig() async throws -> ReverbConfig {
        let res: ReverbEnvelope = try await request("api/v1/reverb-config")
        return res.config
    }

    func timeline(agentId: Int, hours: Int = 24) async throws -> TimelineResponse {
        try await request("api/v1/agents/\(agentId)/timeline?hours=\(hours)")
    }
}

// Envolventes de los endpoints de lista.
struct AgentsEnvelope: Decodable { let agents: [AgentDTO] }
struct AgentEnvelope: Decodable { let agent: AgentDTO }
struct MessagesEnvelope: Decodable { let messages: [ChatMessageDTO] }
struct MessageEnvelope: Decodable { let message: ChatMessageDTO }
struct ModelsEnvelope: Decodable { let models: [ModelDTO] }
struct ProvidersEnvelope: Decodable { let providers: [ProviderDTO] }
struct ApiMessage: Decodable { let message: String }

struct ReverbConfig: Decodable {
    let key: String
    let secret: String?
    let appId: String
    let host: String
    let port: Int
    let scheme: String?

    enum CodingKeys: String, CodingKey {
        case key, secret, host, port, scheme
        case appId = "appId"
    }

    var wsURL: URL? {
        // El protocolo Pusher usa la CLAVE de la app en la ruta (no el id).
        // CFNetwork exige esquema ws/wss (https/http no sirven para
        // URLSessionWebSocketTask); la clave base64 va codificada.
        let wsScheme = (scheme == "http") ? "ws" : "wss"
        let encodedKey = key.replacingOccurrences(of: "+", with: "%2B")
            .replacingOccurrences(of: "=", with: "%3D")
        if ProcessInfo.processInfo.arguments.contains("--taap-ws-simple") {
            return URL(string: "\(wsScheme)://\(host):\(port)/")
        }
        return URL(string: "\(wsScheme)://\(host):\(port)/app/\(encodedKey)")
    }
}

struct ReverbEnvelope: Decodable { let config: ReverbConfig }

/// Wrapper que permite pasar cualquier `Encodable` (structs y
/// diccionarios) como body de request.
///
/// Nota: usa un closure que re-delega en `encode(to:)` del tipo original.
/// La version anterior pasaba los valores por `ERN` (reflection minima),
/// que convertia un struct en un string de debug y rompia el JSON
/// (login 422 "email/password required").
struct AnyEncodable: Encodable {
    private let encodeClosure: (Encoder) throws -> Void

    init<T: Encodable>(_ value: T) {
        encodeClosure = { try value.encode(to: $0) }
    }

    func encode(to encoder: Encoder) throws {
        try encodeClosure(encoder)
    }
}


