<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispara el webhook saliente de fallo de agente (agent-failure-notifications).
 * Best-effort: si falla, se registra y no afecta al agente. Reintentos
 * acotados (máx. 3 intentos: 10/30/60 s); los 4xx no reintentan.
 */
class SendAgentFailureNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $agentId,
        public string $statusFrom = '',
        public string $statusTo = '',
        public string $cause = '',
    ) {}

    public function handle(): void
    {
        $settings = app(Settings::class);
        $url = $settings->failureWebhookUrl();
        $secret = $settings->failureWebhookSecret();

        if (! $url) {
            Log::info('failure-notification: webhook desactivado', ['agent_id' => $this->agentId]);

            return;
        }

        $agent = Agent::find($this->agentId);

        $payload = [
            'event' => 'agent.failed',
            'agent_id' => $this->agentId,
            'name' => $agent?->name,
            'runtime' => $agent?->runtime?->value,
            'status_from' => $this->statusFrom,
            'status_to' => $this->statusTo,
            'cause' => $this->cause,
            'at' => now()->toIso8601String(),
            'url' => config('app.url').'/agents/'.$this->agentId,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'X-TaaP-Event' => 'agent.failed',
        ];

        if ($secret) {
            $headers['X-TaaP-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                $settings->storeLastNotification((string) $this->agentId, 'sent', $response->status(), null);

                return;
            }

            // 4xx: URL rota, auth, etc. No reintentar — registrar y salir.
            if ($response->status() >= 400 && $response->status() < 500) {
                $settings->storeLastNotification((string) $this->agentId, 'failed', $response->status(), null);

                return;
            }

            // 5xx: se rethrow consciente para reintentos con backoff.
            throw new \RuntimeException('webhook HTTP '.$response->status());
        } catch (\Throwable $e) {
            // Guardamos el desenlace aunque el job reintente.
            $settings->storeLastNotification((string) $this->agentId, 'failed', null, $e->getMessage());

            Log::warning('failure-notification: envío fallido', [
                'agent_id' => $this->agentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
