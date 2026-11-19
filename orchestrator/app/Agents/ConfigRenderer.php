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
