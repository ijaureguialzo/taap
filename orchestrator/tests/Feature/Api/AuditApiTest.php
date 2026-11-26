<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'audit-api@taap.test']);
        $this->actingAs($this->admin, 'sanctum');

        AuditLog::create([
            'actor_id' => $this->admin->id,
            'action' => 'user.created',
            'target_type' => 'user',
            'target_id' => '1',
            'created_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => $this->admin->id,
            'action' => 'provider.updated',
            'target_type' => 'provider',
            'target_id' => '7',
            'created_at' => now(),
        ]);
    }

    public function test_admin_lists_audit_entries(): void
    {
        $this->getJson('/api/v1/audit')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['action' => 'user.created'])
            ->assertJsonFragment(['action' => 'provider.updated']);
    }

    public function test_admin_filters_by_action(): void
    {
        $this->getJson('/api/v1/audit?action=user.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'user.created');
    }

    public function test_admin_filters_by_target_type(): void
    {
        $this->getJson('/api/v1/audit?target_type=provider')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_paginate(): void
    {
        $this->getJson('/api/v1/audit?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 2);
    }

    public function test_non_admin_is_denied(): void
    {
        $other = User::factory()->create(['email' => 'audit-other@taap.test']);
        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/v1/audit')
            ->assertStatus(403);
    }
}
