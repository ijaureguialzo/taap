<?php

namespace Tests\Unit;

use App\Support\UpstreamIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Identidad upstream compartida (agent-runtime-reliability, design D1):
 * el mapeo host.docker.internal → 127.0.0.1 que el proxy y el sondeo de
 * salud deben aplicar al header Host.
 */
final class UpstreamIdentityTest extends TestCase
{
    public function test_docker_internal_maps_to_loopback_with_port(): void
    {
        $this->assertSame('127.0.0.1:8000', UpstreamIdentity::hostFor('http://host.docker.internal:8000/v1'));
        $this->assertSame('127.0.0.1:11434', UpstreamIdentity::hostFor('http://host.docker.internal:11434'));
        $this->assertSame('127.0.0.1:8000', UpstreamIdentity::hostFor('https://host.docker.internal:8000/v1'));
    }

    public function test_docker_internal_without_port(): void
    {
        $this->assertSame('127.0.0.1', UpstreamIdentity::hostFor('http://host.docker.internal'));
    }

    public function test_regular_host_kept_as_is(): void
    {
        $this->assertSame('api.openai.com', UpstreamIdentity::hostFor('https://api.openai.com/v1'));
        $this->assertSame('ollama.local:11434', UpstreamIdentity::hostFor('http://ollama.local:11434'));
    }

    public function test_loopback_stays_loopback(): void
    {
        $this->assertSame('127.0.0.1:9999', UpstreamIdentity::hostFor('http://127.0.0.1:9999/v1'));
    }

    public function test_url_without_host_yields_null(): void
    {
        $this->assertNull(UpstreamIdentity::hostFor('/v1'));
        $this->assertNull(UpstreamIdentity::hostFor(''));
    }
}
