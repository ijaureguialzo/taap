# Design

## Context

Estado actual (verificado): la web es Laravel + Bootstrap 5.3.7 (npm/Vite) + Alpine + Reverb; `app.css` importa Bootstrap y define una única variable (`--taap-bg: #f6f7f9`); la marca es el emoji ⚡ (navbar y auth), el favicon es un cubo 3D rojo `#FF2D20` (SVG 166 px + ICO), hay 3 botones con 🗑, cero usos de iconos de framework, todos los colores son defaults de Bootstrap (`bg-*`/`text-*`) y no existe `data-bs-theme` en ninguna parte. iOS es SwiftUI sobre XcodeGen (`project.yml` → `regenerate.sh`): el pbxproj fija `ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon` **pero no existe ningún `.xcassets`** (el build usa el icono genérico); los colores están hardcodeados (`.indigo`, `.red`, `.green`, `systemGray5`) y no hay asset catalog ni color sets. El diseño web vigente (design.md de `taap-v1`, D8) fija Bootstrap 5 sin Tailwind/Flux — este cambio respeta ese marco.

## Goals / Non-Goals

**Goals**
- Un maestro SVG (el grifo) del que se generan todos los formatos; un comando, sin PNGs hechos a mano.
- Iconos consistentes en todas las vistas web vía un único componente; mapa semántico idéntico en iOS con SF Symbols.
- Paleta de la marca como tokens: un sitio define colores, todas las plataformas las consumen; paridad de semántica web/iOS.
- Dark mode de tres estados con paridad, sin flash en web, usando el mecanismo nativo de Bootstrap 5.3.
- Verificación automática del contraste AA sobre la tabla de tokens.

**Non-Goals**
- PWA/instalable en web (el webmanifest con iconos puede venir después).
- Ajuste per-usuario en base de datos del tema (v1: persistencia por dispositivo; la paridad la da el mismo contrato, no una sincronización).
- Rediseño de layouts: este cambio no mueve columnas ni flujos — solo apariencia (iconos, colores, tema).
- Android (fuera de v1 según `ios-client`).
- Ilustraciones/gráficos (gráficos de actividad, etc.): fuera.

## Decisions

**D1 — El grifo: dos derivados de un maestro, según destino.**
Maestro `assets/brand/taap-faucet.svg` (viewBox 1024, 2 paths planos: silueta + gota; glifo al 62 % del lienzo, margen 19 %). Derivados: (a) favicon = glifo bicolor (silueta `aqua-600`, gota `ink`), fondo transparente — nítido a 16 px sobre cualquier pestaña; (b) app icon = **teja** full-bleed `aqua-600` con glifo blanco y gota `aqua-200` — presencia en Home; (c) wordmark inline en navbar/auth (`currentColor`). Alternativa descartada: un solo estilo para todo — el glfico transparente queda débil como icono de app y la teja opaca ensucia la pestaña de 16 px.
El maestro se dibuja a mano en el repo (2 paths, geometría redondeada simple: cuerpo + boquilla descendente + percha + gota); sin dependencias de diseño externas.

**D2 — Bootstrap Icons por npm, set completo, servido por Vite.**
`@import 'bootstrap-icons/font/bootstrap-icons.css'` en `app.css`; el woff2 (~130 KB) se sirve empaquetado (sin CDN, criterio D8 de `taap-v1` + sin red en la red local). Alternativa: tree-shakear SVGs sueltos — más ligero pero frágil (cada icono nuevo es un asset a gestionar) y el set completo es trivial a este tamaño. Componente `<x-icon name="..." size="sm|md|lg">` → `<i class="bi bi-{name}" aria-hidden="true">` (o con `<span class="sr-only">` si es funcional): un único punto de cambio de set y de convención.

**D3 — Tokens: una tabla, dos empaquetados.**
La tabla canónica vive en `app.css` como custom properties: `--taap-aqua-50…950`, `--taap-ink`, `--taap-slate-50…950`, `--taap-state-{success,warning,danger,info}` (+variantes `*-dark`). La web las inyecta reescribiendo las variables de Bootstrap (`--bs-primary: var(--taap-aqua-500)`, `--bs-body-bg`, `--bs-tertiary-bg`, `--bs-border-color`, `--bs-{success,warning,danger,info}`, etc.) — con eso **todos** los componentes (botones, cards, nav, forms, badges, dropdowns, alertas) adoptan paleta y dark sin tocar vistas. iOS: `Taap/Assets.xcassets` con los mismos valores como color sets (Any + Dark) y accessors `TaapColor.primary/.surface/.textSecondary/...`. Alternativa: generar ambas desde un JSON de tokens con un script — elegante pero añade una herramienta con un consumidor por plataforma; con 2 empaquetados, la tabla CSS es la canónica y los color sets se mantienen a mano (pequeños).
Escala primaria `aqua`: 50 `#EEFBF9` → 950 `#0A2B2A` (500 `#299A94` es la marca; en dark se usan los pasos 300–400 para texto/trazos). Neutros `slate`: grises fríos 50 `#F6F8F9` (sustituye `--taap-bg`) → 950 `#10161B` (fondo dark; **no negro puro**). Ink `#125E7A` (gota + info). Estado: success `#188A4C`/dark `#34C97B`, warning `#B45309`/`#E8A33D`, danger `#C2333B`/`#F06A6A`.

