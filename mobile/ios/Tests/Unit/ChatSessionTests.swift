import Foundation
import XCTest

@testable import Taap

/// 8.3: logica del chat (deltas, final, aprobaciones, dedupe) sobre un
/// servidor WSS de pruebas embebido. La parte de aplicacion de eventos
/// (`ChatSession.apply`) se prueba sin red; la parte de transporte se
/// prueba con `PusherTestServer` (misma infraestructura que
/// ReverbSocketTests).
final class ChatSessionTests: XCTestCase {
    // MARK: - Logica pura (sin red)

    private func makeSession() -> ChatSession {
        ChatSession(agentId: 7, api: ApiClient())
    }

    private func event(_ type: String, _ payload: [String: Any] = [:], agentId: Int = 7) -> AgentEventPayload {
        // Mismo shape que AgentEventBroadcast.broadcastWith.
        var dict: [String: Any] = [
            "type": type,
            "agent_id": agentId,
            "at": "2026-01-01T00:00:00Z",
        ]
        if !payload.isEmpty { dict["payload"] = payload }
        let data = try! JSONSerialization.data(withJSONObject: dict)
        return try! JSONDecoder().decode(AgentEventPayload.self, from: data)
    }

    func test_deltaStreamAccumulatesAndFinalizes() {
        let session = makeSession()
        session.apply(event("message.delta", ["content": "Ho"]))
        session.apply(event("message.delta", ["content": "la"]))
        XCTAssertEqual(session.liveDelta, "Hola")
        XCTAssertEqual(session.messages, [])

        session.apply(event("message.final", ["content": "Hola"]))
        XCTAssertEqual(session.liveDelta, "")
        XCTAssertEqual(session.messages.count, 1)
        XCTAssertEqual(session.messages.last?.content, "Hola")
        XCTAssertEqual(session.messages.last?.isAgent, true)
    }

    func test_finalDedupesAgainstHistory() {
        let session = makeSession()
        session.messages = [ChatMessageDTO(id: 1, agentId: 7, author: "agent", content: "Hola", meta: nil, createdAt: nil)]
        session.apply(event("message.final", ["content": "Hola"]))
        XCTAssertEqual(session.messages.count, 1, "el final duplicado no debe añadir otra burbuja")
    }

    func test_runEndFlushesAccumulatedDelta() {
        let session = makeSession()
        session.apply(event("message.delta", ["content": "respuesta parcia"]))
        session.apply(event("run.end"))
        XCTAssertEqual(session.liveDelta, "")
        XCTAssertEqual(session.messages.last?.content, "respuesta parcia")
    }

    func test_approvalRequestAndResolve() {
        let session = makeSession()
        session.apply(event("approval.request", ["id": "apr_1", "prompt": "¿Ejecutar rm -rf?"]))
        XCTAssertEqual(session.pendingApproval?.id, "apr_1")
        XCTAssertEqual(session.pendingApproval?.prompt, "¿Ejecutar rm -rf?")

        session.apply(event("approval.resolved", ["approved": true]))
        XCTAssertNil(session.pendingApproval)
    }

    func test_eventsFromOtherAgentsAreIgnored() {
        let session = makeSession()
        session.apply(event("message.final", ["content": "otro"], agentId: 99))
        XCTAssertEqual(session.messages, [])
        XCTAssertEqual(session.liveDelta, "")
    }

    // MARK: - Integracion: ReverbSocket + PusherTestServer + ChatSession

    var server: PusherTestServer!
    var socket: ReverbSocket?

    override func setUp() {
        super.setUp()
        continueAfterFailure = false
        TokenStore.shared.token = "test-token"
    }

    override func tearDown() {
        socket?.disconnect()
        socket = nil
        server?.stop()
        server = nil
        super.tearDown()
    }

    private func post(_ path: String, body: [String: Any]) async {
        var req = URLRequest(url: server.httpBase.appendingPathComponent(path))
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try? JSONSerialization.data(withJSONObject: body)
        _ = try? await ApiClient.session.data(for: req)
    }

