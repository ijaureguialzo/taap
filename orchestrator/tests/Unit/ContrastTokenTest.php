<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contraste AA (taap-visual-identity 6.1, design D8): la tabla de tokens
 * de app.css declara en `--taap-ct-*` cada par texto-superficie de la
 * paleta (claro y oscuro), formato `#hex #hex`. Este test extrae la tabla
 * del CSS en vivo (la misma que compila Vite) y falla si cualquier par no
 * alcanza 4.5:1 (WCAG 2.1 AA para texto normal, luminancia relativa).
 */
final class ContrastTokenTest extends TestCase
{
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $this->assertMatchesRegularExpression('/^[0-9a-fA-F]{6}$/', $hex, "hex invalido: $hex");

        $channels = [];
        for ($i = 0; $i < 3; $i++) {
            $c = hexdec(substr($hex, $i * 2, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public function test_allDeclaredTextSurfacePairsReachWcagAa(): void
    {
        $css = file_get_contents(__DIR__ . '/../../resources/css/app.css');
        $this->assertNotFalse($css, 'app.css no encontrado');

        // Pares declarados: --taap-ct-<nombre>: <texto> <superficie>;
        preg_match_all('/--taap-ct-([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s+(#[0-9a-fA-F]{6})/', $css, $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(8, count($m), 'tabla --taap-ct-* vaciada o formato inesperado');

        $bad = [];
        foreach ($m as $pair) {
            [, $name, $text, $surface] = $pair;
            $l1 = max($this->luminance($text), $this->luminance($surface));
            $l2 = min($this->luminance($text), $this->luminance($surface));
            $ratio = ($l1 + 0.05) / ($l2 + 0.05);
            if ($ratio < 4.5) {
                $bad[] = sprintf('%s (%s on %s = %.2f:1)', $name, $text, $surface, $ratio);
            }
        }

        $this->assertSame([], $bad, 'pares por debajo de AA 4.5:1 — ' . implode(' · ', $bad));
    }
}
