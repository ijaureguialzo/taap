# Proposal

## Why

El modo oscuro web se rompe con la navegación interna: al hacer click en una
página enlazada con `wire:navigate` (p. ej. una fila de la lista de agentes),
Livewire 4 intercambia el DOM sin re-parsear el documento — y su sincronización
de atributos **borra** el `data-bs-theme` del `<html>` vivo (es un atributo
puramente cliente: el HTML del servidor solo tiene `lang`) mientras el script
inline del `<head>` no se re-ejecuta (el bundle lo deduplica por ser idéntico).
Resultado reproducido en producción: tras el click la página pinta clara
(`attr=null`, body slate-50, chatbox blanco) hasta que el usuario refresca.
Un segundo síntoma latente de la misma causa: los listeners del conmutador de
tema se unieron a nodos que el swap descarta, así que el control muere hasta
el siguiente reload.

## What Changes

- Los layouts web (`app` y `auth`) renderizan `data-bs-theme` en `<html>` a
  partir de una cookie pequeña (`taap-theme`) que espeja la elección del
  cliente: el HTML servido lleva el tema y la sincronización de atributos de
  `wire:navigate` lo **preserva** en lugar de borrarlo.
- El script inline del `<head>` mantiene su resolución previa al pintado
  (localStorage → sistema → claro) y, además, espeja el tema resuelto a la
  cookie, de modo que el servidor nunca queda desfasado (incluido el usuario
  nuevo que no eligió: el espejo sale de la primera carga).
- El conmutador de tema (`app.js`) escribe la cookie además de localStorage,
  se une por **delegación** en `document` (sobrevive a los swaps de body) y
  re-aplica el tema en `livewire:navigated` como red de seguridad.
- Sin cambios de API, base de datos ni iOS (el tema nativo no toca el DOM).

## Capabilities

### New Capabilities

(ninguna)

### Modified Capabilities

- `brand-identity`: la spec gana una exigencia nueva («Modo oscuro: persistencia en la navegación interna»): el tema vigente debe conservarse a través de la navegación client-side sin parpadeo, y el control de tema debe seguir siendo usable tras esa navegación.

## Impact

- `orchestrator/resources/views/layouts/app.blade.php` y
  `resources/views/layouts/auth.blade.php` (atributo en `<html>` + espejo de
  cookie en el script inline).
- `orchestrator/resources/js/app.js` (conmutador: cookie, delegación,
  re-aplicación en `navigated`).
- Comportamiento que depende de un detalle de implementación de Livewire 4
  (`replaceHtmlAttributes`/`mergeNewHead` en `wire:navigate`): el diseño fija
  la expectativa con un test de feature sobre el atributo renderizado.
- Sin impacto en la API, la base de datos, el cliente iOS ni los agentes.
