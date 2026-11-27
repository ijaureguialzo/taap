import Foundation
import XCTest

@testable import Taap

/// ApiClient: caminos de prueba sin red — composición de URL, mapeo de
/// errores, persistencia de token en KeyVault. El mapeo de errores HTTP
/// (p. ej. 422 model_id → incompatibleModel) requiere un servidor vivo;
/// ahí se prueba el caso de clase con un puerto muerto por localhost.
final class ApiClientTests: XCTestCase {

    // MARK: - modelsPath (composición de URL)

    func test_modelsPath_without_runtime_has_no_query() {
        XCTAssertEqual(ApiClient.modelsPath(runtime: nil), "api/v1/models")
    }

    func test_modelsPath_with_runtime_appends_query() {
        XCTAssertEqual(
            ApiClient.modelsPath(runtime: "pi"),
            "api/v1/models?runtime=pi"
        )
    }

    // MARK: - KeyVault (persistencia del token)

    func test_keychain_round_trip() throws {
        try KeyVault.save(token: "test-token-round-trip")
        XCTAssertEqual(try KeyVault.loadToken(), "test-token-round-trip")
        try KeyVault.save(token: nil)
        XCTAssertNil(try KeyVault.loadToken())
    }

    // MARK: - Error mapping (puerto muerto)

    func test_request_to_dead_port_throws_network_error() async {
        let api = ApiClient(baseURL: URL(string: "http://127.0.0.1:1")!)

        do {
            _ = try await api.login(email: "a@b.c", password: "x")
            XCTFail("expected network error")
        } catch let error as ApiClient.ApiError {
            switch error {
            case .network:
                break  // esperado
            default:
                XCTFail("expected .network, got \(error)")
            }
        } catch {
            XCTFail("expected ApiError, got \(error)")
        }
    }

    // MARK: - baseURL settable (reconfigurable por la UI al primer arranque)

    func test_baseURL_is_settable() {
        let api = ApiClient(baseURL: URL(string: "http://127.0.0.1:8011")!)
        api.baseURL = URL(string: "http://192.168.1.10:8011")!
        XCTAssertTrue(api.baseURL.absoluteString.hasSuffix("http://192.168.1.10:8011/")
            || api.baseURL.absoluteString == "http://192.168.1.10:8011")
    }
}
