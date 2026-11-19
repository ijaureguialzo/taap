<?php

namespace App\Enums;

enum AgentRuntime: string
{
    case Pi = 'pi';
    case Hermes = 'hermes';

    /**
     * @return list<string>
     */
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

    /**
     * @return array<string, mixed>
     */
    public function registry(): array
    {
        return config("taap.runtimes.{$this->value}");
    }
}
