<?php

namespace Tests\Unit;

use App\Enums\AgentRuntime;
use Tests\TestCase;

/**
 * Registro de runtimes (add-claude-code-runtime 2.2/4.1, add-codex-runtime
 * 3.1): las capacidades y las piezas de cada entrada se declaran en
 * config/taap.php y se consumen desde el enum; aqui se asertan los valores
 * declarados.
 */
class AgentRuntimeTest extends TestCase
{
    public function test_pi_capabilities_are_declared(): void
    {
        $this->assertSame(['openai', 'native', 'restart'], AgentRuntime::Pi->capabilities());
        $this->assertSame('rpc-stdio', AgentRuntime::Pi->mode());
        $this->assertSame('openai', AgentRuntime::Pi->modelFormat());
    }

    public function test_hermes_capabilities_are_declared(): void
    {
        $this->assertSame(['openai', 'turn', 'restart'], AgentRuntime::Hermes->capabilities());
        $this->assertSame('api-server', AgentRuntime::Hermes->mode());
        $this->assertSame('openai', AgentRuntime::Hermes->modelFormat());
    }

    public function test_claude_capabilities_are_declared(): void
    {
        $this->assertSame(['anthropic', 'native', 'next-turn'], AgentRuntime::Claude->capabilities());
        $this->assertSame('rpc-stdio', AgentRuntime::Claude->mode());
        $this->assertSame('anthropic', AgentRuntime::Claude->modelFormat());
    }

    public function test_codex_capabilities_are_declared(): void
    {
        // Wire `openai` (el provider custom habla la API OpenAI; el formato
        // concreto es `responses`, que el Model Proxy sirve como passthrough).
        $this->assertSame(['openai', 'native', 'restart'], AgentRuntime::Codex->capabilities());
        $this->assertSame('rpc-stdio', AgentRuntime::Codex->mode());
        $this->assertSame('openai', AgentRuntime::Codex->modelFormat());
    }

    public function test_opencode_capabilities_are_declared(): void
    {
        // Doble formato (el provider custom elige openai-compatible o
        // anthropic segun el type), native (prompt propio) y restart
        // (config = re-render + reinicio; el hot-model via API es acelerador).
        $this->assertSame(['openai', 'anthropic', 'native', 'restart'], AgentRuntime::OpenCode->capabilities());
        $this->assertSame('api-events', AgentRuntime::OpenCode->mode());
        $this->assertSame('openai', AgentRuntime::OpenCode->modelFormat());
        $this->assertSame(['openai', 'anthropic'], AgentRuntime::OpenCode->modelFormats());
    }

    public function test_opencode_registry_has_its_volumes_and_config_file(): void
    {
        $entry = AgentRuntime::OpenCode->registry();
        $this->assertSame('taap/agent-opencode:local', $entry['image']);
        $this->assertSame(4096, $entry['api_port']);
        $this->assertArrayHasKey('config', $entry['volumes']);
        $this->assertArrayHasKey('data', $entry['volumes']);
        $this->assertArrayHasKey('workspace', $entry['volumes']);
        $this->assertSame(['opencode.json'], $entry['config_files']);
    }

    public function test_supported_lists_all_registered_runtimes(): void
    {
        $this->assertSame(['pi', 'hermes', 'claude', 'codex', 'opencode'], AgentRuntime::supported());
    }

    public function test_registry_entries_are_complete(): void
    {
        foreach (AgentRuntime::supported() as $value) {
            $entry = AgentRuntime::fromString($value)->registry();
            $this->assertNotSame(null, $entry, "el runtime {$value} debe estar registrado");
            $this->assertIsString($entry['label'] ?? null, "{$value}: label");
            $this->assertIsString($entry['image'] ?? null, "{$value}: image");
            $this->assertContains($entry['mode'] ?? null, ['rpc-stdio', 'api-server', 'api-events'], "{$value}: mode");
            $this->assertIsArray($entry['capabilities'] ?? null, "{$value}: capabilities");
            $this->assertIsArray($entry['volumes'] ?? null, "{$value}: volumes");
            $this->assertIsArray($entry['config_files'] ?? null, "{$value}: config_files");
        }
    }

    public function test_claude_registry_has_its_volumes_and_config_file(): void
    {
        $entry = AgentRuntime::Claude->registry();
        $this->assertSame('taap/agent-claude:local', $entry['image']);
        $this->assertArrayHasKey('config', $entry['volumes']);
        $this->assertArrayHasKey('data', $entry['volumes']);
        $this->assertArrayHasKey('workspace', $entry['volumes']);
        $this->assertSame(['claude.json'], $entry['config_files']);
    }

    public function test_codex_registry_has_its_volumes_and_config_files(): void
    {
        $entry = AgentRuntime::Codex->registry();
        $this->assertSame('taap/agent-codex:local', $entry['image']);
        $this->assertArrayHasKey('config', $entry['volumes']);
        $this->assertArrayHasKey('data', $entry['volumes']);
        $this->assertArrayHasKey('workspace', $entry['volumes']);
        $this->assertSame(['codex.env', 'codex-args.env'], $entry['config_files']);
    }
}
