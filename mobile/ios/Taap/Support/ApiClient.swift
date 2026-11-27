import Foundation

/// Cliente REST del orquestador TAAP (base URL configurable + Bearer token).
final class ApiClient {
    enum ApiError: LocalizedError, Equatable {
        case http(Int, String)
        case network(String)
        case notAuthenticated
        /// 422 de validacion con error en `model_id`: el formato del modelo
        /// no es compatible con el runtime (el servidor aplica la regla en
        /// todo caso; la vista lo mapea a su cadena localizada).
        case incompatibleModel

        var errorDescription: String? {
            switch self {
            case .http(let code, let msg): return "Error \(code): \(msg)"
            case .network(let msg): return msg
            case .notAuthenticated: return "Sesión no válida"
            case .incompatibleModel: return "El modelo no es compatible con este runtime"
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

    /// Compone la URL final. Si la ruta lleva query (p. ej.
    /// "/api/v1/models?runtime=pi"), se separa el query y se conserva en
    /// bruto: `appendingPathComponent` codifica el "?" (%3F) y Laravel
    /// responde 404 «route ... could not be found».
    private func url(for path: String) -> URL {
        guard let qIndex = path.firstIndex(of: "?") else {
            return baseURL.appendingPathComponent(path)
        }
        let pathPart = String(path[..<qIndex])
        let queryPart = String(path[path.index(after: qIndex)...])
        var url = baseURL.appendingPathComponent(pathPart)
        if var components = URLComponents(url: url, resolvingAgainstBaseURL: false) {
            components.percentEncodedQuery = queryPart
            if let resolved = components.url { url = resolved }
        }
        return url
    }

    func request<T: Decodable>(_ path: String, method: String = "GET", body: Encodable? = nil) async throws -> T {
        var req = URLRequest(url: url(for: path))
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
            // Un 422 con error en `model_id` es el 422 de formato
            // modelo↔runtime de la API (design D4): se expone como un
            // error propio para que la UI muestre su cadena localizada
            // en lugar del crudo de Laravel (riesgo del design).
            if http.statusCode == 422,
               let validation = try? JSONDecoder().decode(ApiValidationErrors.self, from: data),
               validation.errors?["model_id"] != nil {
                throw ApiError.incompatibleModel
            }
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
        var req = URLRequest(url: url(for: path))
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
        if let token = res.token {
            self.token = token
        }
        return res
    }

    /// Completa el reto 2FA (stateless, HMAC): devuelve token + user
    /// cuando el código es válido. Sin sesión: el challenge es el token.
    @discardableResult
    func twoFactorChallenge(challenge: String, code: String? = nil, recoveryCode: String? = nil) async throws -> LoginResponse {
        let res: LoginResponse = try await request(
            "api/v1/auth/two-factor/challenge",
            method: "POST",
            body: TwoFactorChallengeRequest(challenge: challenge, code: code, recoveryCode: recoveryCode)
        )
        if let token = res.token {
            self.token = token
        }
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

    // MARK: - Plantillas

    func templates() async throws -> [AgentTemplateDTO] {
        let res: TemplatesEnvelope = try await request("api/v1/templates")
        return res.templates
    }

    // MARK: - Catálogos

    /// Ruta de `GET /models` con el filtro opcional por runtime
    /// (ios-model-runtime-compatibility, design D3). Sin runtime, la query
    /// no se añade: un orquestador viejo no conoce el parametro (y un
    /// cliente viejo no lo envia; retrocompatible).
    static func modelsPath(runtime: String? = nil) -> String {
        guard let runtime else { return "api/v1/models" }
        return "api/v1/models?runtime=\(runtime)"
    }

    func models(runtime: String? = nil) async throws -> [ModelDTO] {
        let res: ModelsEnvelope = try await request(Self.modelsPath(runtime: runtime))
        return res.models
    }

    /// Runtimes del registro del servicio (`GET /v1/runtimes`): la base del
    /// picker de runtime, que NO se hardcodea en la app (spec agent-runtimes
    /// / ios-client "Runtimes desde el servicio"). Un orquestador viejo
    /// devuelve 404: la UI lo muestra como error y deshabilita la creacion
    /// (design D6), sin inventar runtimes.
    func runtimes() async throws -> [RuntimeDTO] {
        let res: RuntimesEnvelope = try await request("api/v1/runtimes")
        return res.runtimes
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

    // MARK: - Archivos

    func files(at path: String = "/") async throws -> FilesEnvelope {
        let encoded = path.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        let res: FilesEnvelope = try await request("api/v1/files?path=\(encoded)")
        return res
    }

    func createFolder(path: String) async throws -> String {
        let res: CreateFolderResponse = try await request(
            "api/v1/files/folders",
            method: "POST",
            body: ["path": AnyEncodable(path)]
        )
        return res.path
    }

    func renameFile(path: String, newName: String) async throws -> String {
        let res: RenameResponse = try await request(
            "api/v1/files/rename",
            method: "POST",
            body: ["path": AnyEncodable(path), "newName": AnyEncodable(newName)]
        )
        return res.path
    }

    /// Sube un fichero (multipart). La URL es absoluta; la petición NO va con
    /// Authorization header (el controlador NO lo exige además del token
    /// base). Nota: el endpoint usa multipart, no JSON.
    func uploadFile(path: String, fileURL: URL) async throws {
        let url = url(for: "api/v1/files/uploads")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        if let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }

        let boundary = "----taap-\(UUID().uuidString)"
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")

        let fileData = try Data(contentsOf: fileURL)

        var body = Data()
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"path\"\r\n\r\n".data(using: .utf8)!)
        body.append("\(path)\r\n".data(using: .utf8)!)
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"file\"; filename=\"\(fileURL.lastPathComponent)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: application/octet-stream\r\n\r\n".data(using: .utf8)!)
        body.append(fileData)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)
        request.httpBody = body

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await Self.session.data(for: request)
        } catch {
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
            throw ApiError.http(http.statusCode, msg)
        }
    }

