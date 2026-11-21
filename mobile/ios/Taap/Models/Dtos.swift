import Foundation

// MARK: - Auth

struct LoginRequest: Encodable {
    let email: String
    let password: String
}

struct LoginResponse: Decodable {
    let token: String
    let user: UserDTO
}

// MARK: - Usuarios

struct UserDTO: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let email: String
    let role: String?
    let active: Bool?
    let agentQuota: Int?
    let runningAgents: Int?

    var isAdmin: Bool { role == "admin" }

    enum CodingKeys: String, CodingKey {
        case id, name, email, role, active
        case agentQuota = "agent_quota"
        case runningAgents = "running_agents"
    }
}

// MARK: - Agentes

struct AgentDTO: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let description: String?
    let runtime: String
    var status: String
    let model: ModelSummary?
    let internetEnabled: Bool
    let autoRestart: Bool
    let systemPrompt: String?
    let workingDir: String?
    let owner: OwnerDTO?
    let lastActiveAt: String?
    let mcpServers: [McpSummary]?

    enum CodingKeys: String, CodingKey {
        case id, name, description, runtime, status, model, owner
        case internetEnabled = "internet_enabled"
        case autoRestart = "auto_restart"
        case systemPrompt = "system_prompt"
        case workingDir = "working_dir"
        case lastActiveAt = "last_active_at"
        case mcpServers = "mcp_servers"
    }

    var statusColorName: String {
        switch status {
        case "idle": return "green"
        case "working": return "blue"
        case "waiting-approval": return "orange"
        case "error": return "red"
        case "starting": return "yellow"
        default: return "gray"
        }
    }
}

struct OwnerDTO: Decodable, Hashable {
    let id: Int
    let name: String
}

struct ModelSummary: Decodable, Hashable {
    let id: Int
    let name: String?
    let provider: String?

    var displayName: String { name ?? provider ?? "modelo \(id)" }
}

struct McpSummary: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let type: String?
}

/// Cambios de config de agente. La API (`PUT /agents/{id}`) exige `name`
/// (required); los opcionales se codifican solo si hay valor, para que un
/// cambio parcial no pise el resto.
struct AgentUpdate: Encodable {
    let name: String?
    let description: String?
    let model_id: Int?
    let system_prompt: String?
    let internet_enabled: Bool?
    let auto_restart: Bool?
    let working_dir: String?
    let mcp_server_ids: [Int]?

    enum CodingKeys: String, CodingKey {
        case name, description, system_prompt
        case model_id = "model_id"
        case internet_enabled = "internet_enabled"
        case auto_restart = "auto_restart"
        case working_dir = "working_dir"
        case mcp_server_ids = "mcp_server_ids"
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        if let name { try c.encode(name, forKey: .name) }
        if let description { try c.encode(description, forKey: .description) }
        if let model_id { try c.encode(model_id, forKey: .model_id) }
        if let system_prompt { try c.encode(system_prompt, forKey: .system_prompt) }
        if let internet_enabled { try c.encode(internet_enabled, forKey: .internet_enabled) }
        if let auto_restart { try c.encode(auto_restart, forKey: .auto_restart) }
        if let working_dir { try c.encode(working_dir, forKey: .working_dir) }
        if let mcp_server_ids { try c.encode(mcp_server_ids, forKey: .mcp_server_ids) }
    }
}

// MARK: - Modelos y proveedores

struct ModelDTO: Decodable, Identifiable, Hashable {
    let id: Int
    let modelId: String
    let name: String?
    /// `GET /models` devuelve el NOMBRE del proveedor (string), no un
    /// objeto: decodificarlo como ProviderSummary hundia la lista entera.
    let provider: String?
    /// Formato de alambre del proveedor (openai/anthropic/other). Campo
    /// aditivo del orquestador (ios-model-runtime-compatibility):
    /// `decodeIfPresent` para que un orquestador viejo sin el campo no
    /// rompa la decodificacion (design D5/D6).
    let providerType: String?
    /// Salud del proveedor (ok/error) o null si no ha sido comprobado.
    /// `"error"` -> la UI lo marca atenuado + «no sano» (paridad web).
    let providerStatus: String?

    var displayName: String { name ?? modelId }

