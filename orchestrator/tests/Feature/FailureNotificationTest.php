<?php

namespace Tests\Feature;

use App\Agents\EventSink;
use App\Jobs\SendAgentFailureNotification;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FailureNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'notif-owner@taap.test']);
    }

    public function test_job_posts_webhook_with_hmac_signature(): void
    {
        Queue::fake();

        $url = 'http://127.0.0.1:9910/hook';
        config(['app.url' => 'http://taap.test']);

        $this->actingAs($this->owner);

        // Configurar el webhook (settings) directamente para el test.
        app(\App\Support\Settings::class)->setFailureWebhookUrl($url);
        app(\App\Support\Settings::class)->setFailureWebhookSecret('test-secret');

        Http::fake(['127.0.0.1:9910/*' => Http::response('ok', 200)]);

        (new SendAgentFailureNotification(100, 'working', 'error', 'boom'))->handle();

        Http::assertSent(function ($request) use ($url) {
            return $request->url() === $url
                && $request->hasHeader('X-TaaP-Event')
                && $request->hasHeader('X-TaaP-Signature');
        });
    }

    public function test_job_does_not_post_when_webhook_missing(): void
    {
        Http::fake();

        $this->actingAs($this->owner);

        // Sin webhook configurado: no envía nada.
        (new SendAgentFailureNotification(100))->handle();

        Http::assertNothingSent();
    }

    public function test_http_4xx_is_not_retried(): void
    {
        // 4xx: registrar y salir sin lanzar excepción (sin reintentos).
        app(\App\Support\Settings::class)->setFailureWebhookUrl('http://127.0.0.1:9912/hook');

        Http::fake(['127.0.0.1:9912/*' => Http::response('bad request', 400)]);

        $this->actingAs($this->owner);

        (new SendAgentFailureNotification(100, 'working', 'error', 'boom'))->handle();

        Http::assertSentCount(1);

        $last = app(\App\Support\Settings::class)->lastNotification('100');
        $this->assertNotNull($last);
        $this->assertSame('failed', $last['status']);
        $this->assertSame(400, $last['http_code']);
    }

    public function test_event_sink_dispatches_notification_job_on_error_transition(): void
    {
        Queue::fake();

        $agent = Agent::factory()->create([
            'owner_id' => $this->owner->id,
            'status' => 'working',
        ]);

        app(\App\Support\Settings::class)->setFailureWebhookUrl('http://127.0.0.1:9910/hook');

        $sink = app(EventSink::class);

        $event = \App\Agents\AgentEvent::statusChange('error');
        // Status change pasa por EventSink::setStatus (el webhook se encola
        // ahí). El evento actualiza agents.status y dispara el job.
        $sink->dispatch($agent->refresh(), $event);

        // El job se encoló (working -> error).
        Queue::assertPushed(SendAgentFailureNotification::class);
    }
}
