# Proposal

## Why

La aplicación no tiene identidad visual propia: la marca web es un emoji (⚡), el favicon es un cubo 3D rojo ajeno al producto, los botones usan emoji sueltos (🗑), los colores son los defaults de Bootstrap y en iOS hay colores hardcodeados, y no existe modo oscuro en ninguna plataforma. TAAP (el "grifo") merece una marca reconocible (un grifo vectorial plano que sirva de favicon e icono de app), un sistema de iconos coherente y una paleta propia con dark mode de extremo a extremo.

## What Changes

- **Marca**: nuevo logotipo vectorial plano — la silueta de un grifo con una gota — como favicon (SVG + ICO), icono de app iOS (asset catalog que hoy no existe y el build referencia), wordmark en el navbar web y apple-touch-icon. Se jubilan el emoji ⚡ y el favicon de cubo rojo. Script de generación de todos los formatos desde un maestro SVG.
- **Iconos web**: integración de Bootstrap Icons (dependencia npm + Vite, sin CDN) y componente `<x-icon>`; pase de iconos en todas las vistas (nav, badges de estado/admin, acciones, chat, auth, estados vacíos) sustituyendo los emoji actuales.
- **Iconos iOS**: mapa semántico equivalente con SF Symbols (la plataforma manda; Bootstrap Icons es solo para web).
- **Paleta propia**: escalas de color de la marca (aqua/teal primario, acento "ink", estado y neutros fríos) como tokens: variables CSS que reescriben las variables `--bs-*` de Bootstrap (5.3) para que todos los componentes adopten la paleta, y color sets en el asset catalog iOS (sustituyendo `.indigo`/`.red`/`systemGray5` hardcodeados).
- **Modo oscuro**: conmutador de tres estados (auto/claro/oscuro) con paridad web e iOS; en web vía `data-bs-theme` (sin flash: script inline antes del pintado) y en iOS vía `preferredColorScheme` + variantes dark de los color sets. Contraste AA auditado por test sobre la tabla de tokens.

## Capabilities

### New Capabilities

- `brand-identity`: la identidad visual de TAAP en todas las plataformas — la marca (el grifo: maestro, formatos, safe zone, usos), el sistema de iconos (mapa semántico por superficie y regla de accesibilidad), los tokens de color (escalas de la marca y pares de contraste AA) y el modo oscuro (contrato de tres estados, paridad web/iOS, sin flash).

### Modified Capabilities

(ninguna: los requirements nuevos viven en `brand-identity`; las funcionalidades existentes de `ios-client` y del resto no cambian)

## Impact

- **Web**: `package.json` (+bootstrap-icons), `resources/css/app.css` (tokens + overrides `--bs-*` + tema), `resources/views/layouts/{app,auth}.blade.php` (wordmark, tema, favicon), todas las vistas Livewire (pase de iconos, tokens), nuevo componente Blade de icono, favicons en `public/`, nuevo script de marca.
- **iOS**: `Taap/Assets.xcassets` nuevo (AppIcon + color sets; el pbxproj ya referencia `AppIcon` sin archivo — hoy el build usa icono genérico), accessors de color, pase de colores en `Views/`, conmutador de tema en ajustes, `regenerate.sh`/`project.yml` (recogida del asset catalog).
- **Tests**: unitarios de tokens (contraste AA), persistencia/estado del tema (web e iOS), y el test de UI existente debe seguir pasando (los selectors por etiqueta no deben romder; el pase de iconos es decorativo `aria-hidden`).
- **Dependencias**: +`bootstrap-icons` (npm, MIT). Sin cambio de API, de base de datos ni de comportamiento funcional.
