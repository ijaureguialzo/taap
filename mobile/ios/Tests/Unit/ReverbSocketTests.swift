import Foundation
import XCTest

@testable import Taap

/// 8.3 / 8.5: el cliente ReverbSocket (protocolo Pusher v7) contra un
/// servidor WSS de pruebas embebido: handshake, suscripcion autorizada,
/// eventos y reconexion + re-suscripcion tras una caida.
final class ReverbSocketTests: XCTestCase {
    var server: PusherTestServer!
    var socket: ReverbSocket?

    override func setUp() {
        super.setUp()
        continueAfterFailure = false
        TokenStore.shared.token = "test-token"
        server = PusherTestServer()
        try? server.start()
    }

    override func tearDown() {
        socket?.disconnect()
        socket = nil
        server?.stop()
        server = nil
        super.tearDown()
    }

    private func makeSocket() -> ReverbSocket {
        let s = ReverbSocket(config: .init(
            wsURL: server.wsBase.appendingPathComponent("app/testkey"),
            authEndpoint: server.httpBase.appendingPathComponent("api/v1/broadcasting/auth"),
        ))
        socket = s
        return s
    }

    private func post(_ path: String, body: [String: Any]) async {
        var req = URLRequest(url: server.httpBase.appendingPathComponent(path))
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try? JSONSerialization.data(withJSONObject: body)
        _ = try? await ApiClient.session.data(for: req)
    }

    func test_handshakeSubscribeAndReceiveEvents() async throws {
        let socket = makeSocket()

        let connected = expectation(description: "connected")
        let subscribed = expectation(description: "suscripcion confirmada")
        let gotEvent = expectation(description: "evento tras suscripcion")
        var received: [AgentEventPayload] = []

        socket.onState = { state in
            if state == .connected { connected.fulfill() }
        }
        socket.onChannelSubscribed = { _ in subscribed.fulfill() }
        socket.onEvent = { event in
            received.append(event)
            if event.type == "message.final" { gotEvent.fulfill() }
        }

        socket.connect()
        socket.subscribe(channel: "agent.7")

        // Esperar a que el servidor confirme la suscripcion ANTES de
        // emitir: si se hiciera antes, el fan-out no llegaria a ningun
        // cliente (el frame subscribe estaria en vuelo).
        await fulfillment(of: [connected, subscribed], timeout: 8)

        await post("/broadcast", body: [
            "channel": "agent.7",
            "event": "agent-event",
            "data": [
                "type": "message.final",
                "payload": ["content": "hola"],
                "at": "2026-01-01T00:00:00Z",
            ],
        ])

        await fulfillment(of: [gotEvent], timeout: 8)
        XCTAssertEqual(received.last?.type, "message.final")
        XCTAssertEqual(received.last?.payload?["content"]?.stringValue, "hola")
    }

    func test_reconnectsAndResubscribesAfterServerClosesSocket() async throws {
        let socket = makeSocket()

        let firstConnected = expectation(description: "primera conexion")
        let dropped = expectation(description: "caida")
        dropped.assertForOverFulfill = false // el cliente puede ver la caida dos veces (frame close + fallo)
        let reconnected = expectation(description: "reconexion")
        let resubscribed = expectation(description: "evento tras reconectar")
        let subscribed = expectation(description: "suscripcion confirmada")
        subscribed.assertForOverFulfill = false // también dispara la primera
        var connectedCount = 0
        var events = 0

        socket.onState = { state in
            switch state {
            case .connected:
                connectedCount += 1
                if connectedCount == 1 {
                    firstConnected.fulfill()
                } else {
                    reconnected.fulfill()
                }
            case .disconnected:
                dropped.fulfill()
            case .connecting:
                break
            }
        }
        socket.onEvent = { _ in
            events += 1
            resubscribed.fulfill()
        }
        socket.onChannelSubscribed = { _ in subscribed.fulfill() }

        socket.connect()
        socket.subscribe(channel: "agent.9")
        await fulfillment(of: [firstConnected], timeout: 8)

        // El servidor corta todas las conexiones (simula modo avion).
        await post("/close-clients", body: [:])
        await fulfillment(of: [dropped], timeout: 8)

        // Backoff: el primer reintento llega a los 2s; holgura de 15s.
        await fulfillment(of: [reconnected], timeout: 15)

        // Tras la reconexion el canal debe estar suscrito de nuevo
        // (subscription_succeeded) ANTES de emitir el evento.
        await fulfillment(of: [subscribed], timeout: 8)

        await post("/broadcast", body: [
            "channel": "agent.9",
            "event": "agent-event",
            "data": ["type": "status.change", "payload": ["status": "idle"], "at": "2026-01-01T00:00:01Z"],
        ])
        await fulfillment(of: [resubscribed], timeout: 8)
        XCTAssertGreaterThanOrEqual(events, 1)
    }
}
