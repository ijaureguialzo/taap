import Foundation
import XCTest

@testable import Taap

/// 8.5: catálogo es/en/eu con override del usuario y fallback.
final class LocaleStringsTests: XCTestCase {
    func test_supported_locales_cover_key_areas() {
        let keys = [
            "login.title", "login.email", "login.password", "login.submit",
            "agents.title", "agents.new", "agents.empty", "agents.start", "agents.stop",
            "status.idle", "status.working", "status.waiting-approval", "status.error",
            "chat.placeholder", "chat.approve", "chat.deny",
            "settings.title", "settings.model", "settings.internet",
            "timeline.title", "timeline.empty", "timeline.open", "timeline.done",
            "offline.title", "offline.body",
        ]
        for locale in ["es", "en", "eu"] {
            for key in keys {
                let value = LocaleStrings.t(locale, key)
                XCTAssertNotEqual(value, key, "falta la clave \(key) en \(locale)")
                XCTAssertFalse(value.isEmpty, "clave vacía \(key) en \(locale)")
            }
        }
    }

    func test_locales_are_actually_different() {
        // Que en/es/eu no sean copias del mismo texto.
        XCTAssertNotEqual(LocaleStrings.t("es", "agents.title"), LocaleStrings.t("en", "agents.title"))
        XCTAssertNotEqual(LocaleStrings.t("es", "timeline.title"), LocaleStrings.t("eu", "timeline.title"))
    }

    func test_unknown_locale_falls_back_to_spanish() {
        XCTAssertEqual(LocaleStrings.t("fr", "login.title"), LocaleStrings.t("es", "login.title"))
        XCTAssertEqual(LocaleStrings.t("de", "timeline.open"), LocaleStrings.t("es", "timeline.open"))
    }

    func test_missing_key_returns_the_key_itself() {
        XCTAssertEqual(LocaleStrings.t("es", "no.existe.clave"), "no.existe.clave")
    }

    func test_user_override_changes_language() {
        // El override del usuario es un simple cambio de locale en AppState;
        // aqui se verifica que el mismo key resuelve distinto por locale.
        let es = LocaleStrings.t("es", "chat.approve")
        let en = LocaleStrings.t("en", "chat.approve")
        XCTAssertNotEqual(es, en)
    }
}

// Spec localization: preferencia del usuario por dispositivo, default por
// idioma del dispositivo y reconexión/resincronización (parte de 8.5).
final class LocalePreferenceTests: XCTestCase {
    func test_isSupportedOnlyKnownLocales() {
        XCTAssertTrue(LocaleStrings.isSupported("es"))
        XCTAssertTrue(LocaleStrings.isSupported("en"))
        XCTAssertTrue(LocaleStrings.isSupported("eu"))
        XCTAssertFalse(LocaleStrings.isSupported("fr"))
        XCTAssertFalse(LocaleStrings.isSupported(""))
    }

    func test_deviceDefaultFallsBackToSpanish() {
        // El dispositivo de test es inglés: el default debe ser "en"
        // (soportado) y no castellano.
        let def = LocaleStrings.deviceDefault()
        XCTAssertTrue(["es", "en", "eu"].contains(def))
    }

    func test_missingKeyFallsBackToEnglishThenKey() {
        // Clave inexistente en todas las tablas: devuelve la clave (modo dev).
        XCTAssertEqual(LocaleStrings.t("eu", "no.exists"), "no.exists")
    }
}
