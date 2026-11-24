<?php

namespace Tests\Unit;

use App\Support\DockerClient;
use Tests\TestCase;

/**
 * Cola durable de turnos en el área IPC (fix-bridge-cross-process-seams D1):
 * el medio cross-proceso entre el worker de cola y el daemon bridge (los
 * procesos comparten el área de ficheros, no la memoria).
 */
class TurnsQueueTest extends TestCase
{
    protected string $dir;

    protected DockerClient $docker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/taap-turns-'.uniqid();
        $this->docker = new DockerClient;
        config(['taap.agents.rpc_base' => $this->dir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.$this->dir);

        parent::tearDown();
    }

    protected function file(int $id = 1): string
    {
        return $this->dir.'/'.$id.'/turns';
    }

    /** @return list<string> */
    protected function lines(int $id = 1): array
    {
        $raw = (string) @file_get_contents($this->file($id));

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    public function test_append_creates_file_and_take_returns_all_lines_fifo(): void
    {
        $this->docker->turnsAppend(1, json_encode(['kind' => 'turn', 'content' => 'primero']));
        $this->docker->turnsAppend(1, json_encode(['kind' => 'turn', 'content' => "segundo\ncon salto de linea"]));
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'r3']));

        $this->assertSame(3, count($this->lines(1)), 'append es append (el fichero acumula)');

        $taken = $this->docker->turnsTake(1);
        $this->assertCount(3, $taken);
        $this->assertSame('primero', json_decode($taken[0], true)['content']);
        $this->assertSame('segundo'.PHP_EOL.'con salto de linea', json_decode($taken[1], true)['content'],
            'las lineas JSON conservan el salto de linea original (escapea el JSON)');
        $this->assertSame('r3', json_decode($taken[2], true)['run_id']);
        $this->assertSame([], $this->lines(1), 'take trunca la cola');
    }

    public function test_take_on_missing_or_empty_queue_is_empty(): void
    {
        $this->assertSame([], $this->docker->turnsTake(7), 'sin area: cola vacia (no lanza)');
        $this->assertSame([], $this->docker->turnsTake(7));
    }

    public function test_next_takes_one_line_at_a_time_fully_fifo(): void
    {
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'a']));
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'b']));
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'c']));

        $this->assertSame(json_encode(['kind' => 'run', 'run_id' => 'a']), $this->docker->turnsNext(1));
        $this->assertSame(json_encode(['kind' => 'run', 'run_id' => 'b']), $this->docker->turnsNext(1), 'FIFO: la segunda linea, no la primera de nuevo');
        $this->assertSame(json_encode(['kind' => 'run', 'run_id' => 'c']), $this->docker->turnsNext(1));
        $this->assertNull($this->docker->turnsNext(1), 'cola vacia: null');
        $this->assertSame([], $this->lines(1));
    }

    public function test_next_leaves_rest_and_append_after_take_is_not_lost(): void
    {
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'a']));
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'b']));

        $this->assertSame(json_encode(['kind' => 'run', 'run_id' => 'a']), $this->docker->turnsNext(1));
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'c']));

        $this->assertSame(
            [json_encode(['kind' => 'run', 'run_id' => 'b']), json_encode(['kind' => 'run', 'run_id' => 'c'])],
            $this->lines(1),
            'la append tras el consume conserva el orden FIFO completo'
        );
    }

    public function test_corrupt_and_overlong_lines_are_dropped_on_consume(): void
    {
        $this->docker->turnsAppend(1, 'no es json');
        $this->docker->turnsAppend(1, str_repeat('x', 5000)); // > 4 KB
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'ok']));

        $taken = $this->docker->turnsTake(1);
        $this->assertCount(1, $taken);
        $this->assertSame('ok', json_decode($taken[0], true)['run_id']);
    }

    public function test_agent_areas_are_independent(): void
    {
        $this->docker->turnsAppend(1, json_encode(['kind' => 'run', 'run_id' => 'a1']));
        $this->docker->turnsAppend(2, json_encode(['kind' => 'run', 'run_id' => 'a2']));

        $this->assertSame(['a1'], array_map(fn ($l) => json_decode($l, true)['run_id'], $this->docker->turnsTake(1)));
        $this->assertSame(['a2'], array_map(fn ($l) => json_decode($l, true)['run_id'], $this->docker->turnsTake(2)));
    }
}
