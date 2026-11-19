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

    public static function fromString(string $v): self
    {
        return self::from($v);
    }
}
