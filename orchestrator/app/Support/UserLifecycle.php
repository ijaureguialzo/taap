<?php

namespace App\Support;

use App\Agents\AgentManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de la cuenta de usuario sobre el acceso y los agentes
 * (change admin-user-management-and-profile, design §2):
 * baja/reactivación, cambio de contraseña y borrado comparten UN camino
 * para que web y API tengan la misma semántica.
 */
class UserLifecycle
{
    public function __construct(
        protected AgentManager $agents,
    ) {}

    /**
     * Baja al usuario: detiene los agentes en ejecución, revoca sus sesiones
     * web y tokens API, y marca active = false.
     */
    public function stopAndRevoke(User $user, ?int $keepTokenId = null): void
    {
        $this->stopAgents($user);

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $tokens = $user->tokens();
        if ($keepTokenId !== null) {
            $tokens = $tokens->where('id', '!=', $keepTokenId);
        }
        $tokens->delete();

        $user->active = false;
        $user->save();
    }

    /**
     * Reactiva al usuario: active = true y los agentes vuelven a arrancar.
     * Best-effort (un agente que no arranca no bloquea la reactivación).
     */
    public function reactivate(User $user): void
    {
        $user->active = true;
        $user->save();

        foreach ($user->agents()->get() as $agent) {
            try {
                $this->agents->start($agent);
            } catch (\Throwable) {
                \Log::warning('Reanudación de agente falló', ['agent_id' => $agent->id, 'user_id' => $user->id]);
            }
        }
    }

    /**
     * Asigna una contraseña nueva conservando la sesión/token con el que se
     * ejecuta el cambio (p. ej. el cambio propio desde la web).
     */
    public function changePassword(User $user, string $newPassword, ?int $keepTokenId = null, ?string $keepSessionId = null): void
    {
        $user->password = $newPassword;
        $user->save();

        $sessions = DB::table('sessions')->where('user_id', $user->id);
        if ($keepSessionId !== null) {
            $sessions = $sessions->where('id', '!=', $keepSessionId);
        }
        $sessions->delete();

        $tokens = $user->tokens();
        if ($keepTokenId !== null) {
            $tokens = $tokens->where('id', '!=', $keepTokenId);
        }
        $tokens->delete();
    }

    /**
     * Baja al usuario y elimina la cuenta (irreversible; los agentes se
     * dan de baja y la FK cascatea sus filas).
     */
    public function deleteUser(User $user): void
    {
        $this->stopAndRevoke($user);
        $user->delete();
    }

    protected function stopAgents(User $user): void
    {
        foreach ($user->agents()->get() as $agent) {
            if (! $agent->status?->isRunning()) {
                continue;
            }

            try {
                $this->agents->stop($agent);
            } catch (\Throwable) {
                \Log::warning('Detención de agente falló', ['agent_id' => $agent->id, 'user_id' => $user->id]);
            }
        }
    }
}
