import Foundation

/// Cliente WebSocket del protocolo Pusher (v7) contra Reverb.
///
/// Flujo (igual que pusher-js):
///   1. WSS a `/app/{key}?protocol=7&client=...`
///   2. `pusher:connection_established` con `data.socket_id`
///   3. Por cada canal: `POST /broadcasting/auth {channel_name, socket_id}`
///      -> `{auth: "key:signature"}`
///   4. `pusher:subscribe` con `data.auth`
///   5. `pusher:subscription_succeeded` y luego los `agent-event`
///
/// Concurrency: TODO el estado interno se muta en `socketQueue` (serial).
/// Las callbacks publicas (`onState`, `onEvent`) se invocan en la cola
/// principal. `URLSessionWebSocketTask` entrega los mensajes en una cola
/// interna, asi que cada callback salta a `socketQueue` antes de tocar
/// estado: `subscribe()` desde el main actor y el `connection_established`
/// (en cola de CFNetwork) quedan deterministas (el socket_id siempre esta
/// resuelto cuando se intenta autenticar un canal pendiente).
final class ReverbSocket {
    struct Config {
        let wsURL: URL
        let authEndpoint: URL
        /// Token de sesion para el handshake de canales (lo provee el
        /// owner, p. ej. `api.token`): unica fuente de verdad.
        var tokenProvider: (() -> String?)?
    }

    enum State: Equatable {
        case disconnected
        case connecting
        case connected
    }

    /// Callbacks publicas (siempre en cola principal).
    var onState: ((State) -> Void)?
    var onEvent: ((AgentEventPayload) -> Void)?
    /// Se invoca cuando el servidor confirma `pusher:subscription_succeeded`
    /// para un canal (a partir de aqui el cliente recibe sus eventos).
    var onChannelSubscribed: ((String) -> Void)?

    private let socketQueue = DispatchQueue(label: "taap.reverb-socket")

    private var task: URLSessionWebSocketTask?
    private var state: State = .disconnected {
        didSet {
            guard oldValue != state else { return }
            let s = state
            Self.wlog("state \(oldValue) -> \(s)")
            DispatchQueue.main.async { self.onState?(s) }
        }
    }

