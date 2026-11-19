<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Broadcast;

/**
 * Difiunde un evento en el canal publico del agente (no bloquea al bridge).
 */
class SendMessageToChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public string $channel,
        public string $event,
        public array $data,
    ) {}

    public function handle(): void
    {
        Broadcast::on($this->channel)->broadcast($this->event, $this->data);
    }
}
