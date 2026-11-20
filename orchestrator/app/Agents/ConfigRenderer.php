<?php

namespace App\Agents;

use App\Enums\AgentRuntime;
use App\Models\Agent;
use App\Models\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Plano de configuración (design.md D3): el orquestador renderiza la
 * configuracion NATIVA de cada runtime en el area persistente del agente
 * (bind mounts bajo /var/lib/taap/agents/<id>/) y la aplica sin perder la
 * sesion. Todo es regenerable desde la base de datos en cualquier momento.
 */
class ConfigRenderer
{
    /** Ruta donde el proceso escribe la config (visible para el orquestador). */
    protected ?string $writeBase = null;

    /** Ruta visible para el demonio Docker (puede ser distinta: binds). */
    protected ?string $bindBase = null;

    public function __construct(
        protected ?string $base = null,
    ) {
        $this->base ??= (string) config('taap.agents.data_base', '/var/lib/taap/agents');
        $this->writeBase = $this->base;
        $this->bindBase = (string) config('taap.agents.data_host_base', $this->base);
    }

    /** Raiz del agente donde se escribe (rutas visibles para el proceso). */
    public function dir(Agent $agent): string
    {
        return $this->writeBase.'/'.$agent->id;
    }

    /**
     * Convierte una ruta escrita en su par de bind `hostDaemon:destino`.
     */
    protected function bind(string $hostDir, string $dest): string
    {
        $rel = ltrim(str_replace($this->writeBase, '', $hostDir), '/');

        return $this->bindBase.'/'.$rel.':'.$dest;
    }

    /**
     * Crea (o regenera) todos los ficheros de configuracion del agente.
     * Devuelve las rutas de bind mount `host:destino` para el contenedor.
     *
     * @return list<string>
     */
    public function render(Agent $agent): array
    {
        $dir = $this->dir($agent);
        File::ensureDirectoryExists($dir.'/config');
        File::ensureDirectoryExists($dir.'/data');
        File::ensureDirectoryExists($dir.'/workspace');

        $tokenModel = $agent->token ?? $agent->token()->first();
        $token = $tokenModel?->token;
        $model = $agent->model ?? $agent->model()->first();
        $orchestrator = rtrim((string) config('taap.proxy.internal_url', 'http://taap-orchestrator:8000'), '/');

        return match ($agent->runtime) {
            AgentRuntime::Pi => $this->renderPi($agent, $dir, $token, $model, $orchestrator),
            AgentRuntime::Hermes => $this->renderHermes($agent, $dir, $token, $model, $orchestrator),
            AgentRuntime::Claude => $this->renderClaude($agent, $dir, $token, $model, $orchestrator),
            AgentRuntime::Codex => $this->renderCodex($agent, $dir, $token, $model, $orchestrator),
            AgentRuntime::OpenCode => $this->renderOpenCode($agent, $dir, $token, $model, $orchestrator),
        };
    }

