<?php

namespace Tests\Unit;

use App\Support\DockerClient;
use PHPUnit\Framework\TestCase;

/**
 * Asignacion de IP (agent-runtime-reliability D2): la reserva no puede
 * colisionar ni con el uso en vivo de la red ni con las direcciones ya
 * asignadas en la base de datos (contenedores Created no aparecen en
 * `docker network inspect`).
 */
final class AllocateIpTest extends TestCase
{
    /**
     * DockerClient sin binario: `run()` simulado (subnet + contenedores
     * apegados a la red).
     *
     * @param list<string> $attached
     */
    protected function docker(array $attached): DockerClient
    {
        return new class ($attached) extends DockerClient
        {
            public function __construct(
                private array $attached,
            ) {
                parent::__construct('docker-fake');
            }

            protected function run(array $args, bool $json = true, bool $allowFail = false): string
            {
                $fmt = $args[array_search('--format', $args) + 1] ?? '';
                if (str_contains($fmt, 'IPAM')) {
                    return '10.163.3.0/24';
                }
                if (str_contains($fmt, 'Containers')) {
                    return implode(PHP_EOL, $this->attached);
                }

                return '';
            }
        };
    }

    public function test_live_ip_is_skipped(): void
    {
        $docker = $this->docker(['10.163.3.2/24', '10.163.3.4/24', '10.163.3.5/24']);
        $this->assertSame('10.163.3.3', $docker->allocateIp('taap-internal'));
    }

    public function test_db_reservation_is_skipped(): void
    {
        // .2/.4/.5 en vivo, .3 reservado en la DB (contenedor Created, sin apegar):
        // ni uno de los cinco .2-.6 debe volver a asignarse.
        $docker = $this->docker(['10.163.3.2/24', '10.163.3.4/24', '10.163.3.5/24']);
        $this->assertSame('10.163.3.6', $docker->allocateIp('taap-internal', ['10.163.3.3']));
    }

    public function test_claudia_pi_collision_never_reallocates_reserved_ip(): void
    {
        // El caso real: Pi corriendo con .5 y Claudia con .5 reservada en la
        // DB. Al re-asignar (otro agente nuevo) la reserva de .5 se respeta
        // aunque nadie este apegado con esa IP.
        $docker = $this->docker([]);
        $this->assertSame('10.163.3.2', $docker->allocateIp('taap-internal'));
        $this->assertNotSame('10.163.3.5', $docker->allocateIp('taap-internal', ['10.163.3.5']));
    }

    public function test_exhausted_range_yields_null(): void
    {
        $attached = [];
        for ($i = 2; $i < 252; $i++) {
            $attached[] = "10.163.3.$i/24";
        }
        $docker = $this->docker($attached);
        $this->assertNull($docker->allocateIp('taap-internal', ['10.163.3.251']));
    }
}
