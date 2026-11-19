import CryptoKit
import Foundation
import Network

/// Servidor de pruebas del protocolo Pusher v7 embebido en el target de
/// tests (8.3): handshake WebSocket, connection_established, ping/pong,
/// suscripcion y `agent-event` bajo demanda. Sin dependencias del host
/// (Foundation.Process no existe en iOS).
///
/// Dos puertos: `wsPort` (solo upgrade WS) y `httpPort` (auth + control).
final class PusherTestServer {
    private struct Client {
        let conn: NWConnection
        var buffer = Data()
        var handshakeDone = false
        var subChannels: Set<String> = []
    }

    private let wsPort: UInt16
    private let httpPort: UInt16
    private let queue = DispatchQueue(label: "taap.pusher-test-server")
    private var wsListener: NWListener?
    private var httpListener: NWListener?
    private var clients: [Client] = []

    private let guid = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

    init(wsPort: UInt16 = 9960, httpPort: UInt16 = 9961) {
        self.wsPort = wsPort
        self.httpPort = httpPort
    }

    var wsBase: URL { URL(string: "ws://127.0.0.1:\(wsPort)")! }
    var httpBase: URL { URL(string: "http://127.0.0.1:\(httpPort)")! }

    /// Arranca los listeners. `NWListener` no falla al arrancar si el
    /// puerto esta en TIME_WAIT (el bind falla despues, en estado
    /// `.waiting`); este metodo reintenta hasta que ambos queden `.ready`
    /// (p. ej. entre ejecuciones de la suite) o lanza.
    func start() throws {
        for attempt in 0..<12 {
            let l1 = try NWListener(using: .tcp, on: NWEndpoint.Port(rawValue: wsPort)!)
            l1.newConnectionHandler = { [weak self] conn in
                self?.queue.async { self?.handleWS(conn) }
            }
            l1.start(queue: queue)
            let l2 = try NWListener(using: .tcp, on: NWEndpoint.Port(rawValue: httpPort)!)
            l2.newConnectionHandler = { [weak self] conn in
                self?.queue.async { self?.handleHTTP(conn) }
            }
            l2.start(queue: queue)
            wsListener = l1
            httpListener = l2

            let deadline = Date().addingTimeInterval(1.5)
            while Date() < deadline {
                let s1 = l1.state
                let s2 = l2.state
                if s1 == .ready && s2 == .ready { return }
                if Self.isFailed(s1) || Self.isFailed(s2) { break }
                Thread.sleep(forTimeInterval: 0.1)
            }
            let s1 = l1.state
            let s2 = l2.state
            if s1 == .ready && s2 == .ready { return }
            l1.cancel()
            l2.cancel()
            wsListener = nil
            httpListener = nil
            Thread.sleep(forTimeInterval: 0.5)
            _ = attempt
        }
        throw ServerStartError(ports: "\(wsPort)/\(httpPort)")
    }

    private static func isFailed(_ state: NWListener.State) -> Bool {
        if case .failed = state { return true }
        return false
    }

    struct ServerStartError: Error {
        let ports: String
        var description: String { "no se pudo arrantar en \(ports)" }
    }

    func stop() {
        let g = DispatchGroup()
        g.enter()
        queue.async {
            self.wsListener?.cancel()
            self.httpListener?.cancel()
            for c in self.clients { c.conn.cancel() }
            self.clients.removeAll()
            self.wsListener = nil
            self.httpListener = nil
            g.leave()
        }
        g.wait(timeout: .now() + 2)
    }

    // MARK: - WebSocket

    private func handleWS(_ conn: NWConnection) {
        conn.stateUpdateHandler = { [weak self] state in
            if case .failed = state { self?.remove(conn) }
            if case .cancelled = state { self?.remove(conn) }
        }
        conn.start(queue: queue)
        clients.append(Client(conn: conn))
        receiveLoop(conn)
    }

    private func receiveLoop(_ conn: NWConnection) {
        conn.receive(minimumIncompleteLength: 1, maximumLength: 65_536) { [weak self] data, _, isDone, error in
            guard let self else { return }
            if let data, !data.isEmpty {
                self.ingest(conn, data)
            }
            if isDone || error != nil {
                self.remove(conn)
                return
            }
            self.receiveLoop(conn)
        }
    }

