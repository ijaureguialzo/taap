# Tasks

## 1. Layout: chat al viewport

- [x] 1.1 En `orchestrator/resources/css/app.css`: cadena flex de la página de detalle acotada a `≥992px` (`body:has(.agent-detail)` con altura fija 100vh/100dvh y `overflow: hidden`; `main` sin scroll; `.agent-detail` flex-column; `.row`/columnas con `min-height: 0`; columna lateral con `overflow-y: auto`) y verificar en navegador: la página no hace scroll, el historial scrollea internamente y el campo de entrada permanece visible (ventanas de 1280×577 y ~700×500)
- [x] 1.2 Retirar los mínimos de altura inline que rompen el flex (`#chat-box` `min-height: 320px` en `chat.blade.php`; `style="min-height: 480px"` de la etiqueta `<livewire:agents.chat>` en `detail.blade.php`) y dejar un `min-height` moderado sobre `.agent-chat`; verificar que en viewport corto el campo de entrada no queda cortado

## 2. Envío: estados y autolarranque

- [x] 2.1 Añadir `App\Enums\AgentStatus::acceptsMessages()` (todo estado salvo `WaitingApproval`) con test unitario de la tabla de estados
- [x] 2.2 En `ChatService::send()`: si el agente no está en ejecución, arrancarlo vía `AgentManager::start()` antes de persistir/encolar; mantener el bloqueo en `WaitingApproval`; test feature: envío a agente detenido → estado `Starting` + mensaje persistido + job de bridge encolado; envío en espera de aprobación → `QuotaExceededException` y sin persistir
- [x] 2.3 En `chat.blade.php`: campo y botón deshabilitados solo por `!acceptsMessages()` (botón además por contenido vacío) y avisos `form-text` por estado («se arrancará al enviar» / «espera tu aprobación») en `lang/{es,en,eu}/chat.php`; verificar en navegador los tres estados (idle, stopped, waiting-approval)
- [x] 2.4 En `TaapHermesTurnCommand`: antes de llamar al adapter, esperar (re-lectura del estado, hasta ~60 s) a que el agente salga de `Starting` para que un turno lanzado por autolarranque no pierda el mensaje

## 3. Enviar con Enter y feedback

- [x] 3.1 En `Chat::send()`: dispatch `message-sent` solo si el envío tuvo éxito; en el script de `chat.blade.php`, al recibirlo vaciar el campo, reenfocarlo y desplazar el historial al final; verificar en navegador: Enter → mensaje enviado, campo vacío, sin texto fantasma, botón coherente

## 4. Eventos en vivo al cliente web

- [x] 4.1 En `chat.blade.php` y `detail.blade.php`: suscribirse con `TaapEcho.channel('agent.{id}')` en lugar de `.private(...)` (mismo canal que el difusor y la app iOS); en `layouts/app.blade.php` corregir `authEndpoint` a `url('/api/v1/broadcasting/auth')`; verificar en navegador: la respuesta del agente sustituye a la burbuja «escribiendo…» sin recargar (Reverb conectado)

## 5. Verificación final

- [x] 5.1 `php artisan test` y `./vendor/bin/pint --test` en el orquestador: suite en verde
- [x] 5.2 E2E en navegador sobre el stack docker: scroll de viewport con conversación larga; botón por estado; envío con Enter; envío a agente detenido (autolarranque); ciclo de aprobación; respuesta en vivo sin recargar (verificado: viewport, Enter, botón, autolarranque `working`→respuesta→`idle`, respuesta en vivo sin recargar; el ciclo de aprobación queda cubierto por `ChatSendTest` + los estados de deshabilitación, sin agente en espera de aprobación disponible en el entorno de test)
## 6. Robustez del botón de envío (reportado: "primera vez no, tras recargar sí")

- [x] 6.1 El estado del botón y el envío (Enter/click) pasan a gestionarse por **delegación en `document`** en el script de `chat.blade.php` (los nodos `input`/`botón` pueden ser sustituidos por el morph de Livewire v4 al re-renderizar `@disabled`: un listener unido al nodo original moría en silencio). El botón se activa **inmediatamente** al teclear (0 ms) sin depender del round-trip de `wire:model` (debounce 400ms), y el hook `morph.updated` + `livewire:initialized` re-aplican el estado tras cada update
- [x] 6.2 `Chat::send(?string $content = null)`: acepta el contenido explícito del cliente para que pulsar Enter justo tras teclear no pierda el mensaje por el debounce; se retiran `wire:click`/`wire:keydown.enter` del template (una sola vía de envío, sin dobles)
- [x] 6.3 Verificado E2E: visita nueva con el agente `working` (canal emitiendo) → 8/8 pulsaciones activan el botón; Enter envía, limpia y re-deshabilita; `php artisan test` y pint en verde
