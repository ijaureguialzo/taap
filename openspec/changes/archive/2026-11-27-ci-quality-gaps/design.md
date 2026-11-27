# Design: Gaps de calidad y CI

## Context
Ver proposal.md — Why. Estado actual: `.github/workflows/ci.yml` tiene 3 jobs: `orchestrator` (tests PHP + Pint + assets), `images` (construye `agent-pi` y `agent-hermes`), `compose-config` (valida compose). Las imágenes Claude Code, Codex y OpenCode — añadidas en v1.1 — no se construyen en CI y no hay job de tests iOS. El repo tiene `mobile/ios/Tests/Unit` (~7 archivos: ChatSession, Dtos, LocaleStrings, ReverbSocket, Theme) sin job de CI ni gate.

## Goals / Non-Goals
**Goals:**
- CI construye las **5** imágenes de agente (las 3 añadidas en v1.1 incluidas).
- CI corre los tests unitarios de iOS (`TaapUnitTests`) para que las regresiones en el SwiftUI se encuentren por CI.

**Non-Goals:**
- E2E completo iOS en CI (requiere simulador/Xcode de sí mismos); despliegue automatizado de imágenes; cobertura de UI tests en CI.

## Decisions

1. **Job `images` (image builds)** — añadir `docker build -t taap/agent-claude:ci agents/claude`, `agent-codex` y `agent-opencode` en el mismo job (heredando `docker buildx` existente). Paralelo al paso existente. La imagen **claude** sigue siendo build de Docker (aunque el repo tiene claude-code en node_modules, la imagen Dockerfile incluye la extensión/binarios según el Dockerfile — la verificación de la imagen se mantiene con el mismo comando `docker build`).
2. **Job `ios` (unit tests en macOS)** — nueva job en `.github/workflows/ci.yml` con `runs-on: macos-latest`, pasos: checkout → `brew install xcodegen` → `cd mobile/ios && ./regenerate.sh` → `xcodebuild -project Taap.xcodeproj -scheme Taap -destination 'platform=iOS Simulator,name=iPhone 17' test -only-testing:TaapUnitTests`. **Decisión explícita**: job en `macos-latest` (runner público de GitHub); el schema/targets ya existen en `project.yml` (targets `TaapUnitTests` ya están declarados), así que no hace falta scaffolding nuevo; UI tests (`TaapUITests`) quedan fuera de CI (requiere simulador complejo; se corre local bajo demanda).
3. **Cobertura iOS (Tests)** — `Tests/Unit/ApiClientTests.swift` nuevo: testea configuración de base URL, mapeo de errores HTTP y construcción de cabeceras de autenticación (sin red — ApiClient expone seams o los tests mockean la capa HTTP con un transport stub). No se toca la infraestructura de testing existente; los tests nuevos siguen el patrón actual (`@testable import`).
4. **Documentación** — `docs/development.md` o README: sección de CI/tests iOS con comandos exactos (`make` / `xcodebuild`), para que un desarrollador pueda correr los tests iOS en local sin adivinar.
5. **Reloj/rol** — no cambia la pipeline de deploy ni el despliegue; solo CI y tests.

## Risks / Trade-offs
- [macOS runner = tiempo de CI más largo] → solo corre en push/PR (no a cada commit); la job es necesaria y barata en minutos.
- [xcodebuild consume mucho tiempo en el runner] → unit tests solo (no UI); si el tiempo crece, considerar caching de DerivedData.
- [`xcodegen` no está en CI] → `brew install xcodegen` en el runner macOS (rápido) o uso de `xcodegen` incluido vía Homebrew de GitHub.
- [Build de las imágenes 3 nuevas] → sigue el patrón del build existente; los Dockerfiles existen desde v1.1 y la imagen se verifica por build (no por ejecutar).

## Migration Plan
- Sin migraciones (solo CI + tests). Rollout: merge → CI verde. Rollback: revertir los jobs (los tests existentes siguen funcionando).

## Open Questions
Ninguna diferible. (El runtime de iOS — `xcodebuild` — debe verificarse en el runner en la implementación; el diseño no afirma APIs concretas de Xcode.)
