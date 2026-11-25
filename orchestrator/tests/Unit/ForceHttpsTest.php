<?php

namespace Tests\Unit;

use App\Support\ForceHttps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Resolver tri-estado de TAAP_FORCE_HTTPS (configurable-https-urls,
 * design D1/D3): true -> https; false -> http; null (o string que env()
 * no castea a bool) -> derivacion del esquema de APP_URL, el estado que
 * 2f56d5b dejo (dev local en Docker con APP_URL=http no redirige a https).
 */
final class ForceHttpsTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string, bool}>
     */
    public static function casesProvider(): array
    {
        return [
            'true fuerza https aunque APP_URL sea interna http (el caso deploy TLS)' => [
                true, 'http://127.0.0.1:8000', true,
            ],
            'true con APP_URL ya https' => [true, 'https://taap.itlent.eus', true],
            'false fuerza http aunque APP_URL sea https' => [false, 'https://taap.itlent.eus', false],
            'false con APP_URL http' => [false, 'http://127.0.0.1:8011', false],
            'sin definir deriva de APP_URL http' => [null, 'http://127.0.0.1:8000', false],
            'sin definir deriva de APP_URL https' => [null, 'https://taap.itlent.eus', true],
            'string no reconocible se trata como sin definir' => ['yes-please', 'http://127.0.0.1:8000', false],
        ];
    }

    /**
     * @dataProvider casesProvider
     */
    #[DataProvider('casesProvider')]
    public function test_resolve(mixed $explicit, string $appUrl, bool $expected): void
    {
        $this->assertSame($expected, ForceHttps::resolve($explicit, $appUrl));
    }
}