    func deletePath(path: String) async throws {
        let _: DeletedResponse = try await request(
            "api/v1/files",
            method: "DELETE",
            body: ["path": AnyEncodable(path)]
        )
    }

    /// Descarga un fichero/zip a un archivo temporal y devuelve la URL para
    /// compartirlo (UIActivityViewController).
    func download(path: String) async throws -> (url: URL, filename: String) {
        let name: String
        if path.hasSuffix("/") || path.isEmpty { name = "download" } else { name = (path as NSString).lastPathComponent }
        let url = url(for: "api/v1/files/download?path=\(path.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "")")

        var request = URLRequest(url: url)
        if let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await Self.session.data(for: request)
        } catch {
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
            throw ApiError.http(http.statusCode, "HTTP \(http.statusCode)")
        }

        // El nombre real lo da Content-Disposition (p. ej. "x.zip"); si no,
        // se deriva del path.
        let disposition = http.value(forHTTPHeaderField: "Content-Disposition") ?? ""
        let filename = Self.filenameFromDisposition(disposition) ?? name

        let tmpDir = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
        let tmp = tmpDir.appendingPathComponent("\(UUID().uuidString)-\(filename)")
        try data.write(to: tmp)

        return (tmp, filename)
    }

    private static func filenameFromDisposition(_ disposition: String) -> String? {
        guard let match = disposition.range(of: #"filename="([^"]+)"#, options: .regularExpression) else { return nil }
        let extracted = String(disposition[match])
            .replacingOccurrences(of: "filename=", with: "")
            .trimmingCharacters(in: .whitespaces)
            .trimmingCharacters(in: CharacterSet(charactersIn: "\""))
        return extracted.isEmpty ? nil : extracted
    }
    // MARK: - Chat: exportación

    /// Descarga el historial del chat como .md o .json (archivo temporal).
    func exportChat(agentId: Int, format: String = "md") async throws -> (url: URL, filename: String) {
        let url = url(for: "api/v1/agents/\(agentId)/messages/export?format=\(format)")

        var request = URLRequest(url: url)
        if let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await Self.session.data(for: request)
        } catch {
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
            throw ApiError.http(http.statusCode, "HTTP \(http.statusCode)")
        }

        let disposition = http.value(forHTTPHeaderField: "Content-Disposition") ?? ""
        let filename = Self.filenameFromDisposition(disposition)
            ?? "chat-\(agentId).\(format == "md" ? "md" : "json")"

        let tmpDir = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
        let tmp = tmpDir.appendingPathComponent("\(UUID().uuidString)-\(filename)")
        try data.write(to: tmp)

        return (tmp, filename)
    }


    // MARK: - Logs

    struct LogsResponse: Decodable {
        let status: String
        let lines: [String]
    }

    func logs(agentId: Int, tail: Int = 200) async throws -> LogsResponse {
        try await request("api/v1/agents/\(agentId)/logs?tail=\(tail)")
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

/// Cuerpo de un 422 de validacion de Laravel: permite distinguir el 422
/// por formato modelo↔runtime (error en `model_id`) de otros 422 y
/// mapearlo a la cadena localizada de la app.
struct ApiValidationErrors: Decodable {
    let message: String?
    let errors: [String: [String]]?
}

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
