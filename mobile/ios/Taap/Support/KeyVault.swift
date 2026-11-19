import Foundation
import Security

/// Persistencia mínima del token en Keychain (sin dependencias).
enum KeyVault {
    static let service = "app.taan.taap"
    static let account = "token"

    static func save(token: String?) throws {
        let item = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: service as CFString,
            kSecAttrAccount: account as CFString,
        ] as CFDictionary

        SecItemDelete(item)

        guard let token, let data = token.data(using: .utf8) else { return }

        let query = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: service,
            kSecAttrAccount: account,
            kSecValueData: data,
            kSecAttrAccessible: kSecAttrAccessibleAfterFirstUnlock,
        ] as CFDictionary

        let status = SecItemAdd(query, nil)
        guard status == errSecSuccess else {
            throw KeyVaultError(code: status)
        }
    }

    static func loadToken() throws -> String? {
        let query = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: service,
            kSecAttrAccount: account,
            kSecReturnData: true,
            kSecMatchLimit: kSecMatchLimitOne,
        ] as CFDictionary

        var result: AnyObject?
        let status = SecItemCopyMatching(query, &result)
        guard status == errSecSuccess, let data = result as? Data else {
            return nil
        }
        return String(data: data, encoding: .utf8)
    }
}

struct KeyVaultError: Error {
    let code: OSStatus
}
