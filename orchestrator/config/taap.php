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

    'agents' => [
        /* Cuota por defecto de agentes EN EJECUCION por usuario (administrable). */
        'default_quota' => (int) env('TAAP_AGENT_QUOTA', 3),

        /* Redes Docker (ver deploy/compose.yaml). */
        'network_internal' => env('TAAP_NETWORK_INTERNAL', 'taap-internal'),
        'network_egress' => env('TAAP_NETWORK_EGRESS', 'taap-egress'),

        /* Rango para IPs fijas por agente (fragmento ACL del proxy squid). */
        'ip_subnet' => env('TAAP_AGENT_SUBNET', '172.30.0.0/24'),

        /* Proxy de egress (solo para agentes con internet_enabled). */
        'egress_proxy' => env('TAAP_EGRESS_PROXY', 'http://taap-egress:3128'),
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
    |   rpc-stdio  -> el orquestador se conecta al stdio del proceso
    |                 principal del contenedor (docker attach) y habla el
    |                 protocolo nativo (JSONL para Pi).
    |   api-server -> el orquestador habla HTTP/SSE con el servidor API del
    |                 runtime dentro del contenedor (Hermes :8642).
    */
    'runtimes' => [
        'pi' => [
            'label' => 'Pi',
            'image' => env('TAAP_PI_IMAGE', 'taap/pi:local'),
            'mode' => 'rpc-stdio',
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
            'api_port' => 8642,
            'api_base' => '/v1',
            'command' => ['gateway', 'run'],
            'volumes' => [
                'data' => '/opt/data',
            ],
            'config_files' => ['.env', 'config.yaml'],
        ],
    ],

    /* Model Proxy: los agentes llaman al orquestador con su token de agente;
       las claves reales de proveedor solo viven en el orquestador. */
    'proxy' => [
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

    /* Fichero de ACLs del proxy squid, gestionado por el orquestador. */
    'egress_acl_file' => env('TAAP_EGRESS_ACL_FILE', '/var/lib/taap/squid/taap-acl.conf'),
];
