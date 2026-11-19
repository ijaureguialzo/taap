<?php

namespace App\Console\Commands;

use App\Agents\HermesAdapter;
use App\Models\Agent;
use Illuminate\Console\Command;

/**
 * Coproceso desatendido para una corrida Hermes (la peticion HTTP de
 * streaming puede durar minutos/horas; no debe bloquear al bridge).
 * Lo lanza ChatService via proc_open; sus eventos van al EventSink (DB+Reverb).
 */
class TaapHermesTurnCommand extends Command
{
    protected $signature = 'taap:hermes-turn {agentId} {messageId}';

    protected $description = 'Ejecuta una corrida de chat contra el gateway Hermes de un agente';

    public function handle(HermesAdapter $adapter): int
    {
        $agent = Agent::find($this->argument('agentId'));
        if (! $agent) {
            return self::SUCCESS;
        }

        $message = $agent->messages()->find($this->argument('messageId'));
        if (! $message) {
            return self::SUCCESS;
        }

        $adapter->sendUserMessage($agent, $message->content, $message->user_id);

        return self::SUCCESS;
    }
}
