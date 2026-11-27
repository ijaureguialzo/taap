# Tasks

## 1. Deployment target + proyecto

- [x] 1.1 `project.yml`: bump `deploymentTarget: iOS 26.0` (Opción A); regenerar `Taap.xcodeproj` vía `regenerate.sh`; verificar con `xcodebuild -version` que el Xcode es 26+ y que `Taap.xcodeproj` se abre sin errores

## 2. Helper de vidrio + tokens

- [x] 2.1 Crear `Support/LiquidGlass.swift` con los helpers de vidrio (superficie/tarjeta/botón/fondo), verificando los APIs contra las docs de Xcode 26; verificar con SwiftUI preview que las primitivas renderizan
- [x] 2.2 Ajustar `TaapColor`/`Theme` (sin cambiar los valores) para que los tintes y acentos usen la estética de vidrio; verificar con preview que los tokens siguen siendo AA sobre glass

## 3. Vistas principales

- [x] 3.1 Lista de agentes + ficha de agente (chat, approvals, timeline) con materiales de vidrio; mantener header/back/toolbar con la misma estética; verificar con SwiftUI tests/preview que las vistas se renderizan y el contraste es AA
- [x] 3.2 Sheets (crear agente, settings, timeline) y login con la misma estética; verificar con preview/test en ambos modos claro/oscuro
- [x] 3.3 Accesibilidad: contraste sobre glass, Reduce Motion (reduce/elimina animaciones), tamaño de toque ≥ 44pt, Increase Contrast; verificar con los tests de accesibilidad básicos y preview

## 4. Verificación final

- [x] 4.1 Suite completa de tests (unit/iOS) + docs (notas de release iOS 26 en docs/release-notes-v1.3.md); verificar con `xcodebuild test -scheme Taap -only-testing:TaapUnitTests` en verde
