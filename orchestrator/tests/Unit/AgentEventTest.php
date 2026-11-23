<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use Tests\TestCase;

/**
 * AgentEvent (interactive-agent-chat 1.1): shape exacto del contrato
 * normalizado. Web e iOS consumen SOLO este modelo: las factorías se
 * prueban a nivel de `toArray()` (tipos, claves y valores del payload).
 */
class AgentEventTest extends TestCase
{
    public function test_message_delta_shape(): void
    {
        $arr = AgentEvent::messageDelta('run-1', 'hola')->toArray();

        $this->assertSame('message.delta', $arr['type']);
        $this->assertSame(['run_id' => 'run-1', 'content' => 'hola'], $arr['payload']);
        $this->assertIsString($arr['at']);
    }

    public function test_thinking_delta_shape(): void
    {
        $arr = AgentEvent::thinkingDelta('run-1', 'muy interesante...')->toArray();

        $this->assertSame('thinking.delta', $arr['type']);
        $this->assertSame(['run_id' => 'run-1', 'content' => 'muy interesante...'], $arr['payload']);
    }

    public function test_thinking_delta_allows_null_run_id(): void
    {
        $arr = AgentEvent::thinkingDelta(null, 'pensando')->toArray();

        $this->assertSame(['run_id' => null, 'content' => 'pensando'], $arr['payload']);
    }

    public function test_message_final_carries_thinking_separated_from_content(): void
    {
        $arr = AgentEvent::messageFinal('run-1', 'La respuesta.', 'El razonamiento.')->toArray();

        $this->assertSame('message.final', $arr['type']);
        $this->assertSame(
            ['run_id' => 'run-1', 'content' => 'La respuesta.', 'thinking' => 'El razonamiento.'],
            $arr['payload']
        );
    }

    public function test_message_final_thinking_defaults_to_empty_string(): void
    {
        // Signature backward-compatible: los adaptadores que aún no emiten
        // razonamiento (p. ej. Codex, si el spike lo confirma) no la tocan.
        $arr = AgentEvent::messageFinal('run-1', 'La respuesta.')->toArray();

        $this->assertSame('message.final', $arr['type']);
        $this->assertSame(
            ['run_id' => 'run-1', 'content' => 'La respuesta.', 'thinking' => ''],
            $arr['payload']
        );
    }
}