    /// Proveedor marcado como no sano: la UI lo atenúa y etiqueta, sin
    /// bloquear la seleccion (spec ios-client "Marca de proveedor no sano").
    var isUnhealthy: Bool { providerStatus == "error" }

    enum CodingKeys: String, CodingKey {
        case id, name, provider
        case modelId = "model_id"
        case providerType = "provider_type"
        case providerStatus = "provider_status"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        modelId = try c.decode(String.self, forKey: .modelId)
        name = try c.decodeIfPresent(String.self, forKey: .name)
        provider = try c.decodeIfPresent(String.self, forKey: .provider)
        providerType = try c.decodeIfPresent(String.self, forKey: .providerType)
        providerStatus = try c.decodeIfPresent(String.self, forKey: .providerStatus)
    }
}

struct ProviderSummary: Decodable, Hashable {
    let id: Int
    let name: String?
    let type: String?
}

struct ProviderDTO: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let type: String?
    let baseUrl: String
    let models: [ModelDTO]?

    enum CodingKeys: String, CodingKey {
        case id, name, type, models
        case baseUrl = "base_url"
    }
}

// MARK: - Runtimes del registro

/// Un runtime soportado por el servicio, tal y como lo expone
/// `GET /v1/runtimes` (ios-model-runtime-compatibility, design D2):
/// identificador, etiqueta de registro y formatos de alambre que consume.
struct RuntimeDTO: Decodable, Identifiable, Hashable {
    let id: String
    let label: String
    /// Formatos de alambre que consume (openai/anthropic). Informativo:
    /// el cliente NUNCA decide la compatibilidad localmente (design D5);
    /// la lista de modelos filtrada por el servidor es la fuente de verdad.
    let formats: [String]?
}

struct RuntimesEnvelope: Decodable { let runtimes: [RuntimeDTO] }

// MARK: - Chat

struct ChatMessageDTO: Decodable, Identifiable, Hashable {
    let id: Int
    let agentId: Int
    let author: String
    let content: String
    let meta: [String: String]?
    let createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, author, content, meta
        case agentId = "agent_id"
        case createdAt = "created_at"
    }

    var isUser: Bool { author == "user" }
    var isAgent: Bool { author == "agent" }
}

struct MessageRequest: Encodable {
    let content: String
}

// MARK: - Reverb

struct AgentEventPayload: Decodable {
    let agentId: Int?
    let type: String
    let payload: [String: JSONValue]?
    let at: String?

    enum CodingKeys: String, CodingKey {
        case type, payload, at
        case agentId = "agent_id"
    }
}

// MARK: - Linea de tiempo (7.2 / spec ios-client: chat parity)

struct TimelineWindowDTO: Decodable, Identifiable, Hashable {
    var id: String { "\(start)-\(toolCalls)-\(open)" }
    let start: String
    let end: String?
    let open: Bool
    let duration: String
    let toolCalls: Int
    let tools: [String]
    let approvals: Int
    let errors: Int
    let messages: Int
    let note: String?

    enum CodingKeys: String, CodingKey {
        case start, end, open, duration, tools, approvals, errors, messages, note
        case toolCalls = "tool_calls"
    }
}

struct TimelineResponse: Decodable {
    let agentStatus: String
    let windows: [TimelineWindowDTO]

    enum CodingKeys: String, CodingKey {
        case windows
        case agentStatus = "agent_status"
    }
}

enum JSONValue: Decodable, Encodable, Hashable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case null
    case object([String: JSONValue])
    case array([JSONValue])

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null }
        else if let b = try? c.decode(Bool.self) { self = .bool(b) }
        else if let n = try? c.decode(Double.self) { self = .number(n) }
        else if let s = try? c.decode(String.self) { self = .string(s) }
        else if let o = try? c.decode([String: JSONValue].self) { self = .object(o) }
        else if let a = try? c.decode([JSONValue].self) { self = .array(a) }
        else { self = .null }
    }

    var stringValue: String? {
        if case .string(let s) = self { return s }
        return nil
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let s): try c.encode(s)
        case .number(let n): try c.encode(n)
        case .bool(let b): try c.encode(b)
        case .null: try c.encodeNil()
        case .object(let o): try c.encode(o)
        case .array(let a): try c.encode(a)
        }
    }
}
