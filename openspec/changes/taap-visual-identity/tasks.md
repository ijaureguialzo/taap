# Tasks

## 1. Marca: el grifo (design D1)

- [ ] 1.1 Dibujar el maestro `orchestrator/assets/brand/taap-faucet.svg`: 2 paths planos (silueta del grifo + gota), viewBox 1024, glifo al 62 %, sin degradados. Verificar: el SVG se abre en el navegador y el glifo se lee como grifo a 16 y 64 px.
- [ ] 1.2 Escribir el generador `orchestrator/assets/brand/generate.mjs` (usa `rsvg-convert`): del maestro rinde `public/favicon.svg`, `public/favicon.ico` (16/32/48), `public/apple-touch-icon.png` (180) y `mobile/ios/Taap/Assets.xcassets/AppIcon.appiconset/*` (16 PNGs de la teja: fondo `aqua-600`, glifo blanco, gota `aqua-200`, 20/29/40/60/76/83.5/120/152/167.25/180). Verificar: `node generate.mjs` crea todos los archivos y `file`/`sips` confirman tamaños; el build iOS compila con el AppIcon.
- [ ] 1.3 Sustituir los favicons existentes (`public/favicon.ico` y `favicon.svg` del cubo rojo) por los generados y enlazar `apple-touch-icon` en el layout. Verificar: `php artisan serve` + abrir una página en el navegador muestra el grifo en la pestaña; la web no referencia el cubo rojo en ninguna parte.

## 2. Web: paleta de tokens y dark mode (design D3, D4, D8)

- [ ] 2.1 Definir la tabla de tokens en `orchestrator/resources/css/app.css`: escalas `--taap-aqua-*`, `--taap-slate-*`, `--taap-ink` y estado (claro + variantes dark), y reescribir las variables `--bs-*` (primario, cuerpo, terciario, bordes, estado) en `:root` y bajo `[data-bs-theme=dark]`, sustituyendo `--taap-bg`. Verificar: `npm run build` sin errores y la app se pinta con el teal de la marca en botones/badges sin tocar ninguna vista.
- [ ] 2.2 Script inline en `layouts/app.blade.php` y `layouts/auth.blade.php` que fija `<html data-bs-theme>` antes del primer pintado (localStorage → matchMedia → claro) y el conmutador de 3 estados (auto/claro/oscuro) en el dropdown del perfil, persistido. Verificar: elegir oscuro + recargar → la página pinta oscura sin flash (probar con `curl` + navegador; el atributo existe en el HTML servido sin JS).
- [ ] 2.3 Pase visual de las 7 áreas (agentes, chat, modelos, proveedores, mcp, usuarios, auth) en modo claro y oscuro: superficies slate frías, contraste legible, sin colores default de Bootstrap destonando. Verificar: recorrido manual documentado con capturas de cada área en ambos modos.

## 3. Web: sistema de iconos (design D2, D6)

- [ ] 3.1 Añadir `bootstrap-icons` a `package.json`, importarlo en `app.css` y crear el componente Blade `orchestrator/resources/views/components/icon.blade.php` (`<x-icon name="…">` → `<i class="bi bi-…" aria-hidden="true">`, con variante funcional `<span class="sr-only">`). Verificar: `npm run build` empaqueta el font woff2 local (sin CDN en el HTML) y el componente renderiza.
- [ ] 3.2 Pase de iconos del mapa D6 en todas las vistas: nav (5 enlaces), badges (admin/user/runtime), estados de agente, botones (borrar `trash3` sustituyendo 🗑 en providers/mcp/users, nuevo, editar, check proveedor), chat (enviar/parar/aprobar/historial), auth (envelope/lock/fingerprint) y estados vacíos. Verificar: `grep -r "🗑\|⚡" resources/views/` devuelve 0; tests de feature/Unit existentes en verde.
- [ ] 3.3 Marca en la interfaz: glifo inline del grifo (SVG `currentColor`) + texto TAAP en el navbar y en el auth layout, sustituyendo los ⚡. Verificar: navbar y pantalla de login muestran la marca; los tests de UI web existentes no se rompen.

## 4. iOS: asset catalog y tokens (design D3, D5, D7)

- [ ] 4.1 Crear `mobile/ios/Taap/Assets.xcassets` con los color sets de la paleta (primary aqua 500/400-dark, ink, surface/surfaceElevated, textPrimary/textSecondary, success/warning/danger con variantes Any+Dark) y los 16 PNGs del AppIcon (1.2) + `Contents.json`; pasar `regenerate.sh`. Verificar: `xcodebuild build` exitoso y el .app resultante lleva el icono del grifo (inspeccionar `Assets_carousel`/ícono del simulador).
- [ ] 4.2 Accessors `TaapColor` (Swift) sobre los color sets y pase de sustitución de los colores hardcodeados (`.indigo`, `.red`, `.green`, `systemGray5` en `AgentDetailView`/`AgentListView`/resto) por los tokens, con el mismo mapa semántico que la web. Verificar: `grep -rn "Color.indigo\|Color.red\|Color.green\|systemGray" Taap/Views/` devuelve 0 (salvo usos deliberados y anotados) y el test unitario de iOS pasa.

## 5. iOS: modo oscuro (design D5)

- [ ] 5.1 Conmutador de tema de 3 estados (auto/claro/oscuro) en Ajustes: `@AppStorage("taap-theme")` con la misma semántica de resolución que la web, aplicando `.preferredColorScheme(…)` desde la raíz. Verificar: elegir oscuro → todas las pantallas (lista, chat, ajustes) usan las variantes dark del asset catalog; la elección persiste al relanzar; con auto sigue el sistema.
- [ ] 5.2 Test unitario iOS de la persistencia/resolución del tema (3 estados, orden de resolución). Verificar: `xcodebuild test -only-testing:TaapUnitTests` en verde.

## 6. Contraste AA y verificación integral (design D8)

- [ ] 6.1 Test PHP de contraste: extraer la tabla de tokens de `app.css` (pares texto-superficie declarados, claro + dark) y fallar si algún par < 4.5:1 (luminancia relativa WCAG). Verificar: `php artisan test --filter=Contrast` en verde; mutar un valor a propósito → el test falla señalando el par.
- [ ] 6.2 Verificación integral: suite PHP completa + suite unitaria iOS + tests de UI iOS existentes, con la app nueva y el orquestador corriendo. Verificar: sin fallos nuevos respecto a la línea base (los 10 errores `$servePort` de integración y el `test_loginFlow` que exige compose en 8011 son ruidos de entorno documentados, no fallos de este cambio).
