# Cambio: web-chat-ux

## Why

Dos problemas de la ventana de chat de la web, verificados en el
despliegue compose (8011):

1. **El chat no aplica el modo oscuro.** El área de mensajes (`#chat-box`)
   usa `bg-white` — un color literal de framework que `--bs-white` nunca
   incluyó en la tabla de tokens de `taap-visual-identity` — así que con el
   tema oscuro la ventana del chat se queda blanca sobre la página oscura,
   tanto antes como después de recargar. Las `.bubble-*` no tienen estilos
   y heredan ese blanco.
2. **Las horas van una hora desplazadas.** `APP_TIMEZONE=UTC` fijo en el
   orquestador: `Chat.php` formatea el instante de cada mensaje en UTC
   (`H:i` / `H:i:s`) y el blade lo pinta como texto plano; la línea de
   tiempo del detalle, igual. Un visor en UTC+1 (España) ve 22:56 para un
   mensaje enviado a las 23:56 locales. iOS no muestra horas
   (`createdAt` no se renderiza): el desfase es solo web.

## What changes

- **brand-identity**: el chat usa superficies de la tabla de tokens
  (`bg-body`: slate-50 en claro, slate-950 en oscuro) y las burbujas
  adoptan colores de marca con contraste AA en ambos temas; el requisito de
  tokens alcanza también las utilidades de color literal del framework.
- **agent-chat**: el servidor transmite el instante en ISO 8601 absoluto y
  el cliente lo formatea en la zona horaria del dispositivo
  (`toLocaleTimeString` / `Intl.DateTimeFormat`) en las burbujas y en la
  línea de tiempo: ningún visor ve la hora horneada en el servidor.

Cambio solo web (orquestador). No toca la API (el campo `at` es interno a
la vista), ni iOS, ni el modo oscuro en sí (ya existe).

## Impact

- **Affected specs**: `brand-identity` (MODIFIED: tokens y modo oscuro),
  `agent-chat` (ADDED: horas locales del visor)
- **Affected code**: `resources/views/livewire/agents/chat.blade.php`,
  `resources/views/livewire/agents/detail.blade.php`,
  `app/Livewire/Agents/Chat.php`, `resources/css/app.css`
- **Usuario final**: chat legible en modo oscuro y horas correctas en
  cualquier zona horaria, sin tocar el orquestador (su `TZ` sigue siendo
  UTC).
