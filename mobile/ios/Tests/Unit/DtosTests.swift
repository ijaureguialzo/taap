import Foundation
import XCTest

@testable import Taap

/// DTOs de modelos y runtimes (ios-model-runtime-compatibility 5.1, design
/// D5/D6): decodificacion tolerante con un orquestador viejo (los campos
/// nuevos via `decodeIfPresent`) y serializacion del filtro `?runtime=`.
final class DtosTests: XCTestCase {
    private let decoder = JSONDecoder()

    private func decode<T: Decodable>(_ type: T.Type, _ json: String) throws -> T {
        try decoder.decode(type, from: Data(json.utf8))
    }

    // MARK: - ModelDTO: campos nuevos

    func test_model_with_new_provider_fields_decodes() throws {
        let json = """
        {"id": 1, "model_id": "gpt-x", "name": "Modelo GPT", "provider": "prov-ok",
         "provider_type": "openai", "provider_status": "error",
         "context_window": 8000, "max_tokens": 2000, "capabilities": ["tools"]}
        """

        let m = try decode(ModelDTO.self, json)
        XCTAssertEqual(m.id, 1)
        XCTAssertEqual(m.provider, "prov-ok")
        XCTAssertEqual(m.providerType, "openai")
        XCTAssertEqual(m.providerStatus, "error")
        XCTAssertTrue(m.isUnhealthy)
    }

    /// Decodificacion tolerante (design D5): un orquestador anterior, sin
    /// los campos nuevos, debe decodificar igual que antes.
    func test_model_without_new_fields_decodes_from_old_orchestrator() throws {
        let json = """
        {"id": 2, "model_id": "claude-y", "name": "Modelo Claude",
         "provider": "prov-anthropic", "context_window": null,
         "max_tokens": null, "capabilities": null}
        """

        let m = try decode(ModelDTO.self, json)
        XCTAssertEqual(m.modelId, "claude-y")
        XCTAssertNil(m.providerType)
        XCTAssertNil(m.providerStatus)
        XCTAssertFalse(m.isUnhealthy)
    }

    func test_model_with_explicit_null_status_is_not_unhealthy() throws {
        // Sin comprobacion: status null en el JSON (no un string vacio)
        let json = """
        {"id": 3, "model_id": "m", "name": "M", "provider": "p",
         "provider_type": "other", "provider_status": null}
        """

        let m = try decode(ModelDTO.self, json)
        XCTAssertEqual(m.providerType, "other")
        XCTAssertNil(m.providerStatus)
        XCTAssertFalse(m.isUnhealthy)
    }

    func test_healthy_and_unchecked_models_are_not_unhealthy() throws {
        let ok = try decode(ModelDTO.self, """
        {"id": 4, "model_id": "a", "provider": "p", "provider_status": "ok"}
        """)
        XCTAssertFalse(ok.isUnhealthy)

        let unknown = try decode(ModelDTO.self, """
        {"id": 5, "model_id": "b", "provider": "p"}
        """)
        XCTAssertFalse(unknown.isUnhealthy)
    }

    // MARK: - RuntimeDTO

    func test_runtimes_envelope_decodes_the_registry() throws {
        let json = """
        {"runtimes": [
            {"id": "pi", "label": "Pi", "formats": ["openai"]},
            {"id": "hermes", "label": "Hermes", "formats": ["openai"]},
            {"id": "claude", "label": "Claude Code", "formats": ["anthropic"]},
            {"id": "codex", "label": "Codex", "formats": ["openai"]},
            {"id": "opencode", "label": "OpenCode", "formats": ["openai", "anthropic"]}
        ]}
        """

        let res = try decode(RuntimesEnvelope.self, json)
        XCTAssertEqual(res.runtimes.map(\.id), ["pi", "hermes", "claude", "codex", "opencode"])
        XCTAssertEqual(res.runtimes[2].label, "Claude Code")
        XCTAssertEqual(res.runtimes[4].formats, ["openai", "anthropic"])
    }

    // MARK: - Filtro ?runtime= (ApiClient.modelsPath)

    func test_models_path_without_runtime_has_no_query() {
        XCTAssertEqual(ApiClient.modelsPath(), "api/v1/models")
    }

    func test_models_path_with_runtime_appends_the_query() {
        // Se añade ?runtime= solo si hay runtime (los tests de 5.1 cubren
        // la serializacion del parametro, task 5.2).
        XCTAssertEqual(ApiClient.modelsPath(runtime: "claude"), "api/v1/models?runtime=claude")
        XCTAssertEqual(ApiClient.modelsPath(runtime: "pi"), "api/v1/models?runtime=pi")
    }

    // MARK: - 422 de formato

