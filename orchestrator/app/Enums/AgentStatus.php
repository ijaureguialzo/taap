<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Offline = 'offline';
    case Starting = 'starting';
    case Idle = 'idle';
    case Working = 'working';
    case WaitingApproval = 'waiting-approval';
    case Error = 'error';
    case Stopped = 'stopped';

    /** Estados que cuentan como "en ejecucion" para la cuota. */
    public function isRunning(): bool
    {
        return $this !== self::Offline && $this !== self::Stopped;
    }

    public function isActive(): bool
    {
        return $this->isRunning() || $this === self::Error;
    }

    /**
     * Puede el usuario enviarle un mensaje en este estado? Solo el agente
     * bloqueado en una aprobacion no lo acepta: la accion pendiente debe
     * resolverse antes. El resto de estados (detenido/offline incluido)
     * aceptan: el envio lo arranca si hiciera falta y el mensaje se encola.
     */
    public function acceptsMessages(): bool
    {
        return $this !== self::WaitingApproval;
    }

    public static function fromString(string $v): self
    {
        return self::from($v);
    }
}