    #if DEBUG
    static func wlog(_ text: String) {
        let line = "\(Date()) \(text)\n"
        let path = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0].appendingPathComponent("taap-ws.log")
        if let handle = try? FileHandle(forWritingTo: path) {
            handle.seekToEndOfFile()
            handle.write(line.data(using: .utf8)!)
            try? handle.close()
        } else {
            try? line.write(toFile: path.path, atomically: true, encoding: .utf8)
        }
    }
    #else
    static func wlog(_ text: String) {}
    #endif

    private var socketId: String?
    private var pendingChannels: Set<String> = []
    private var subscribedChannels: Set<String> = []
    private var retryTask: Task<Void, Never>?
    private var retryAttempt = 0
    private var pingTimer: Task<Void, Never>?
    private let config: Config
    private let session: URLSession

    init(config: Config) {
        self.config = config
        let cfg = URLSessionConfiguration.ephemeral
        cfg.timeoutIntervalForRequest = 3600
        cfg.timeoutIntervalForResource = 3600
        self.session = URLSession(configuration: cfg)
    }

    var isConnected: Bool { state == .connected }

    // MARK: - Ciclo de vida

    /// (Re)conecta el socket. Idempotente mientras ya hay intento en curso.
    func connect() {
        socketQueue.async { self.connectInternal() }
    }

    private func connectInternal() {
        guard state != .connecting, task == nil else { return }
        state = .connecting

        var comps = URLComponents(url: config.wsURL, resolvingAgainstBaseURL: false)!
        comps.queryItems = [
            URLQueryItem(name: "protocol", value: "7"),
            URLQueryItem(name: "client", value: "taap-ios"),
            URLQueryItem(name: "version", value: "1.0"),
            URLQueryItem(name: "flash", value: "0"),
        ]
        let task = session.webSocketTask(with: comps.url!)
        self.task = task
        task.resume()
        receiveNext(from: task)

        // Reverb responde a cualquier frame; un ping inmediato fuerza el
        // handshake y sirve de keepalive.
        sendRaw(event: "pusher:ping", channel: nil, data: nil)
        startPingTimer()
    }

    private func startPingTimer() {
        pingTimer?.cancel()
        pingTimer = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(20))
                guard let self else { return }
                self.socketQueue.async {
                    guard self.state == .connected else { return }
                    self.sendRaw(event: "pusher:ping", channel: nil, data: nil)
                }
            }
        }
    }

    /// Cierra el socket y deja de reintentar.
    func disconnect() {
        socketQueue.async {
            self.retryTask?.cancel()
            self.retryTask = nil
            self.pingTimer?.cancel()
            self.pingTimer = nil
            self.task?.cancel(with: .goingAway, reason: nil)
            self.task = nil
            self.socketId = nil
            self.pendingChannels = []
            self.subscribedChannels = []
            self.retryAttempt = 0
            self.state = .disconnected
        }
    }

    /// Suscribe a un canal (autentica via `authEndpoint` y envia
    /// `pusher:subscribe`). Si el socket no esta establecido, el canal
    /// queda pendiente y se autenta al conectar.
    func subscribe(channel: String) {
        socketQueue.async {
            guard !self.subscribedChannels.contains(channel) else { return }
            self.pendingChannels.insert(channel)
            #if DEBUG
            Self.wlog("subscribe \(channel) (sid=\(self.socketId ?? "nil") state=\(self.state))")
            #endif
            if let sid = self.socketId {
                self.authenticate(channel: channel, socketId: sid)
            }
        }
    }

    func unsubscribe(channel: String) {
        socketQueue.async {
            self.subscribedChannels.remove(channel)
            self.pendingChannels.remove(channel)
            self.sendRaw(event: "pusher:unsubscribe", channel: channel, data: nil)
        }
    }

    // MARK: - Recibir

    private func receiveNext(from task: URLSessionWebSocketTask) {
        task.receive { [weak self] result in
            guard let self else { return }
            self.socketQueue.async {
                switch result {
                case .success(let message):
                    self.handle(message: message, task: task)
                case .failure:
                    self.handleDisconnect()
                @unknown default:
                    break
                }
            }
        }
    }

    private func handle(message: URLSessionWebSocketTask.Message, task: URLSessionWebSocketTask) {
        var text = ""
        switch message {
        case .string(let s): text = s
        case .data(let d): text = String(data: d, encoding: .utf8) ?? ""
        @unknown default: break
        }

        // Pusher v7: cada mensaje WS es un frame JSON completo (sin
        // separador). Si un server enviara varios frames en un mensaje
        // (separados por \n), se reparten.
        for line in text.split(separator: "\n") {
            handleLine(String(line))
        }
        // Re-armar: una llamada `receive` consume un mensaje. Si el frame
        // provoco `handleDisconnect`, `task` ya es nil y no re-armamos el
        // task cancelado (evita cascada de fallos).
        if self.task === task {
            receiveNext(from: task)
        }
    }

    private struct WireFrame: Decodable {
        let event: String?
        let channel: String?
        let data: String?

        enum CodingKeys: String, CodingKey { case event, channel, data }
    }

    private func handleLine(_ line: String) {
        #if DEBUG
        Self.wlog("frame: \(String(line.prefix(120)))")
        #endif
        guard let data = line.data(using: .utf8),
              let frame = try? JSONDecoder().decode(WireFrame.self, from: data) else { return }
        #if DEBUG
        Self.wlog("parsed: \(frame.event ?? "?") \(frame.channel ?? "")")
        #endif

        // `data` llega como JSON *serializado* (string).
        let dataObject: [String: JSONValue]? = {
            guard let raw = frame.data, let d = raw.data(using: .utf8) else { return nil }
            return try? JSONDecoder().decode([String: JSONValue].self, from: d)
        }()

        switch frame.event {
        case "pusher:connection_established":
            // Primero el socket_id, DESPUES el estado: quien observe
            // `.connected` ya puede suscribir sin carreras.
            socketId = dataObject?["socket_id"]?.stringValue
            state = .connected
            retryAttempt = 0
            for channel in Array(pendingChannels) {
                if let sid = socketId {
                    authenticate(channel: channel, socketId: sid)
                }
            }
        case "pusher:subscription_succeeded", "pusher_internal:subscription_succeeded":
            if let channel = frame.channel {
                subscribedChannels.insert(channel)
                pendingChannels.remove(channel)
                DispatchQueue.main.async { self.onChannelSubscribed?(channel) }
            }
        case "pusher:disconnect":
            // El servidor nos descuelga: reconectamos (y re-suscribimos).
            handleDisconnect()
        case "agent-event":
            guard let raw = frame.data, let d = raw.data(using: .utf8),
                  let payload = try? JSONDecoder().decode(AgentEventPayload.self, from: d) else {
                return
            }
            DispatchQueue.main.async { self.onEvent?(payload) }
        default:
            break
        }
    }

    // MARK: - Auth + frames

    private func authenticate(channel: String, socketId: String) {
        var req = URLRequest(url: config.authEndpoint)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = config.tokenProvider?() {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        let body: [String: String] = [
            "channel_name": channel,
            "socket_id": socketId,
        ]
        req.httpBody = try? JSONEncoder().encode(body)

        Task { [weak self] in
            guard let self else { return }
            do {
                let (data, response) = try await ApiClient.session.data(for: req)
                guard let http = response as? HTTPURLResponse, http.statusCode < 400,
                      let dict = try? JSONDecoder().decode([String: String].self, from: data),
                      let auth = dict["auth"] else {
                    #if DEBUG
                    Self.wlog("auth FAIL \(channel) http=\((response as? HTTPURLResponse)?.statusCode ?? -1)")
                    #endif
                    return
                }
                self.socketQueue.async {
                    #if DEBUG
                    Self.wlog("auth ok para \(channel)")
                    #endif
                    // Reverb lee `channel` desde `data` del frame (no del
                    // campo top-level); pusher-js lo incluye en ambos.
                    self.sendRaw(event: "pusher:subscribe", channel: channel, data: ["channel": .string(channel), "auth": .string(auth)])
                }
            } catch {
                // El canal queda pendiente; se re-intenta al reconectar.
            }
        }
    }

    private func sendRaw(event: String, channel: String?, data: [String: JSONValue]?) {
        // CFNetwork encola los frames hasta que el handshake 101 completa;
        // se permite enviar tambien en `.connecting` (el ping inicial).
        guard state != .disconnected, let task else { return }
        struct Outgoing: Encodable {
            let event: String
            let channel: String?
            var data: String?

            enum CodingKeys: String, CodingKey { case event, channel, data }
        }
        var outgoing = Outgoing(event: event, channel: channel, data: nil)
        if let data, let d = try? JSONEncoder().encode(data), let s = String(data: d, encoding: .utf8) {
            outgoing.data = s
        }
        guard let encoded = try? JSONEncoder().encode(outgoing),
              let string = String(data: encoded, encoding: .utf8) else { return }
        task.send(.string(string)) { _ in }
    }

    private func handleDisconnect() {
        // Solo si el cambio de estado es real (evita doble reconexion si
        // recibimos frame close y fallo de receive a la vez).
        guard state != .disconnected || retryTask != nil else {
            // Ya desconectado y sin reintento programado: no hacer nada.
            return
        }
        state = .disconnected
        socketId = nil
        task?.cancel()
        task = nil
        // Los canales suscritos/pendientes se reintentan en la reconexion.
        scheduleRetry()
    }

    private func scheduleRetry() {
        guard retryTask == nil else { return }
        retryAttempt += 1
        let delay = min(pow(2.0, Double(retryAttempt)), 30.0)
        let channels = subscribedChannels.union(pendingChannels)
        subscribedChannels = []

        retryTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(delay) * 1_000_000_000)
            guard !Task.isCancelled, let self else { return }
            self.socketQueue.async {
                self.retryTask = nil
                self.connectInternal()
                for channel in channels {
                    self.pendingChannels.insert(channel)
                }
            }
        }
    }
}

/// Token de sesion en memoria + Keychain (KeyVault).
final class TokenStore {
    static let shared = TokenStore()
    var token: String? {
        didSet { try? KeyVault.save(token: token) }
    }

    init() {
        token = try? KeyVault.loadToken()
    }
}
