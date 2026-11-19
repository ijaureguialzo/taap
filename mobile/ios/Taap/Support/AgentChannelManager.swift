import Foundation

/// Gestora los canales Reverb de la app con UN socket compartido:
///
///   - `agent.{id}` del agente abierto (chat) via `open(agentId:)`
///   - `agent.{id}` de cada agente de la lista via `track(_:)` para
///     actualizar el estado en vivo (punto verde) sin refrescar.
///
/// La socket se crea cuando llega `reverb-config` (o con el fallback
/// local de desarrollo). Los canales solicitados antes de que exista la
/// socket quedan pendientes y se suscriben al crearla. Tras una
/// reconexion la socket re-suscribe sola (`ReverbSocket`).
final class AgentChannelManager {
    /// Eventos para el chat del agente abierto (cola principal).
    var onEvent: ((AgentEventPayload) -> Void)?
    /// true al conectar, false al perder la conexion (cola principal).
    var onLive: ((Bool) -> Void)?
    /// Cambios de estado de agentes en la lista (cola principal).
    var onAgentStatus: ((_ agentId: Int, _ status: String) -> Void)?

    private var socket: ReverbSocket?
    private var currentAgentId: Int?
    private var trackedIds = Set<Int>()
    private var reverbConfig: ReverbConfig?
    private let api: ApiClient

    init(api: ApiClient) {
        self.api = api
    }

    var isLive: Bool {
        socket?.isConnected ?? false
    }

    // MARK: - Config

    /// Aplica la config real del servidor y crea (o reinicia) la socket.
    func configureWith(config: ReverbConfig) {
        self.reverbConfig = config
        rebuildSocket()
    }

    private func rebuildSocket() {
        socket?.disconnect()
        socket = nil
        ensureSocket()
    }

    /// Crea la socket (si no existe) y suscribe todos los canales
    /// conocidos. Idempotente.
    private func ensureSocket() {
        guard socket == nil else { return }
        guard let ws = wsURL() else { return }
        var cfg = ReverbSocket.Config(
            wsURL: ws,
            authEndpoint: api.baseURL.appendingPathComponent("api/v1/broadcasting/auth"),
        )
        cfg.tokenProvider = { [weak self] in self?.api.token }
        let newSocket = ReverbSocket(config: cfg)
        newSocket.onState = { [weak self] state in
            DispatchQueue.main.async {
                self?.onLive?(state == .connected)
            }
        }
        newSocket.onEvent = { [weak self] event in
            guard let self, let agentId = event.agentId else { return }
            // Estado en vivo de la lista (y del agente abierto).
            if self.trackedIds.contains(agentId) || self.currentAgentId == agentId,
               let status = event.payload?["status"]?.stringValue {
                self.onAgentStatus?(agentId, status)
            }
            // Chat: solo eventos del agente abierto.
            if agentId == self.currentAgentId {
                self.onEvent?(event)
            }
        }
        self.socket = newSocket
        for channel in channels() {
            newSocket.subscribe(channel: channel)
        }
        newSocket.connect()
    }

    private func wsURL() -> URL? {
        if let cfg = reverbConfig, let u = cfg.wsURL {
            return u
        }
        // Fallback local de desarrollo (sin config de reverb).
        return URL(string: "ws://127.0.0.1:8081/")
    }

    private func channels() -> [String] {
        var out = Set<String>()
        if let currentAgentId {
            out.insert("agent.\(currentAgentId)")
        }
        for id in trackedIds {
            out.insert("agent.\(id)")
        }
        return Array(out)
    }

    // MARK: - Chat

    /// Abre (o mantiene) el canal del agente abierto en la vista de chat.
    /// `ReverbSocket.subscribe` es idempotente por canal.
    func open(agentId: Int) {
        currentAgentId = agentId
        if let socket {
            socket.subscribe(channel: "agent.\(agentId)")
        } else {
            ensureSocket()
        }
    }

    /// Cierra la parte de chat (la socket sigue viva para la lista).
    func close() {
        currentAgentId = nil
    }

    // MARK: - Lista en vivo

    /// Traza los canales de los agentes de la lista para recibir sus
    /// `status.change` en vivo.
    func track(_ agentIds: [Int]) {
        let new = Set(agentIds)
        trackedIds = new
        if let socket {
            for id in agentIds {
                socket.subscribe(channel: "agent.\(id)")
            }
        } else {
            ensureSocket()
        }
    }
}
