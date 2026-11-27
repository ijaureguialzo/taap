<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatSearchExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'chat-search@taap.test']);
        $this->agent = Agent::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Agente Búsqueda']);

        ChatMessage::create(['agent_id' => $this->agent->id, 'author' => 'user', 'user_id' => $this->owner->id, 'content' => 'hola cómo va el proyecto de búsqueda?']);
        ChatMessage::create(['agent_id' => $this->agent->id, 'author' => 'agent', 'user_id' => $this->owner->id, 'content' => 'todo bien, el filtro funciona']);
        ChatMessage::create(['agent_id' => $this->agent->id, 'author' => 'user', 'user_id' => $this->owner->id, 'content' => '¿qué tal la exportación de mensajes?']);
    }

    public function test_search_returns_matches_with_pagination(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->getJson('/api/v1/agents/'.$this->agent->id.'/messages/search?q=búsqueda')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1);
    }

    public function test_search_for_other_terms_finds_messages(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->getJson('/api/v1/agents/'.$this->agent->id.'/messages/search?q=exportación')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author', 'user');
    }

    public function test_search_is_restricted_to_owner(): void
    {
        $other = User::factory()->create(['email' => 'chat-other@taap.test']);
        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/v1/agents/'.$this->agent->id.'/messages/search?q=hola')
            ->assertStatus(404);
    }

    public function test_export_markdown_returns_attachment(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->get('/api/v1/agents/'.$this->agent->id.'/messages/export?format=md')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="chat-'.$this->agent->id.'-'.now()->format('Ymd-His').'.md"');
    }

    public function test_export_json_returns_full_history(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->getJson('/api/v1/agents/'.$this->agent->id.'/messages/export?format=json')
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'hola cómo va el proyecto de búsqueda?')
            ->assertJsonCount(3, 'messages');
    }

    public function test_export_requires_authorization(): void
    {
        $other = User::factory()->create(['email' => 'chat-other-2@taap.test']);
        $this->actingAs($other, 'sanctum');

        $this->get('/api/v1/agents/'.$this->agent->id.'/messages/export')
            ->assertStatus(404);
    }
}