    private func ingest(_ conn: NWConnection, _ chunk: Data) {
        guard let i = clients.firstIndex(where: { $0.conn === conn }) else { return }
        clients[i].buffer.append(chunk)

        // Handshake: cuando aparece la cabecera, contestamos 101 + established.
        if !clients[i].handshakeDone,
           let range = clients[i].buffer.range(of: Data("\r\n\r\n".utf8)) {
            let head = String(data: clients[i].buffer.subdata(in: clients[i].buffer.startIndex..<range.upperBound), encoding: .utf8) ?? ""
            clients[i].buffer.removeSubrange(range)
            if head.lowercased().contains("upgrade: websocket"),
               let key = httpHeaderValue(head, "sec-websocket-key") {
                // Los bytes tras la cabecera son frames del cliente (p. ej.
                // el ping inicial): se descartan; el cliente lo reenvia.
                clients[i].buffer = Data()
                clients[i].handshakeDone = true
                conn.send(content: Data("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: \(wsAccept(key))\r\n\r\n".utf8), completion: .contentProcessed { _ in })
                conn.send(content: frame(event: "pusher:connection_established", channel: "pusher",
                                          data: ["socket_id": "1234.5678", "activity_timeout": 240]),
                           completion: .contentProcessed { _ in })
            } else {
                conn.cancel()
                remove(conn)
            }
        }

        guard clients[i].handshakeDone else { return }

        // Frames de texto del cliente (siempre enmascarados por RFC 6455).
        while true {
            let bytes = [UInt8](clients[i].buffer)
            guard bytes.count >= 2 else { break }
            let lenByte = bytes[1] & 0x7f
            guard lenByte < 126 || lenByte == 126 else { break } // los frames de prueba caben aqui
            let payloadLen: Int; let bodyOff: Int
            if lenByte < 126 {
                payloadLen = Int(lenByte); bodyOff = 2
            } else {
                guard bytes.count >= 4 else { break }
                payloadLen = Int(bytes[2]) << 8 | Int(bytes[3]); bodyOff = 4
            }
            let masked = (bytes[1] & 0x80) != 0
            let maskOff = bodyOff + (masked ? 4 : 0)
            guard bytes.count >= maskOff + payloadLen else { break }

            var payload = clients[i].buffer.subdata(in: (clients[i].buffer.startIndex + maskOff)..<(clients[i].buffer.startIndex + maskOff + payloadLen))
            if masked {
                let mask = [UInt8](clients[i].buffer.subdata(in: (clients[i].buffer.startIndex + bodyOff)..<(clients[i].buffer.startIndex + bodyOff + 4)))
                payload = Data((0..<payload.count).map { payload[$0] ^ mask[$0 % 4] })
            }
            clients[i].buffer.removeSubrange(clients[i].buffer.startIndex..<(clients[i].buffer.startIndex + maskOff + payloadLen))

            guard bytes[0] & 0x0f == 0x1,
                  let obj = try? JSONSerialization.jsonObject(with: payload) as? [String: Any],
                  let event = obj["event"] as? String else { continue }

            switch event {
            case "pusher:ping":
                conn.send(content: frame(event: "pusher:pong", channel: nil, data: [:]), completion: .contentProcessed { _ in })
            case "pusher:subscribe":
                if let channel = obj["channel"] as? String {
                    clients[i].subChannels.insert(channel)
                    conn.send(content: frame(event: "pusher_internal:subscription_succeeded", channel: channel, data: [:]), completion: .contentProcessed { _ in })
                }
            case "pusher:unsubscribe":
                if let channel = obj["channel"] as? String {
                    clients[i].subChannels.remove(channel)
                }
            default:
                break
            }
        }
    }

    private func remove(_ conn: NWConnection) {
        clients.removeAll { $0.conn === conn }
    }

    private func wsAccept(_ key: String) -> String {
        Data(Insecure.SHA1.hash(data: Data((key + guid).utf8))).base64EncodedString()
    }

    private func httpHeaderValue(_ head: String, _ name: String) -> String? {
        for line in head.components(separatedBy: "\r\n") {
            let lower = line.lowercased()
            if lower.hasPrefix("\(name):") {
                return line.components(separatedBy: ":").dropFirst().joined(separator: ":").trimmingCharacters(in: .whitespaces)
            }
        }
        return nil
    }

    /// Frame Pusher: `data` viaja como JSON serializado dentro del texto.
    private func frame(event: String, channel: String?, data: [String: Any]) -> Data {
        var obj: [String: Any] = ["event": event, "data": "{}"]
        if let channel { obj["channel"] = channel }
        if let s = try? JSONSerialization.data(withJSONObject: data), let str = String(data: s, encoding: .utf8) {
            obj["data"] = str
        }
        guard let payload = try? JSONSerialization.data(withJSONObject: obj) else { return Data() }
        var out = Data([0x81])
        let n = payload.count
        if n < 126 {
            out.append(UInt8(n))
        } else if n < 65_536 {
            out.append(126)
            out.append(UInt8(n >> 8))
            out.append(UInt8(n & 0xff))
        } else {
            out.append(127)
            for shift in stride(from: 56, through: 0, by: -8) {
                out.append(UInt8((UInt64(n) >> shift) & 0xff))
            }
        }
        out.append(payload)
        return out
    }

