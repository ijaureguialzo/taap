# Tasks

## 1. El servidor lleva el tema (D1, D2)

- [ ] 1.1 Layouts `app.blade.php` y `auth.blade.php`: `data-bs-theme` en `<html>` cuando la cookie `taap-theme` vale `light` o `dark`
- [ ] 1.2 Script inline del `<head>` (ambos layouts): tras resolver el tema, espejarlo a la cookie `taap-theme` (`path=/`, `max-age` un año)

## 2. El control de tema en cliente (D2, D3)

- [ ] 2.1 `app.js`: el conmutador actualiza cookie además de localStorage y atributo (en `auto` escribe el tema resuelto, no el literal)
- [ ] 2.2 `app.js`: listeners del conmutador por delegación en `document` (sobrevive a los swaps de body de `wire:navigate`), con `aria-pressed` sincronizado contra el DOM actual
- [ ] 2.3 `app.js`: en `livewire:navigated` re-aplicar el tema resuelto y re-inicializar en el body nuevo los `data-bs-toggle` sin instancia (dropdown/collapse)

## 3. Verificación

- [ ] 3.1 Test de feature: con `withCookie('taap-theme', 'dark')` una página web renderiza el atributo en `<html>`; sin cookie no lo renderiza; suite PHP sin fallos nuevos vs. la línea base
- [ ] 3.2 En vivo (8011): con dark guardado, click en la lista de agentes → página destino en dark sin flash; el control de tema responde tras la navegación; igual con elección «claro» y en un layout de guest (login)
- [ ] 3.3 Commit con el trailer de autoría