    func test_validation_errors_body_exposes_model_id_error() throws {
        // Cuerpo de un 422 de Laravel: la deteccion del 422 de formato
        // (ApiClient.request) mira la clave `model_id` de `errors`.
        let json = """
        {"message": "The given data was invalid.",
         "errors": {"model_id": ["El modelo usa el formato anthropic, que el runtime Pi no consume."]}}
        """

        let res = try decode(ApiValidationErrors.self, json)
        XCTAssertNotNil(res.errors?["model_id"])
        XCTAssertNil(res.errors?["name"])
    }
}

final class LoginResponseTests: XCTestCase {
    private let decoder = JSONDecoder()
    private func decode(_ json: String) throws -> LoginResponse {
        try decoder.decode(LoginResponse.self, from: Data(json.utf8))
    }

    /// Challenge 2FA: sin token ni user; solo el challenge firmado.
    func test_challenge_response_decodes() throws {
        let json = """
        {"requires_two_factor": true, "challenge": "abc123"}
        """
        let res = try decode(json)
        XCTAssertTrue(res.requiresTwoFactor)
        XCTAssertEqual(res.challenge, "abc123")
        XCTAssertNil(res.token)
        XCTAssertNil(res.user)
    }

    /// Login feliz: token + user, sin reto.
    func test_success_response_decodes() throws {
        let json = """
        {"requires_two_factor": false, "token": "tok",
         "user": {"id": 7, "name": "Test", "email": "t@x", "role": "user"}}
        """
        let res = try decode(json)
        XCTAssertFalse(res.requiresTwoFactor)
        XCTAssertEqual(res.token, "tok")
        XCTAssertEqual(res.user?.id, 7)
    }

    /// Challenge de recuperación también se decodifica.
    func test_challenge_payload_encodes_recovery_code() throws {
        let req = TwoFactorChallengeRequest(challenge: "c123", code: nil, recoveryCode: "r456")
        let data = try JSONEncoder().encode(req)
        let dict = try JSONDecoder().decode([String: String].self, from: data)
        XCTAssertEqual(dict["challenge"], "c123")
        XCTAssertNil(dict["code"])
        XCTAssertEqual(dict["recovery_code"], "r456")
    }
}

final class FilesEnvelopeTests: XCTestCase {
    private let decoder = JSONDecoder()

    func test_files_envelope_decodes() throws {
        let json = """
        {"path": "/datos", "entries": [
            {"name": "docs", "type": "dir", "size": 0, "mtime": "2026-11-26T10:00:00Z", "owner": null},
            {"name": "notes.txt", "type": "file", "size": 2048, "mtime": "2026-11-26T10:00:00Z", "owner": null}
        ]}
        """
        let res = try decoder.decode(FilesEnvelope.self, from: Data(json.utf8))
        XCTAssertEqual(res.path, "/datos")
        XCTAssertEqual(res.entries.count, 2)
        XCTAssertTrue(res.entries[0].isDir)
        XCTAssertFalse(res.entries[1].isDir)
        XCTAssertEqual(res.entries[1].name, "notes.txt")
    }

    func test_entry_size_display_for_files() throws {
        let entry = FileEntryDTO(name: "a.bin", type: "file", size: 2048, mtime: "2026-11-26T10:00:00Z", owner: nil)
        XCTAssertTrue(entry.sizeDisplay.contains("KB"))
        let dir = FileEntryDTO(name: "sub", type: "dir", size: 0, mtime: "2026-11-26T10:00:00Z", owner: nil)
        XCTAssertEqual(dir.sizeDisplay, "—")
    }
}

final class AgentTemplateDTOTests: XCTestCase {
    private let decoder = JSONDecoder()

    func test_template_envelope_decodes() throws {
        let json = """
        {"templates": [
            {"id": 4, "name": "Revisor", "runtime": "pi", "model_id": 7, "internet": true, "cpu": 2.0, "memory": "2g"}
        ]}
        """
        let res = try decoder.decode(TemplatesEnvelope.self, from: Data(json.utf8))
        XCTAssertEqual(res.templates.count, 1)
        XCTAssertEqual(res.templates[0].name, "Revisor")
        XCTAssertEqual(res.templates[0].modelId, 7)
        XCTAssertTrue(res.templates[0].internet)
    }

    func test_template_without_model_decodes() throws {
        let json = """
        {"templates": [
            {"id": 5, "name": "Agente vacío", "runtime": "codex", "model_id": null, "internet": false, "cpu": null, "memory": null}
        ]}
        """
        let res = try decoder.decode(TemplatesEnvelope.self, from: Data(json.utf8))
        XCTAssertNil(res.templates[0].modelId)
        XCTAssertFalse(res.templates[0].internet)
    }
}