    /**
     * @return list<string>
     */
    protected function renderPi(Agent $agent, string $dir, ?string $token, ?Model $model, string $orchestrator): array
    {
        // Id de sesion estable (se crea una vez; la sesion vive en el volumen)
        $config = $agent->config ?? [];
        // El id de sesion es estable PERO: si el modelo/provider cambio, la
        // sesion reanudada arrastra la config antigua -> nueva sesion.
        $providerName = $model->provider?->name;
        $modelKey = $model
            ? $model->id.'|'.($providerName ?? '')
            : 'none';
        if (empty($config['session_id']) || ($config['model_key'] ?? null) !== $modelKey) {
            $config['session_id'] = (string) Str::uuid();
            $config['model_key'] = $modelKey;
            $agent->config = $config;
            $agent->saveQuietly();
        }

        // /config/agent.env -> lo lee el entrypoint de la imagen Pi
        $env = [
            'SESSION_ID='.$config['session_id'],
            'PROVIDER=taap',
        ];
        if ($model) {
            $env[] = 'MODEL='.$model->model_id;
        }
        if ($token) {
            $env[] = 'API_KEY='.$token;
        }
        if ($agent->system_prompt) {
            $env[] = 'SYSTEM_PROMPT='.escapeshellarg($agent->system_prompt);
        }
        File::put($dir.'/config/agent.env', implode("\n", $env)."\n");

        // /data/models.json -> provider "taap" = Model Proxy del orquestador
        $models = $model ? [[
            'id' => $model->model_id,
            'name' => $model->name,
            'contextWindow' => $model->context_window,
            'maxTokens' => $model->max_tokens,
        ]] : [];
        $modelsJson = [
            'providers' => [
                'taap' => [
                    'baseUrl' => $orchestrator.$agent->modelProxyUrl().'/v1',
                    'api' => 'openai-completions',
                    'apiKey' => $token ?? '',
                    'models' => $models,
                ],
            ],
        ];
        File::put($dir.'/data/models.json', json_encode($modelsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        // /data/mcp.json -> servers asignados via la puerta MCP del orquestador
        $mcpServers = [];
        foreach ($agent->mcpServers()->wherePivot('mcp_server_id', '!=', 0)->get() as $server) {
            if ($server->enabled) {
                $mcpServers[$server->name] = [
                    'url' => $orchestrator.$agent->mcpBridgeUrl().'?server='.urlencode($server->name),
                    'headers' => [
                        'Authorization' => 'Bearer '.($token ?? ''),
                    ],
                ];
            }
        }
        File::put($dir.'/data/mcp.json', json_encode(['mcpServers' => $mcpServers === [] ? new \stdClass : $mcpServers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        // /data/extensions/taap-approval.ts -> extension que pide confirmacion
        // antes de cada bash (-> extension_ui_request -> approval.request)
        $ext = base_path('resources/agents/pi/taap-approval.ts');
        if (is_file($ext)) {
            File::ensureDirectoryExists($dir.'/data/extensions');
            File::copy($ext, $dir.'/data/extensions/taap-approval.ts');
        }

        return [
            $this->bind($dir.'/config', '/config'),
            $this->bind($dir.'/data', '/home/agent/.pi/agent'),
            $this->bind($dir.'/workspace', '/workspace'),
        ];
    }

    /**
     * @return list<string>
     */
    protected function renderHermes(Agent $agent, string $dir, ?string $token, ?Model $model, string $orchestrator): array
    {
        $token = $token ?? '';

        // /data/.env -> switches y claves. EL .ENV DEL VOLUMEN TIENE
        // PRECEDENCIA (override=True) sobre el env del contenedor, asi
        // declaramos aqui todo lo que el gateway debe ver.
        $env = [
            'API_SERVER_ENABLED=true',
            'API_SERVER_HOST=0.0.0.0',
            'API_SERVER_KEY='.$token,
            'TAAP_MODEL_TOKEN='.$token,
        ];
        File::put($dir.'/data/.env', implode("\n", $env)."\n");

        // /data/config.yaml -> modelo (Model Proxy) + MCP (puerta MCP)
        $yaml = '# Generado por TAAP - no editar a mano (se regenera en cada
'
            .'# cambio de config y reinicio del gateway).
'
            .'_config_version: 53
';

        // WAL no es fiable sobre virtiofs (Docker Desktop macOS).
        $yaml .= "database:\n  journal_mode: \"delete\"\n";

        $yaml .= "model:\n  provider: \"custom\"\n";
        if ($model) {
            $yaml .= '  base_url: '.$this->yamlScalar($orchestrator.$agent->modelProxyUrl().'/v1')."\n";
            $yaml .= "  key_env: \"TAAP_MODEL_TOKEN\"\n";
            $yaml .= '  default: '.$this->yamlScalar($model->model_id)."\n";
        }

        $servers = [];
        foreach ($agent->mcpServers()->get() as $server) {
            if (! $server->enabled) {
                continue;
            }

            if ($server->type === 'http' && $server->url) {
                $servers[$server->name] = [
                    'url' => $orchestrator.$agent->mcpBridgeUrl().'?server='.rawurlencode($server->name),
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                    ],
                ];
            } else {
                $srv = [
                    'command' => (string) $server->command,
                    'args' => (array) $server->args,
                ];
                if (! empty($server->env)) {
                    $srv['env'] = (array) $server->env;
                }
                $servers[$server->name] = $srv;
            }
        }

        if ($servers) {
            $yaml .= "\nmcp_servers:\n".$this->yamlBlock($servers, 2)."\n";
        }

        // El terminal hereda el propio contenedor (la aislacion la da Docker)
        $yaml .= "terminal:\n  backend: local\n";

        File::put($dir.'/data/config.yaml', $yaml);

        return [
            $this->bind($dir.'/data', '/opt/data'),
        ];
    }

    /**
     * Claude Code (design D6): /config/claude.json para el sidecar. El
     * sidecar lo RE-LEE en cada turno: cambiar modelo/MCP/prompt/permisos
     * se aplica al SIGUIENTE turno sin reinicio (la sesion la reanuda el
     * SDK con `resume`; NO hay rotacion de session_id, a diferencia de Pi).
     *
     * @return list<string>
     */
    protected function renderClaude(Agent $agent, string $dir, ?string $token, ?Model $model, string $orchestrator): array
    {
        // Puerta del Model Proxy: el CLI appenda /v1/messages, /v1/models,
        // /api/hello -> la base NO lleva /v1 (spike 1.2).
        $proxy = $orchestrator.$agent->modelProxyUrl();

        // MCP por la puerta HTTP del orquestador (token de agente), igual
        // que Hermes: los servers stdio viven ahi, no en el contenedor.
        $mcpServers = [];
        foreach ($agent->mcpServers()->wherePivot('mcp_server_id', '!=', 0)->get() as $server) {
            if (! $server->enabled || $server->type !== 'http' || ! $server->url) {
                continue;
            }
            $mcpServers[$server->name] = [
                'url' => $orchestrator.$agent->mcpBridgeUrl().'?server='.rawurlencode($server->name),
                'headers' => [
                    'Authorization' => 'Bearer '.($token ?? ''),
                ],
            ];
        }

        // Permisos (design D3): los defaults los aporta el sidecar; el plano
        // los lleva solo si el admin los ajusto en la config del agente.
        $config = $agent->config ?? [];
        $permissions = $config['permissions'] ?? null;

        $claude = [
            'model' => $model?->model_id,
            'anthropic_base_url' => $proxy,
            'anthropic_api_key' => $token,
            'system_prompt' => $agent->system_prompt,
            'mcp_servers' => $mcpServers === [] ? new \stdClass : $mcpServers,
            'workdir' => '/workspace',
            'state_file' => '/data/session-state.json',
        ];
        if (is_array($permissions)) {
            $claude['permissions'] = $permissions;
        }
        if (isset($config['approval_timeout_seconds'])) {
            $claude['approval_timeout_seconds'] = (int) $config['approval_timeout_seconds'];
        }
        // Reintentos del CLI ante peticiones fallidas (p. ej. proveedor
        // caido): sin acesos, la SDK reintenta varios minutos y el evento
        // `error` TAAP tardaria en llegar. Solo si el admin lo fija.
        if (isset($config['max_retries'])) {
            $claude['max_retries'] = (int) $config['max_retries'];
        }

        File::put($dir.'/config/claude.json', json_encode($claude, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return [
            $this->bind($dir.'/config', '/config'),
            $this->bind($dir.'/data', '/data'),
            $this->bind($dir.'/workspace', '/workspace'),
        ];
    }

    /**
     * Codex (add-codex-runtime, design D4): el `codex app-server` lee la
     * config por `-c key=value` (TOML plano) en la línea de comandos: el
     * plano es un `codex-args.env` con un ARGUMENTO por línea (el
     * entrypoint lo expande a argv) + un `codex.env` con las variables
     * (API key, CODEX_HOME). Nada de `config.toml` renderizado: los `-c`
     * ganan sobre cualquier fichero y el layout queda idéntico a Pi.
     *
     * Wire `responses`: el provider custom apunta al Model Proxy con /v1
     * (codex llama a /v1/responses; el proxy lo sirve como passthrough —
     * el wire `chat` fue eliminado de codex en 0.128: no existe).
     */
    protected function renderCodex(Agent $agent, string $dir, ?string $token, ?Model $model, string $orchestrator): array
    {
        // /config/codex.env -> lo lee el entrypoint (patrón agents/pi)
        $env = ['CODEX_HOME=/data'];
        if ($token) {
            $env[] = 'TAAP_API_KEY='.$token;
        }
        File::put($dir.'/config/codex.env', implode("\n", $env)."\n");

        // /config/codex-args.env -> un argumento por línea para el binary
        $args = [
            '-c', 'approval_policy='.($this->codexStr($agent, 'approval_policy') ?? 'on-request'),
            '-c', 'sandbox_mode='.($this->codexStr($agent, 'sandbox_mode') ?? 'workspace-write'),
        ];

        if ($model?->model_id) {
            $args[] = '-c';
            $args[] = 'model="'.$model->model_id.'"';
        }

        // Provider custom "taap" = Model Proxy del agente (wire responses)
        $args = array_merge($args, [
            '-c', 'model_provider=taap',
            '-c', 'model_providers.taap.name=taap',
            '-c', 'model_providers.taap.base_url="'.$orchestrator.$agent->modelProxyUrl().'/v1"',
            '-c', 'model_providers.taap.env_key=TAAP_API_KEY',
            '-c', 'model_providers.taap.wire_api=responses',
        ]);

        // Reintentos ante proveedor caido (mismo dial que el runtime Claude:
        // sin tope, codex reintenta con backoff exponencial durante varios
        // minutos antes de que el error llegue al chat). Solo si el admin lo
        // fija en la config del agente.
        if (isset($agent->config['max_retries'])) {
            $retries = (int) $agent->config['max_retries'];
            $args[] = '-c';
            $args[] = 'model_providers.taap.request_max_retries='.$retries;
            $args[] = '-c';
            $args[] = 'model_providers.taap.stream_max_retries='.$retries;
        }

        // MCP: los http van por la puerta del orquestador (token de agente);
        // los stdio se declaran con command/args (la imagen lleva python3+node
        // para ejecutarlos, paridad con Pi). Verificado en el spike: el
        // app-server llama al gate con el Authorization esperado.
        foreach ($agent->mcpServers()->wherePivot('mcp_server_id', '!=', 0)->get() as $server) {
            if (! $server->enabled) {
                continue;
            }
            $name = str_replace('.', '_', $server->name);
            if ($server->type === 'http' && $server->url) {
                $args[] = '-c';
                $args[] = 'mcp_servers.'.$name.'.url="'.$orchestrator.$agent->mcpBridgeUrl().'?server='.urlencode($server->name).'"';
                if ($token) {
                    $args[] = '-c';
                    $args[] = 'mcp_servers.'.$name.'.http_headers.Authorization="Bearer '.$token.'"';
                }
            } elseif ($server->type === 'stdio' && $server->command) {
                $args[] = '-c';
                $args[] = 'mcp_servers.'.$name.'.command="'.$server->command.'"';
                $args[] = '-c';
                $args[] = 'mcp_servers.'.$name.'.args='.json_encode(array_values($server->args ?? []), JSON_UNESCAPED_SLASHES);
                if (! empty($server->env)) {
                    $args[] = '-c';
                    $envPairs = collect($server->env)->mapWithKeys(fn ($v, $k) => $k.'="'.$v.'"')->implode(' ');
                    $args[] = 'mcp_servers.'.$name.'.env={'.$envPairs.'}';
                }
            }
        }

        File::put($dir.'/config/codex-args.env', implode("\n", $args)."\n");

        return [
            $this->bind($dir.'/config', '/config'),
            $this->bind($dir.'/data', '/data'),
            $this->bind($dir.'/workspace', '/workspace'),
        ];
    }

    /**
     * OpenCode (add-opencode-runtime, design D4): `opencode.json` en el
     * volumen de config. opencode lo busca en `$XDG_CONFIG_HOME/opencode/`
     * (el entrypoint fija `XDG_CONFIG_HOME=/config` -> el fichero va en
     * `config/opencode/opencode.json` — verificado en el spike: sin el
     * subdirectorio `opencode/` no se carga).
     *
     * Provider dual: el paquete npm depende del TIPO del proveedor
     * (`@ai-sdk/openai-compatible` llama a `/v1/chat/completions`;
     * `@ai-sdk/anthropic` a `/v1/messages`) — ambos contra el Model Proxy.
     * El modelo elegido debe figurar en el mapa `models` del provider
     * (spike: un modelo no resolvable se admite en silencio y no llama al
     * proveedor), de ahi re-renderizar + reiniciar al cambiarlo.
     */
    protected function renderOpenCode(Agent $agent, string $dir, ?string $token, ?Model $model, string $orchestrator): array
    {
        $config = $agent->config ?? [];

        // Provider `taap` -> Model Proxy del agente. La base va con /v1:
        //   openai-compatible llama a <base>/chat/completions
        //   @ai-sdk/anthropic   llama a <base>/messages
        // (ambas rutas las sirve el Model Proxy; verificado en el spike).
        $providerType = $model?->provider?->type ?? 'openai';
        $proxy = $orchestrator.$agent->modelProxyUrl().'/v1';
        $provider = match ($providerType) {
            'anthropic' => [
                'npm' => '@ai-sdk/anthropic',
                'name' => 'TAAP',
                'options' => [
                    'baseURL' => $proxy,
                    'apiKey' => $token,
                ],
            ],
            default => [
                // `other` se trata como OpenAI-compatible (regla v1.0)
                'npm' => '@ai-sdk/openai-compatible',
                'name' => 'TAAP',
                'options' => [
                    'baseURL' => $proxy,
                    'apiKey' => $token,
                ],
            ],
        };

        // Mapa de modelos del provider: SOLO el elegido (spike Q4: la
        // resolucion falla en silencio con ids no declarados; el cambio de
        // modelo re-renderiza y reinicia, y la sesion se re-sync por API).
        if ($model?->model_id !== null) {
            $provider['models'] = [
                $model->model_id => ['name' => $model->name ?? $model->model_id],
            ];
        }

        // Permisos (design D3): ask para efectos, allow para lectura. El
        // admin puede ajustarlo en la config del agente (`permissions`).
        $permission = is_array($config['permissions'] ?? null) ? $config['permissions'] : [
            'bash' => 'ask',
            'edit' => 'ask',
            'webfetch' => 'ask',
            'websearch' => 'ask',
            'task' => 'ask',
            'read' => 'allow',
            'glob' => 'allow',
            'grep' => 'allow',
            'list' => 'allow',
        ];

        // MCP: http por la puerta del orquestador (token de agente); stdio
        // con command/args (la imagen lleva node+python3, paridad Pi/Codex).
        $mcp = [];
        foreach ($agent->mcpServers()->wherePivot('mcp_server_id', '!=', 0)->get() as $server) {
            if (! $server->enabled) {
                continue;
            }
            if ($server->type === 'http' && $server->url) {
                $mcp[$server->name] = [
                    'type' => 'remote',
                    'url' => $orchestrator.$agent->mcpBridgeUrl().'?server='.rawurlencode($server->name),
                    'enabled' => true,
                    'headers' => ['Authorization' => 'Bearer '.($token ?? '')],
                ];
            } elseif ($server->type === 'stdio' && $server->command) {
                $entry = [
                    'type' => 'local',
                    'command' => [$server->command],
                    'enabled' => true,
                ];
                if (! empty($server->args)) {
                    $entry['command'] = array_merge([$server->command], array_values($server->args));
                }
                if (! empty($server->env)) {
                    $entry['environment'] = $server->env;
                }
                $mcp[$server->name] = $entry;
            }
        }

        $opencode = [
            '$schema' => 'https://opencode.ai/config.json',
            'model' => 'taap/'.($model?->model_id ?? ''),
            'provider' => ['taap' => $provider],
            'permission' => $permission,
            'mcp' => $mcp === [] ? new \stdClass : $mcp,
        ];
        if ($agent->system_prompt) {
            $opencode['instructions'] = $agent->system_prompt;
        }

        File::ensureDirectoryExists($dir.'/config/opencode');
        File::put($dir.'/config/opencode/opencode.json', json_encode($opencode, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return [
            $this->bind($dir.'/config', '/config'),
            $this->bind($dir.'/data', '/data'),
            $this->bind($dir.'/workspace', '/workspace'),
        ];
    }

    /**
     * Valores de la config JSON del agente (dials opcionales del form)
     * como strings limpios para el plano.
     */
    protected function codexStr(Agent $agent, string $key): ?string
    {
        $value = $agent->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     */
    protected function yamlBlock(array $items, int $indent = 0): string
    {
        if ($items === []) {
            return str_pad('', $indent).'{}';
        }

        $pad = str_repeat(' ', $indent);
        $lines = [];
        foreach ($items as $name => $value) {
            $lines[] = $pad.$this->yamlScalar($name).':';
            foreach ($value as $key => $val) {
                if (is_array($val)) {
                    $lines[] = $pad.'  '.$key.':';
                    foreach ($val as $k2 => $v2) {
                        $lines[] = $pad.'    '.$k2.': '.$this->yamlScalar((string) $v2);
                    }
                } else {
                    $lines[] = $pad.'  '.$key.': '.$this->yamlScalar((string) $val);
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function yamlScalar(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_\-\.\/:]+$/', $value) && ! is_numeric($value)) {
            return $value;
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
