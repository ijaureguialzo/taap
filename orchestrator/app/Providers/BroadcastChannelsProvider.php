<?php

namespace App\Providers;

use App\Models\Agent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastChannelsProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
        | Canales de eventos de agentes. Autorizacion: propietario o admin.
        | (el callback recibe el usuario autenticado via Sanctum/Reverb)
        */
        $authorize = function ($user, int $id) {
            $agent = Agent::find($id);

            return $agent && ($user->isAdmin() || $agent->owner_id === $user->id);
        };

        foreach (['agent.{id}', 'private-agent.{id}', 'presence-agent.{id}'] as $pattern) {
            Broadcast::channel($pattern, $authorize);
        }
    }
}
