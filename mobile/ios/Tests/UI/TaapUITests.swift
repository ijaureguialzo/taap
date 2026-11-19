import Foundation
import XCTest

/// E2E minimo de la app contra el orquestador local (deploy/compose.yaml:
/// HTTP 127.0.0.1:8011, Reverb 127.0.0.1:8081).
///
/// Hay dos tests:
///  - `login_flow`: pantalla de login, credenciales y entrada (usa el
///    endpoint real de login).
///  - `chat_flow`: arranca ya autenticado (token por argumento de
///    arranque, obtenido via HTTP desde el host de tests) y recorre
///    lista de agentes -> detalle -> chat. Determinista.
@MainActor
final class TaapUITests: XCTestCase {
    static let bundleId = "app.taan.taap"
    static let serviceURL = "http://127.0.0.1:8011"
    var app: XCUIApplication!
    var attachments: [XCTAttachment] = []

    override func setUp() {
        continueAfterFailure = true
        app = XCUIApplication()
    }

    /// Token valido + id del agente web-hermes (endpoints reales).
    private func fetchToken() async -> String? {
        var req = URLRequest(url: URL(string: "http://127.0.0.1:8011/api/v1/auth/login")!)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        let body: [String: String] = ["email": "admin@taap.local", "password": "taap1234"]
        req.httpBody = try? JSONSerialization.data(withJSONObject: body)
        guard let (data, _) = try? await URLSession.shared.data(for: req),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let token = json["token"] as? String else {
            return nil
        }
        return token
    }

    /// Id del agente "web-hermes" (para abrir el chat por argumento).
    private func fetchAgentId(token: String) async -> Int? {
        guard let agentsURL = URL(string: "http://127.0.0.1:8011/api/v1/agents") else { return nil }
        var req = URLRequest(url: agentsURL)
        req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        guard let (data, _) = try? await URLSession.shared.data(for: req),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let agents = json["agents"] as? [[String: Any]] else {
            return nil
        }
        return agents.first(where: { ($0["name"] as? String) == "web-hermes" })?["id"] as? Int
    }

    // MARK: - Login (correo + password contra el orquestador real)

    func test_loginFlow() async throws {
        app.launchArguments = ["--taap-clean", "--taap-locale", "es"]
        app.launch()

        let login = app.staticTexts["Iniciar sesión"]
        XCTAssertTrue(login.waitForExistence(timeout: 20), "pantalla de login no aparece")

        let email = app.textFields["Correo electrónico"]
        email.tap()
        app.typeText("admin@taap.local")
        let emailValue = email.value as? String ?? ""
        if emailValue != "admin@taap.local" {
            // El teclado capitalizo: borrar y reescribir minusculas.
            email.tap()
            for _ in 0..<emailValue.count { app.typeText("\u{08}") }
            app.typeText("admin@taap.local")
        }
        app.secureTextFields["Contraseña"].tap()
        app.typeText("taap1234")
        app.buttons["Entrar"].tap()

        // Tras el login la lista de agentes debe aparecer.
        let list = app.staticTexts["Agentes"]
        if !list.waitForExistence(timeout: 25) {
            attach("login-after-submit")
            XCTFail("lista de agentes no aparece tras login con credenciales")
        }
        let row = app.otherElements.containing(NSPredicate(format: "label CONTAINS %@", "web-hermes")).firstMatch
        if !row.waitForExistence(timeout: 15) {
            attach("login-agent-list")
            XCTFail("fila web-hermes no aparece tras login")
        }
    }

    // MARK: - Chat (ya autenticado)

    func test_chatFlow() async throws {
        guard let token = await fetchToken() else {
            throw XCTSkip("orquestador no disponible (login falló); arrancar docker compose en deploy/")
        }
        let agentId = await fetchAgentId(token: token)

        // Fase 1: lista de agentes (sin open-agent: la lista debe verse).
        app.launchArguments = ["--taap-clean", "--taap-locale", "es", "--taap-token", token]
        app.launch()

        let list = app.staticTexts["Agentes"]
        if !list.waitForExistence(timeout: 20) {
            attach("chat-list")
            XCTFail("lista de agentes no aparece")
        }
        let row = app.otherElements.containing(NSPredicate(format: "label CONTAINS %@", "web-hermes")).firstMatch
        if !row.waitForExistence(timeout: 15) {
            attach("chat-list-rows")
            XCTFail("fila web-hermes no aparece en la lista")
        }

        // Fase 2: chat. El tap sobre filas de List es frágil en la capa de
        // accessibility; se relanza abriendo el agente por argumento.
        app.terminate()
        if let agentId {
            app.launchArguments = ["--taap-clean", "--taap-locale", "es", "--taap-token", token, "--taap-open-agent", String(agentId)]
        } else {
            app.launchArguments = ["--taap-clean", "--taap-locale", "es", "--taap-token", token]
            row.tap()
        }
        app.launch()

        let detailTitle = app.staticTexts["web-hermes"]
        if !detailTitle.firstMatch.waitForExistence(timeout: 15) {
            attach("chat-detail-open")
            XCTFail("detalle del agente no abre")
        }

        let composer = app.textFields.matching(
            NSPredicate(format: "label CONTAINS %@ OR placeholderValue CONTAINS %@ OR placeholderValue CONTAINS %@", "Mensaje", "mensaje", "Escribe un mensaje")
        ).firstMatch
        if !composer.waitForExistence(timeout: 20) {
            attach("chat-composer")
            XCTFail("composer no aparece en el chat")
        }
    }

    private func attach(_ tag: String) {
        let name = "taap-ui-\(tag)"
        let shot = app.screenshot()
        if let data = shot.image.pngData() {
            attachments.append(XCTAttachment(uniformTypeIdentifier: "public.png", name: name, payload: data))
        }
    }
}