    func test_chatSessionReceivesStreamOverWSS() async throws {
        // Puerto propio: reutilizar el de otro test/proceso choca con
        // TIME_WAIT (el start() reintenta el bind, pero con puerto libre
        // es inmediato).
        server = PusherTestServer(wsPort: 9980, httpPort: 9981)
        try! server.start()
        let socket = ReverbSocket(config: .init(
            wsURL: server.wsBase.appendingPathComponent("app/testkey"),
            authEndpoint: server.httpBase.appendingPathComponent("api/v1/broadcasting/auth"),
        ))
        self.socket = socket

        let session = makeSession()

        let subscribed = expectation(description: "suscripcion confirmada")
        socket.onChannelSubscribed = { _ in subscribed.fulfill() }

        let streamDone = expectation(description: "final recibido")
        var gotFinal = false
        // onEvent corre en cola principal; un solo punto de fulfill (con
        // guard para no doble-fulfill con el bucle de re-emision).
        socket.onEvent = { event in
            session.apply(event)
            if event.type == "message.final", !gotFinal {
                gotFinal = true
                streamDone.fulfill()
            }
        }

        socket.connect()
        socket.subscribe(channel: "agent.7")
        await fulfillment(of: [subscribed], timeout: 8)

        // Pequeños huecos: URLSession reutiliza la conexion HTTP de control
        // y el servidor la cierra tras responder; de golpe se perderian.
        await post("/broadcast", body: ["channel": "agent.7", "event": "agent-event",
                                        "data": ["agent_id": 7, "type": "message.delta",
                                                  "payload": ["content": "Hola"], "at": "2026-01-01T00:00:00Z"]])
        try? await Task.sleep(nanoseconds: 300_000_000)
        await post("/broadcast", body: ["channel": "agent.7", "event": "agent-event",
                                        "data": ["agent_id": 7, "type": "message.delta",
                                                  "payload": ["content": " desde el WSS"], "at": "2026-01-01T00:00:01Z"]])
        try? await Task.sleep(nanoseconds: 300_000_000)

        // El frame final: re-emitir si el POST de control se perdio (el
        // canal de control es best-effort en el simulador ocupado).
        // Re-emite el final si el POST de control se perdio (best-effort
        // en el simulador ocupado). El fulfill lo hace onEvent; el bucle
        // solo decide si seguir re-emitiendo.
        for _ in 0..<5 where !gotFinal {
            await post("/broadcast", body: ["channel": "agent.7", "event": "agent-event",
                                            "data": ["agent_id": 7, "type": "message.final",
                                                      "payload": ["content": "Hola desde el WSS"], "at": "2026-01-01T00:00:02Z"]])
            try? await Task.sleep(nanoseconds: 1_500_000_000)
        }

        await fulfillment(of: [streamDone], timeout: 10)

        // Diagnostico: el estado final dice si el delta llego aunque el
        // final no.
        if !gotFinal {
            XCTFail("stream incompleto: liveDelta=\(session.liveDelta) messages=\(session.messages.map { $0.content })")
        }

        // El delta intermedio se vio en el momento; el final quedo como
        // burbuja y el delta se vacio.
        XCTAssertEqual(session.liveDelta, "")
        XCTAssertEqual(session.messages.count, 1)
        XCTAssertEqual(session.messages.last?.content, "Hola desde el WSS")
    }

    func test_approvalOverWSS() async throws {
        server = PusherTestServer(wsPort: 9970, httpPort: 9971)
        try! server.start()
        let socket = ReverbSocket(config: .init(
            wsURL: server.wsBase.appendingPathComponent("app/testkey"),
            authEndpoint: server.httpBase.appendingPathComponent("api/v1/broadcasting/auth"),
        ))
        self.socket = socket

        let session = makeSession()
        let resolved = expectation(description: "aprobacion resuelta")
        socket.onEvent = { event in
            session.apply(event)
            if event.type == "approval.resolved" {
                DispatchQueue.main.async { resolved.fulfill() }
            }
        }
        let subscribed = expectation(description: "suscripcion")
        socket.onChannelSubscribed = { _ in subscribed.fulfill() }

        socket.connect()
        socket.subscribe(channel: "agent.7")
        await fulfillment(of: [subscribed], timeout: 8)

        await post("/broadcast", body: ["channel": "agent.7", "event": "agent-event",
                                        "data": ["agent_id": 7, "type": "approval.request",
                                                  "payload": ["id": "apr_9", "prompt": "¿Instalar paquete?"],
                                                  "at": "2026-01-01T00:00:00Z"]])
        try await Task.sleep(nanoseconds: 500_000_000)
        XCTAssertEqual(session.pendingApproval?.id, "apr_9")

        await post("/broadcast", body: ["channel": "agent.7", "event": "agent-event",
                                        "data": ["agent_id": 7, "type": "approval.resolved",
                                                  "payload": ["approved": true], "at": "2026-01-01T00:00:01Z"]])
        await fulfillment(of: [resolved], timeout: 8)
        XCTAssertNil(session.pendingApproval)
    }
}
