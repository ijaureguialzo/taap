# Tasks

## 1. CI images job

- [ ] 1.1 En `.github/workflows/ci.yml`, añadir las imágenes `agent-claude`, `agent-codex` y `agent-opencode` al job `images` (mismo patrón `docker build`); verificar con `gh run list --limit 5` que el workflow pasa con las 5 imágenes

## 2. CI iOS tests job

- [ ] 2.1 Añadir job `ios` (`runs-on: macos-latest`) a `.github/workflows/ci.yml`: checkout + `brew install xcodegen` + `regenerate.sh` + `xcodebuild test -scheme Taap -destination 'platform=iOS Simulator,name=iPhone 17' -only-testing:TaapUnitTests`; verificar con `gh run watch` que la job de tests iOS pasa
- [ ] 2.2 Añadir `Tests/Unit/ApiClientTests.swift` (base URL, mapeo de errores HTTP, cabeceras de autenticación — sin red); verificar con `xcodebuild test -scheme Taap -only-testing:TaapUnitTests -only-testing:TaapUnitTests/ApiClientTests` verde

## 3. Documentación

- [ ] 3.1 Documentar en `docs/` (o README) el procedimiento local de tests iOS (`regenerate.sh`, `xcodebuild`); verificar con `grep` que los comandos coincidentes con los del CI

## 4. Verificación final

- [ ] 4.1 Suite completa (`php artisan test` + CI workflow con los 4 jobs) y verificación de que los Dockerfiles de las 5 imágenes construyen; verificar con `gh run list` en verde
