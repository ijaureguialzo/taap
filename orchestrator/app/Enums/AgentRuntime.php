<?php

namespace App\Enums;

enum AgentRuntime: string
{
    case Pi = 'pi';
    case Hermes = 'hermes';

    public static function supported(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromString(string $v): self
    {
        return self::from($v);
    }

    public function label(): string
    {
        return config("taap.runtimes.{$this->value}.label", $this->value);
    }

    public function registry(): array
    {
        return config("taap.runtimes.{$this->value}");
    }
}
