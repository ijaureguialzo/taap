# Design: web-chat-ux

## Context

Dos problemas de la ventana de chat web (verificados en el despliegue
8011): el área de mensajes es un `bg-white` literal (blanco en ambos
temas; `--bs-white` nunca entró en la tabla de tokens) y las marcas de
tiempo se formatean en el servidor en UTC (`APP_TIMEZONE=UTC` fijo) y se
pintan como texto plano: un visor en CET ve la hora menos una. iOS no
muestra horas (`ChatMessageDTO.createdAt` no se renderiza): el cambio es
solo web.

## Decisiones

### D1 — Superficies del chat desde la tabla de tokens

- `#chat-box`: `bg-white` → `bg-body` (`var(--bs-body-bg)` = slate-50 en
  claro, slate-950 en oscuro): el chat se hunde respecto a la tarjeta que lo
  contiene, en ambos temas, sin clase nueva.
- Burbujas (hoy sin estilos, sobre el blanco):
  - `bubble-user`: fondo `--taap-aqua-600` (idéntico en ambos temas), texto
    blanco (4.8:1 AA en los dos).
  - `bubble-agent`: fondo `--taap-slate-100` / `--taap-slate-800` (dark),
    texto `--taap-text-*` estándar.
  - El timestamp de la burbuja usa `text-secondary` (temático, ya lo hace).
- No se remapea `--bs-white` en dark: hay un único `bg-white` en la web y
  se sustituye; remapear el blanco global (blanco→oscuro) sorprendería a
  cualquier uso futuro. El pase visual programático (fugas de paleta)
  cubre el resto.

### D2 — El instante viaja absoluto, la hora la decide el dispositivo

- `Chat.php`: `at` deja de ser `format('H:i')`/`now()->format('H:i:s')`
  (UTC horneada) y pasa a emitir ISO 8601 absoluto (`->toISOString()` en
  history y en el mensaje final; el placeholder de streaming emite el
  `now()` local del cliente en el render, no del servidor).
- `chat.blade.php`: el label renderiza el ISO en un `data-time`; un script
  pequeño (el bloque JS existente del chat) lo formatea con
  `Date#toLocaleTimeString` (H:mm) y lo actualiza sobre los morphs de
  Livewire — la hora la calcula siempre el dispositivo del visor.
- `detail.blade.php` (línea de tiempo): los `start`/`end` de las ventanas
  se transmiten en ISO y se formatean en el cliente con el mismo helper
  (`d/m H:i` vía `Intl.DateTimeFormat`, que respeta la zona local).
- Alternativa descartada: `APP_TIMEZONE` por usuario o `TZ` en el
  contenedor — el servidor es compartido; la zona horaria es propiedad del
  dispositivo, y hornar ningún texto de hora en el servidor lo evita a
  todos.

## Riesgos

- Los clientes existentes del payload `at` (solo la propia vista web; la
  API de chat y iOS no consumen `at` en texto) no ven cambio de contrato.
- El formateo en cliente introduce un flash mínimo de ISO→hora en el primer
  render: se formatea antes del paint en el bloque de setup del chat.
