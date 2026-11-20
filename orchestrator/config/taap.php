<?php

/*
|--------------------------------------------------------------------------
| TAAP - configuracion del orquestador
|--------------------------------------------------------------------------
| Registros de runtime, redes, cuotas y endpoints internos. El resto de la
| configuracion (base de datos, reverb, sanctum...) usa los ficheros
| estandar de Laravel.
*/

return [

    /* Idiomas soportados (el preferido por usuario se guarda en users.locale). */
    'locales' => ['es', 'en', 'eu'],

    // Sin preferencia de usuario ni Accept-Language: se usa este (spec
    // localizacion: "default: locale del navegador soportado, si no es").
    'default_locale' => env('TAAP_DEFAULT_LOCALE', 'es'),

    /*
    | Datos publicos para que los clientes (web/iOS) abran el canal WSS.
    | Son la cara externa del Reverb interno (TAAP_PUBLIC_*).
    */
    'public' => [
        'host' => env('TAAP_PUBLIC_HOST', env('REVERB_HOST', '127.0.0.1')),
        'port' => (int) env('TAAP_REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
        'scheme' => env('TAAP_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
    ],

    /* Solo seed (php artisan db:seed); no usarse en codigo de produccion. */
    'seed' => [
        'qwen_base_url' => env('TAAP_QWEN_BASE_URL', 'http://127.0.0.1:8000/v1'),
    ],

    'agents' => [
        /* Cuota por defecto de agentes EN EJECUCION por usuario (administrable). */
        'default_quota' => (int) env('TAAP_AGENT_QUOTA', 3),

        /* Redes Docker (ver deploy/compose.yaml). */
        'network_internal' => env('TAAP_NETWORK_INTERNAL', 'taap-internal'),
        'network_egress' => env('TAAP_NETWORK_EGRESS', 'taap-egress'),

        /* Rango para IPs fijas por agente (fragmento ACL del proxy squid). */
        'ip_subnet' => env('TAAP_AGENT_SUBNET', '172.30.0.0/24'),

        /* Proxy de egress (solo para agentes con internet_enabled). */
        'egress_proxy' => env('TAAP_EGRESS_PROXY', 'http://10.163.3.2:3128'),
        /* URL con la que el orquestador se alcanza desde el host/contenedores. */
        'orchestrator_url' => env('TAAP_ORCHESTRATOR_URL', 'http://taap-orchestrator:8000'),

        /* Raiz de nombres de contenedores y volúmenes. */
        'container_prefix' => 'taap-agent-',

        /* Raiz de areas persistentes por agente (data/workspace/config). */
        'data_base' => env('TAAP_AGENT_DATA_BASE', '/var/lib/taap/agents'),
        'data_host_base' => env('TAAP_AGENT_DATA_HOST', env('TAAP_AGENT_DATA_BASE', '/var/lib/taap/agents')),

        /* Reintentos de arranque antes de marcar error. */
        'start_retries' => 3,
        /* Area del host para el IPC de archivos con los agentes (RPC Pi). */
        'rpc_base' => env('TAAP_RPC_BASE', '/tmp/taap-rpc'),
    ],

    /*
    | Registros de runtime. Cada entrada tiene TODAS las piezas necesarias
    | para soportar ese runtime; añadir v1.1/v1.2 = añadir una entrada +
    | su adaptador (ver openspec: design.md D2/D12).
    |
    | mode:
    |   rpc-stdio   -> el orquestador se conecta al stdio del proceso
    |                  principal del contenedor (docker attach) y habla el
    |                  protocolo nativo (JSONL para Pi).
    |   api-server  -> el orquestador habla HTTP/SSE con el servidor API del
    |                  runtime dentro del contenedor (Hermes :8642); los
    |                  eventos viajan dentro de la respuesta (drain sin-op).
    |   api-events  -> como api-server PERO los eventos llegan por un stream
    |                  SSE persistente (`/event`) que un coproceso cola en el
    |                  IPC de ficheros; el drain drena ese stream (OpenCode).
    |
    | capabilities (design.md D12): que consume/permite el runtime:
    |   openai|anthropic : formato de API de modelo que consume (Model Proxy)
    |   native|turn      : sesion mantenida por el runtime | por turno HTTP
    |   restart|next-turn: como se aplica un cambio de config
    */
    'runtimes' => [
        'pi' => [
            'label' => 'Pi',
            'image' => env('TAAP_PI_IMAGE', 'taap/pi:local'),
            'mode' => 'rpc-stdio',
            'capabilities' => ['openai', 'native', 'restart'],
            'command' => ['gateway', 'run'], // placeholder; Pi usa el entrypoint de la imagen
            'volumes' => [
                'data' => '/home/agent/.pi/agent',
                'workspace' => '/workspace',
                'config' => '/config',
            ],
            'config_files' => ['agent.env'],
        ],
        'hermes' => [
            'label' => 'Hermes',
            'image' => env('TAAP_HERMES_IMAGE', 'taap/hermes:local'),
            'mode' => 'api-server',
            'capabilities' => ['openai', 'turn', 'restart'],
            'api_port' => 8642,
            'api_base' => '/v1',
            'command' => ['gateway', 'run'],
            'volumes' => [
                'data' => '/opt/data',
            ],
            'config_files' => ['.env', 'config.yaml'],
        ],
        /* Claude Code (add-claude-code-runtime v1.1): el sidecar Node (Claude
           Agent SDK) vive en la imagen agents/claude; habla el protocolo TAAP
           v1 por stdio (como Pi). `next-turn`: los cambios de configuracion
           (modelo, MCP, permisos, max_retries, approval_timeout_seconds) se
           aplican en el SIGUIENTE query sin reiniciar el contenedor (el
           sidecar re-lee /config/claude.json por turno); la sesion Claude
           persiste en /data y se reanuda con `resume` tras cualquier
           reinicio. El plano claude.json apunta al Model Proxy (base sin
           /v1: el CLI llama a /v1/messages y /v1/models). */
        'claude' => [
            'label' => 'Claude Code',
            'image' => env('TAAP_CLAUDE_IMAGE', 'taap/agent-claude:local'),
            'mode' => 'rpc-stdio',
            'capabilities' => ['anthropic', 'native', 'next-turn'],
            'command' => ['sidecar'], // placeholder; Claude usa el entrypoint de la imagen
            'volumes' => [
                'config' => '/config',
                'data' => '/data',
                'workspace' => '/workspace',
            ],
            'config_files' => ['claude.json'],
        ],
        /* Codex (add-codex-runtime v1.1): el binario `codex app-server`
           (Rust, release rust-v0.171.0) corre como PID 1 y habla JSON-RPC
           por stdio (el entrypoint de la imagen monta el FIFO; no hay
           sidecar: la normalizacion vive en el adapter PHP). `native`: la
           sesion es el "thread" del app-server, persistido en
           CODEX_HOME=/data y reanudado con thread/resume. `restart`: la
           config (-c) se lee al arrancar; el modelo se re-sync in situ
           (thread/settings/update) tras cada reanudar. Wire `responses`
           del Model Proxy (el provider custom de codex lo exige: en >=
           0.128 el wire `chat` no existe). */
        'codex' => [
            'label' => 'Codex',
            'image' => env('TAAP_CODEX_IMAGE', 'taap/agent-codex:local'),
            'mode' => 'rpc-stdio',
            'capabilities' => ['openai', 'native', 'restart'],
            'command' => ['app-server'], // placeholder; Codex usa el entrypoint de la imagen
            'volumes' => [
                'config' => '/config',
                'data' => '/data',
                'workspace' => '/workspace',
            ],
            'config_files' => ['codex.env', 'codex-args.env'],
        ],
        /* OpenCode (add-opencode-runtime v1.1): `opencode serve` (1.19.6,
           npm `opencode-ai`) como PID 1. Modos de formato DUAL (openai+
           anthropic): el provider custom `taap` elige el paquete npm según
           el tipo del proveedor y el agente puede usar CUALQUIERA (el filtro
           del selector lo refleja). `api-events`: comandos por HTTP desde el
           orquestador; eventos por el stream SSE `/event` que un coproceso
           cola en el IPC de ficheros. `native`: 1 agente = 1 sesión
           `ses_*` persistida en XDG_DATA_HOME=/data (sobrevive reinicios).
           `restart`: la config (opencode.json) se aplica con re-render +
           reinicio; el modelo se re-sync por API. */
        'opencode' => [
            'label' => 'OpenCode',
            'image' => env('TAAP_OPENCODE_IMAGE', 'taap/agent-opencode:local'),
            'mode' => 'api-events',
            'capabilities' => ['openai', 'anthropic', 'native', 'restart'],
            'api_port' => 4096,
            'command' => ['serve'], // placeholder; OpenCode usa el entrypoint de la imagen
            'volumes' => [
                'config' => '/config',
                'data' => '/data',
                'workspace' => '/workspace',
            ],
            'config_files' => ['opencode.json'],
        ],
    ],

    /* Model Proxy: los agentes llaman al orquestador con su token de agente;
       las claves reales de proveedor solo viven en el orquestador. */
    'proxy' => [
        // Tope de salida por defecto (ver ModelProxyController::mergeReasoning)
        'default_max_tokens' => (int) env('TAAP_PROXY_DEFAULT_MAX_TOKENS', 2048),
        // Modelos de razonamiento: con 'low' Qwen se queda en pensamientos
        // y no invoca tools; con 'high' invoca de forma fiable.
        'default_reasoning_effort' => env('TAAP_PROXY_REASONING_EFFORT', 'high'),
        // Muestreo estable para que la decision de tool-calls no sea moneda al aire.
        'default_temperature' => (float) env('TAAP_PROXY_TEMPERATURE', 0),
        'token_length' => 48,
        /* Como se alcanza el orquestador desde los contenedores de agente. */
        'internal_url' => env('TAAP_PROXY_INTERNAL_URL', 'http://taap-orchestrator:8000'),
    ],

    /* Puerta MCP: el orquestador expone una puerta JSON-RPC por agente y
       reenvía a los servidores MCP registrados (stdio dentro del contenedor
       del agente, o URL con credenciales de TAAP). */
    'mcp' => [
        'route_prefix' => '/mcp/{agent}/bridge',
    ],

    // Timeline (7.2, design D6): sintesis de ventanas de trabajo desde
    // agent_events. "hueco" = separacion minima entre eventos para abrir
    // una ventana nueva.
    'timeline' => [
        'hours' => (int) env('TAAP_TIMELINE_HOURS', 24),
        'gap_minutes' => (int) env('TAAP_TIMELINE_GAP_MINUTES', 15),
    ],

    /* Fichero de ACLs del proxy squid, gestionado por el orquestador. */
    'egress_acl_file' => env('TAAP_EGRESS_ACL_FILE', '/var/lib/taap/squid/taap-acl.conf'),
];
