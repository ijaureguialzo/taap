<?php

namespace Tests\Feature\Proxy;

use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\ModelUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * model-usage-tracking: el proxy persiste una entrada de uso por petición
 * (best-effort; no rompe la respuesta).
 */
class UsageRecordTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Agent $agent;

    protected ModelProvider $provider;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->provider = ModelProvider::factory()->create([
            'type' => 'openai',
            'base_url' => 'http://upstream-openai.local/v1',
            'api_key' => 'key-test',
            'created_by' => $this->user->id,
        ]);
        $model = Model::factory()->create([
            'provider_id' => $this->provider->id,
            'model_id' => 'gpt-test',
            'context_window' => 100000,
            'max_tokens' => 4096,
        ]);
        $this->agent = Agent::factory()->create([
            'owner_id' => $this->user->id,
            'model_id' => $model->id,
        ]);
        $token = AgentToken::issueFor($this->agent);
        $token->save();
        $this->token = $token->token;
    }

    public function test_successful_request_records_usage(): void
    {
        Http::fake([
            'upstream-openai.local/v1/chat/completions*' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-test',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'hola'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17],
            ], 200),
        ]);

        $response = $this->postJson(
            "/api/proxy/agents/{$this->agent->id}/v1/chat/completions",
            ['model' => 'gpt-test', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            ['Authorization' => 'Bearer '.$this->token]
        );

        $response->assertOk();

        $this->assertDatabaseHas('model_usage', [
            'agent_id' => $this->agent->id,
            'provider_id' => $this->provider->id,
            'tokens_input' => 12,
            'tokens_output' => 5,
            'status' => 200,
        ]);
    }

    public function test_upstream_failure_records_usage_but_still_serves_response(): void
    {
        Http::fake([
            'upstream-openai.local/v1/chat/completions*' => Http::response([
                'error' => ['message' => 'boom'],
            ], 500),
        ]);

        $response = $this->postJson(
            "/api/proxy/agents/{$this->agent->id}/v1/chat/completions",
            ['model' => 'gpt-test', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            ['Authorization' => 'Bearer '.$this->token]
        );

        // La respuesta transclúe el error upstream; la telemetría igualmente se escribe.
        $this->assertDatabaseHas('model_usage', [
            'agent_id' => $this->agent->id,
            'status' => 500,
        ]);
    }
}
