<?php

namespace Tests\Unit;

use App\Enums\AgentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tabla de estados del chat: en que estados el agente puede aceptar
 * mensajes del usuario (fix-chat-viewport-send).
 */
class AgentStatusTest extends TestCase
{
    #[DataProvider('acceptsMessagesProvider')]
    public function test_accepts_messages_per_state(AgentStatus $status, bool $expected): void
    {
        $this->assertSame($expected, $status->acceptsMessages(), "Estado: {$status->value}");
    }

    /**
     * @return array<string, array{0: AgentStatus, 1: bool}>
     */
    public static function acceptsMessagesProvider(): array
    {
        return [
            'offline (arranca al enviar)' => [AgentStatus::Offline, true],
            'starting' => [AgentStatus::Starting, true],
            'idle' => [AgentStatus::Idle, true],
            'working (encola FIFO)' => [AgentStatus::Working, true],
            'waiting-approval (bloqueado)' => [AgentStatus::WaitingApproval, false],
            'error' => [AgentStatus::Error, true],
            'stopped (arranca al enviar)' => [AgentStatus::Stopped, true],
        ];
    }
}
