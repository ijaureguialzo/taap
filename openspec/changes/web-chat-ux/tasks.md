# Tareas: web-chat-ux

## 1. Superficies del chat (D1)

- [ ] 1.1 `chat.blade.php`: `#chat-box` `bg-white` → `bg-body` (slate-50/slate-950 según tema)
- [ ] 1.2 `app.css`: estilos de `.bubble-user` (aqua-600 + texto blanco) y `.bubble-agent` (slate-100/slate-800 + texto estándar)
- [ ] 1.3 Pase visual programático (ambos temas): caja del chat y burbujas sin fugas de blanco/Bootstrap; AA del texto de las burbujas

## 2. Horas locales del visor (D2)

- [ ] 2.1 `Chat.php`: `at` emite ISO 8601 absoluto en history y en el mensaje final (placeholder de streaming: sin hora horneada en el servidor)
- [ ] 2.2 `chat.blade.php`: label con `data-time` (ISO) + formateo en cliente (`toLocaleTimeString` H:mm) aplicado en el setup y en los morphs de Livewire
- [ ] 2.3 `detail.blade.php`: ventanas de la línea de tiempo con `start`/`end` en ISO + formateo en cliente (`Intl.DateTimeFormat` d/m H:i, zona local)
- [ ] 2.4 Verificación en navegador: con un instante UTC conocido, el visor muestra su hora local en burbujas y en la línea de tiempo (claro y oscuro)

## 3. Verificación integral

- [ ] 3.1 Suite PHP: sin fallos nuevos vs. la línea base
- [ ] 3.2 En vivo 8010 y 8011: chat en modo oscuro con superficies temáticas y horas correctas; sin regresión en el resto de la página de detalle
