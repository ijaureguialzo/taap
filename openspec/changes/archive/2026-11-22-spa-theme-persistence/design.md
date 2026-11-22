# Design

## Context

La web TAAP es Laravel + Livewire 4 (bundle `livewire.js` empaquetado, que
incluye su propio Alpine con el plugin `navigate`) + Bootstrap 5.3 + Alpine.
El tema se resuelve hoy en un único sitio: un script inline en el `<head>` de
los layouts (`app` y `auth`) que lee `localStorage['taap-theme']` →
`matchMedia` → `light` y aplica `data-bs-theme` en `<html>` **antes del primer
pintado**. El `<html>` que renderiza el servidor solo tiene `lang`.

Los enlaces internos usan `wire:navigate` (listas de agentes, botones
«volver»). Al navegar, Livewire 4 ejecuta `swapCurrentPageWithNewHtml()`
(`vendor/livewire/livewire/dist/livewire.js`), que:

1. `replaceHtmlAttributes(newHtml)`: copia los atributos del `<html>` recibido
   al vivo y **elimina** los que el recibido no tenga → `data-bs-theme`
   (solo existe en cliente) desaparece.
2. `mergeNewHead(newHead)`: el `<script>` de tema del head recibido es
   byte-idéntico al que ya existe → se descarta la copia; el nodo original se
   queda en el DOM **sin re-ejecutarse** (un script ya insertado no corre
   de nuevo).
3. `document.body.replaceWith(newBody)`: el nuevo body pinta con los tokens
   `:root` (claros) porque el atributo ya no está.

Reproducido en vivo: `/agents` (dark) → click → `/agents/5` con
`attr=null`, body slate-50 y chatbox blanco; F5 restaura el dark.
Secundario: `initThemeSwitcher()` (`app.js`) unió `addEventListener` directo
en los botones; el módulo del head no se re-ejecuta en el swap (idéntico →
dedupe) y los nodos viejos se descartan → tras navegar, los botones (y el
dropdown `data-bs-toggle` de Bootstrap, inicializado en DOMContentLoaded) no
responden hasta un reload.

## Goals / Non-Goals

**Goals**
- El tema vigente sobrevive a cualquier navegación client-side sin parpadeo.
- El control de tema (y su menú) sigue siendo usable tras navegar.
- Sin flash ni cambios en el modelo de tres estados ni en los tokens.

**Non-Goals**
- Compartir el tema entre dispositivos (sigue siendo por dispositivo).
- Re-escanear todos los componentes de Bootstrap tras navegar: solo los
  toggles que el control de tema necesita (dropdown del perfil, collapse del
  navbar móvil).
- Tocar iOS (el tema nativo no depende del DOM).

## Decisions

### D1 — El servidor renderiza el tema: cookie `taap-theme` → atributo en `<html>`
Los layouts (`app` y `auth`) emiten `data-bs-theme` en `<html>` cuando la
cookie `taap-theme` contiene `light` o `dark`. Con el atributo presente en el
HTML recibido, `replaceHtmlAttributes()` lo **preserva** (lo copia
nuevo→actual; solo borra lo ausente) y el bug de raíz desaparece sin depender
de que el script del head se re-ejecute.

- *Alternativa A (re-aplicar en `livewire:navigated`)*: el swap ya ha pintado
  el body en claro antes de que dispare el evento → flash en cada
  navegación; el contrato de brand-identity exige sin parpadeo. Se conserva
  como red de seguridad (D3), no como mecanismo principal.
- *Alternativa C (forzar re-ejecución del script variando su hash, p. ej. un
  atributo aleatorio)*: explota un detalle de `mergeNewHead` (el hash ignora
  `nonce`/`data-csrf` pero no otros atributos); frágil ante upgrades de
  Livewire. Descartada.
- *¿Por qué cookie y no sesión?* La elección ya es por dispositivo
  (localStorage). La cookie viaja sola en el fetch de `wire:navigate`, no
  exige estado de servidor (funciona también en páginas de guest) y es
  trivialmente legible/escrivable por el cliente. No es un secreto.

### D2 — localStorage sigue siendo la fuente de verdad; la cookie es su espejo
- El script inline del head (contrato existente: resolver antes del pintado)
  pasa a **además** escribir `taap-theme=<tema resuelto>` en la cookie
  (`path=/`, `max-age` de un año). Así:
  - usuario con elección explícita → espejo = su elección;
  - usuario en automático (sin elección) → el espejo sale de la **primera
    carga** (el sistema) → su primera navegación ya es segura.
- En cada carga completa el script re-resuelve y re-espeja: la derivación
  cookie↔localStorage se **autocorrige** (si el usuario borra una de las dos,
  la siguiente carga completa las alinea desde localStorage→sistema).
- El conmutador, al cambiar de estado, actualiza las tres salidas a la vez:
  atributo vivo (sin flash), `localStorage` (o lo borra en `auto`) y cookie.

### D3 — El control de tema sobrevive al swap: delegación + re-escaneo de toggles
- Los clicks de `[data-taap-theme-set]` se unen **una sola vez en
  `document`** (delegación): el `document` no se intercambia en
  `wire:navigate`, el body sí. El handler resuelve el estado, aplica y
  persiste (D2) y actualiza los `aria-pressed` consultando el DOM actual.
- En el listener ya existente `livewire:navigated` (hoy `initEcho`):
  - re-aplicar el tema resuelto (red de seguridad para cualquier desvío
    cookie/localStorage o cambio futuro de Livewire);
  - re-inicializar en el body nuevo los elementos `data-bs-toggle`
    (`dropdown`/`collapse`) que no tengan instancia (`bootstrap.*.
    getOrCreateInstance`), de modo que el menú del perfil y el collapse del
    navbar vuelvan a abrirse tras navegar.

### D4 — El contrato observable se fija con un test de feature
El detalle frágile vive en el bundle de Livewire (dedupe de head, sync de
atributos); el contrato que nos importa es observable y simple: **con la
cookie presente, el HTML servido lleva `data-bs-theme`; sin ella, no**.
Test de feature sobre una ruta web (p. ej. `/agents` autenticado): con
`withCookie('taap-theme','dark')` el `<html>` renderiza el atributo; sin
cookie, no lo renderiza. La verificación de navegador (navegar → dark sin
flash; conmutar tras navegar) queda como tarea de verificación en vivo.

## Risks / Trade-offs

- **Dependencia de la implementación de `wire:navigate`**: mitigada por
  diseño — el mecanismo principal (D1) hace que el atributo sobreviva *a
  pesar* del sync, y D3 redondea; si Livewire cambia de comportamiento, el
  test de D4 sigue pasando y la red de seguridad re-aplica.
- **Deriva cookie/localStorage** (el usuario limpia una de las dos): se
  autocorrige en la siguiente carga completa (D2); en el intervalo, la carga
  completa pinta según localStorage→sistema, que es la fuente de verdad.
- **La cookie es visible por el cliente**: solo contiene `light`/`dark` —
  preferencia estética, no dato sensible. No `HttpOnly` (el JS debe leerla).
- **Re-escaneo de toggles tras navegar**: es un re-escopio acotado
  (`data-bs-toggle` sin instancia en el body nuevo); si más adelante se
  añade un componente Bootstrap con toggle propio, se beneficia también.
  Limitación residual: componentes inicializados por otro mecanismo (p. ej.
  tooltips) seguirían sin re-aparecer tras navegar — no bloquea este cambio.