**D4 — Dark mode web: `data-bs-theme` + preferencia local + media query.**
`<html data-bs-theme="…">`; resolución: (1) `localStorage['taap-theme']` del dispositivo, (2) `matchMedia('(prefers-color-scheme: dark)')`, (3) claro. Script inline de 6 líneas en el `<head>` (antes de la hoja de estilos) para que el primer pintado sea ya del tema correcto (sin flash); Alpine monta el conmutador (dropdown del perfil: ☀︎/🌙/auto con los iconos `bi-sun`/`bi-moon`/`bi-circle-half`) y lo persiste. Bootstrap 5.3 redefine sus `--bs-*` bajo `[data-bs-theme=dark]`; nosotros los sobreescribimos después con la escala slate oscura (los dark defaults de Bootstrap son neutros cálidos; queremos los fríos de D3).

**D5 — Dark mode iOS: color sets con variante dark + `preferredColorScheme`.**
Los color sets declaran Any + Dark (el sistema aplica la variante sola); el conmutador (misma semántica de 3 estados, mismo orden de resolución, `@AppStorage("taap-theme")`) aplica `.preferredColorScheme(nil/.light/.dark)` desde la raíz para forzar o liberar. Sin asset catalog no hay nada que pintar en dark: este change lo crea.
Pase de colores: `.indigo`/`.red`/`.green`/`systemGray5` → `TaapColor.*`; el mapa semántico (estado → color) es idéntico al de web (D3).

**D6 — Mapa de iconos: una tabla en el design, aplicación mecánica.**
Nav: `robot`/`box-seam`/`cloud-arrow-down`/`plug`/`people`. Badges: admin `patch-check`, user `person-bounding`, runtime `cpu`. Estados de agente: idle `pause-circle`, working `lightning-charge`, waiting-approval `hourglass-split`, error `exclamation-octagon` (el icono acompaña al badge de estado en web e iOS, semántica igual). Acciones: nuevo `plus-circle`, borrar `trash3` (sustituye 🗑), editar `pencil`, config `gear`, check proveedor `wifi`/`wifi-off` + `clock-history`. Chat: `send-fill`, `x-octagon` (parar), `check2-square`/`x-square` (aprobación), `clock-history` (historial). Auth: `envelope`/`lock`/`fingerprint`. Vacíos: `robot`/`cloud-slash` grandes. iOS: el mismo mapa con sus SF Symbols equivalentes (`gearshape`, `trash`, `play.circle`, `exclamationmark.octagon`, `paperplane.fill`, …).

**D7 — AppIcon: crear el asset catalog que el build ya espera.**
`Taap/Assets.xcassets/AppIcon.appiconset` (16 PNGs de la teja D1: 20/29/40/60/76/83.5/120/152/167.25/180 @1/2/3) + `Contents.json`; `project.yml` no necesita cambios (la carpeta `Taap` ya es `sources`) y `regenerate.sh` lo recoge. Esto corrige un hueco existente: el build actual produce la app sin icono propio.

**D8 — Contraste AA verificado por test.**
La tabla de tokens declara explícitamente los pares texto-superficie (ej. `text-on-aqua-600`, `text-primary/surface`, `text-secondary/surface-elevated`, estado sobre superficie, estado sobre dark). Test PHP (web: parsea `app.css` extrayendo las custom properties y calcula ratios luminancia relativa WCAG) y test iOS (mismo cálculo sobre la tabla de valores) — ambos fallan si algún par < 4.5:1. No se auditan colores generados por Bootstrap a ojo; se audita el contrato de tokens.

## Risks / Trade-offs

- [El glifo a 16 px pierde la gota] → Se verifica el render del favicon a 16/24/32 px sobre fondos claros y oscuros antes de darlo por bueno; el maestro lleva una variante simplificada (solo silueta) si hiciera falta.
- [Sobrescribir `--bs-*` puede romper matices de componentes] → Pase visual completo de las 7 áreas (agentes, chat, modelos, proveedores, mcp, usuarios, auth) en claro y oscuro; el dark de Bootstrap 5.3 ya es robusto, solo cambiamos neutros y primario.
- [El pase de iconos toca muchas vistas a la vez] → El componente `<x-icon>` aísla la convención; el pase es decorativo (`aria-hidden`) y los tests de UI existentes (que buscan por etiquetas/textos) no deben verse afectados — se ejecutan antes y después.
- [Manejar la paleta a mano en 2 empaquetados (CSS + color sets)] → Riesgo de drift → el test de contraste replica la misma tabla numérica en ambos lados; un drift de valor salta en el test iOS.
- [Icono de app que hoy no existe → el asset catalog nuevo debe ser válido o el build falla] → `actool`/build de verificación con el `Contents.json` antes de pasar `regenerate.sh`.

## Migration Plan

1. Todo el trabajo es aditivo en apariencia: se despliega el backend sin cambios funcionales; la web nueva se sirve desde el build de Vite.
2. Favicons: el `.ico` anterior se sustituye; los navegadores con caché fuerte ven el nuevo al renovarse la caché (no hay acción de usuario; es cosmético y no hay que migrar nada).
3. Tema: sin fila en BD; cada dispositivo decide solo (localStorage/UserDefaults) — sin migración de datos.
4. Rollback: revertir el commit de marca/paleta restaura emoji+cubo+defaults de Bootstrap (los valores antiguos siguen en la historia de git); el asset catalog nuevo se puede eliminar sin tocar código.

## Open Questions

- Ninguno bloqueante: la escala exacta de la paleta (valores de D3) se afina durante el pase visual, sin cambiar el contrato de tokens; el ajuste final de la geometría del grifo es de taller (el maestro SVG), no de spec.