    /// Cierra todos los clientes WS (simula caida de red / modo avion).
    func closeClients() {
        let close = Data([0x88, 0x00])
        for c in clients {
            c.conn.send(content: close, completion: .contentProcessed { _ in c.conn.cancel() })
        }
    }

    // MARK: - HTTP de control

    // Bucle de recepcion autoreferencial (los handlers de receive no son
    // recursivos por si mismos).
    private func recvLoop(_ conn: NWConnection, onChunk: @escaping (Data, Bool, Bool) -> Void) {
        conn.receive(minimumIncompleteLength: 1, maximumLength: 65_536) { [weak self] data, _, isDone, error in
            guard let self else { return }
            onChunk(data ?? Data(), isDone, error != nil)
            if isDone || error != nil { return }
            self.recvLoop(conn, onChunk: onChunk)
        }
    }

    private func handleHTTP(_ conn: NWConnection) {
        conn.stateUpdateHandler = { [weak self] state in
            if case .failed = state { conn.cancel() }
            if case .cancelled = state { conn.cancel() }
        }
        conn.start(queue: queue)
        var buffer = Data()
        var head: String?
        var bodyComplete = false

        recvLoop(conn) { [weak self] data, isDone, failed in
            buffer.append(data)

            // Cabecera: se extrae una sola vez; el resto es cuerpo.
            if head == nil, let range = buffer.range(of: Data("\r\n\r\n".utf8)) {
                head = String(data: buffer.subdata(in: buffer.startIndex..<range.upperBound), encoding: .utf8) ?? ""
                // Quitar cabeceras + separador (queda solo el cuerpo).
                buffer.removeSubrange(buffer.startIndex..<range.upperBound)
            }

            if let head, self?.routeHTTP(conn, head: head, rest: &buffer, bodyComplete: &bodyComplete) == true {
                // El request ya se contesto completo; seguimos recibiendo
                // (keep-alive) pero ya no se procesa.
                buffer = Data()
            }

            if isDone || failed {
                conn.cancel()
            }
        }
    }

    /// Devuelve `true` cuando el request ya se ha contestado por completo.
    private func routeHTTP(_ conn: NWConnection, head: String, rest: inout Data, bodyComplete: inout Bool) -> Bool {
        if bodyComplete { return true }
        let lines = head.components(separatedBy: "\r\n")
        let parts = lines.first?.components(separatedBy: " ") ?? []
        let method = parts.first ?? "GET"
        let path = parts.count > 1 ? parts[1] : "/"

        if method == "POST", path.hasSuffix("broadcasting/auth") {
            let sig = Data(Insecure.SHA1.hash(data: Data(head.utf8))).base64EncodedString().prefix(16)
            conn.send(content: http200("{\"auth\":\"test:\(sig)\"}"), completion: .contentProcessed { _ in conn.cancel() })
            bodyComplete = true
            return true
        } else if method == "POST", path == "/close-clients" {
            conn.send(content: http200("{\"ok\":true}"), completion: .contentProcessed { _ in conn.cancel() })
            closeClients()
            bodyComplete = true
            return true
        } else if method == "POST", path == "/broadcast" {
            // Keep-alive: la conexion no se cierra despues del request; el
            // cuerpo se procesa al alcanzar Content-Length (los chunks
            // llegan por el bucle externo, que re-invoca routeHTTP).
            let len = httpHeaderValue(head, "content-length").flatMap(Int.init)
            guard let len, rest.count >= len else { return false }
            let body = rest.prefix(len)
            rest.removeSubrange(rest.startIndex..<rest.index(rest.startIndex, offsetBy: len))
            // Sin keep-alive: URLSession re-diala; evita estados parciales
            // al disparar varios /broadcast seguidos.
            conn.send(content: http200("{\"ok\":true}"), completion: .contentProcessed { _ in conn.cancel() })
            if let obj = try? JSONSerialization.jsonObject(with: Data(body)) as? [String: Any],
               let channel = obj["channel"] as? String,
               let event = obj["event"] as? String {
                let frameData = self.frame(event: event, channel: channel, data: (obj["data"] as? [String: Any]) ?? [:])
                for c in self.clients where c.subChannels.contains(channel) {
                    c.conn.send(content: frameData, completion: .contentProcessed { _ in })
                }
            }
            bodyComplete = true
            return true
        } else {
            conn.send(content: http404(), completion: .contentProcessed { _ in conn.cancel() })
            bodyComplete = true
            return true
        }
    }

    private func http200(_ body: String) -> Data {
        Data("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: \(body.utf8.count)\r\nConnection: close\r\n\r\n\(body)".utf8)
    }

    private func http404() -> Data {
        Data("HTTP/1.1 404 Not Found\r\nContent-Length: 2\r\nConnection: close\r\n\r\nno".utf8)
    }
}
