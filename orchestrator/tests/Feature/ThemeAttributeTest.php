<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * spa-theme-persistence (design D4): la cookie la escribe JavaScript en texto
 * plano, asi que los tests usan withUnencryptedCookie (withCookie cifra el
 * valor antes de enviarlo, simulando una cookie que el MISMO app setea).
 * el HTML servido lleva el tema cuando
 * la cookie taap-theme lo declara, para que la navegacion wire:navigate lo
 * preserve (su sync de atributos borra lo que el <html> recibido no trae).
 */
class ThemeAttributeTest extends TestCase
{
    public function test_html_carries_theme_from_cookie(): void
    {
        $html = $this->withUnencryptedCookie('taap-theme', 'dark')->get('/login')->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-bs-theme="dark"/', $html);
    }

    public function test_html_carries_light_cookie(): void
    {
        $html = $this->withUnencryptedCookie('taap-theme', 'light')->get('/login')->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-bs-theme="light"/', $html);
    }

    public function test_html_has_no_theme_attribute_without_cookie(): void
    {
        $html = $this->get('/login')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*data-bs-theme/', $html);
    }

    public function test_html_ignores_unknown_cookie_values(): void
    {
        $html = $this->withUnencryptedCookie('taap-theme', 'neon')->get('/login')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*data-bs-theme/', $html);
    }
}
