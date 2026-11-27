<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChatSearchWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'chat-web@taap.test']);
        $this->agent = Agent::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Agente Web']);

        ChatMessage::create(['agent_id' => $this->agent->id, 'author' => 'user', 'user_id' => $this->owner->id, 'content' => 'hola cómo va el mundo']);
        ChatMessage::create(['agent_id' => $this->agent->id, 'author' => 'agent', 'user_id' => $this->owner->id, 'content' => 'todo bien, gracias']);

        $this->actingAs($this->owner);
    }

    public function test_search_filters_displayed_results(): void
    {
        Livewire::test(\App\Livewire\Agents\Chat::class, ['agent' => $this->agent])
            ->set('searchQuery', 'mundo')
            ->assertSee('mundo')
            ->assertSee('export?format=md');
    }

    public function test_empty_query_clears_results(): void
    {
        Livewire::test(\App\Livewire\Agents\Chat::class, ['agent' => $this->agent])
            ->set('searchQuery', 'mundo')
            ->assertSee('mundo')
            ->set('searchQuery', '')
            ->assertDontSee('Sin resultados');
    }

    public function test_export_links_are_present(): void
    {
        Livewire::test(\App\Livewire\Agents\Chat::class, ['agent' => $this->agent])
            ->assertSee('/messages/export?format=md')
            ->assertSee('/messages/export?format=json');
    }
}
