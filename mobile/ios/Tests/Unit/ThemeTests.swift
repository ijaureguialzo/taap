import SwiftUI
import XCTest

@testable import Taap

/// Tema de 3 estados (taap-visual-identity 5.2, design D5): mismo contrato
/// que la web — valor guardado -> auto (sistema) -> y un valor desconocido
/// o ausente resuelve a auto, nunca rompe.
final class ThemeTests: XCTestCase {

    func test_resolutionOfStoredValues() {
        XCTAssertEqual(TaapTheme(resolving: "auto"), .auto)
        XCTAssertEqual(TaapTheme(resolving: "light"), .light)
        XCTAssertEqual(TaapTheme(resolving: "dark"), .dark)
    }

    func test_missingOrUnknownResolvesToAuto() {
        XCTAssertEqual(TaapTheme(resolving: nil), .auto)
        XCTAssertEqual(TaapTheme(resolving: ""), .auto)
        XCTAssertEqual(TaapTheme(resolving: "weird"), .auto)
    }

    func test_colorSchemeMapping() {
        // auto libera el control al sistema (nil); light/dark fuerzan.
        XCTAssertNil(TaapTheme.auto.colorScheme)
        XCTAssertEqual(TaapTheme.light.colorScheme, .light)
        XCTAssertEqual(TaapTheme.dark.colorScheme, .dark)
    }
}
