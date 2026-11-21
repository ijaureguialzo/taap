<?php

namespace App\Enums;

/**
 * Runtimes de agente soportados. La fuente de verdad de las capacidades
 * de cada uno es el registro `taap.runtimes` (config/taap.php): añadir un
 * runtime = añadir un case + una entrada del registro + su adaptador
 * (design.md D2/D12).
 */
enum AgentRuntime: string
{
    case Pi = 'pi';
    case Hermes = 'hermes';
    case Claude = 'claude';
    case Codex = 'codex';
    case OpenCode = 'opencode';

    /**
     * Runtimes ofrecidos al crear agentes: los que tienen entrada en el
     * registro (con imagen y adaptador). Valores (para validacion `in:`).
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_map(
            fn (self $r) => $r->value,
            array_values(array_filter(self::cases(), fn (self $r) => $r->registry() !== null)),
        );
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
     * Entrada completa del registro (null si el runtime no esta registrado).
     *
     * @return array<string, mixed>|null
     */
    public function registry(): ?array
    {
        return config("taap.runtimes.{$this->value}");
    }

    /**
     * Superficie del orquestador hacia el runtime: `rpc-stdio` (stream
     * persistente por stdio; adaptadores StdioRpcAdapter) o `api-server`
     * (HTTP/SSE; adaptadores sin estado de stream).
     */
    public function mode(): ?string
    {
        return $this->registry()['mode'] ?? null;
    }

    /**
     * Capabilities declaradas en el registro (design.md D12). Vocabulario:
     *   - formato del modelo que consume el runtime: `openai` | `anthropic`
     *   - gestion de sesion: `native` (el runtime la mantiene) | `turn`
     *   - aplicacion de la config: `restart` | `next-turn`
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->registry()['capabilities'] ?? [];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Formatos de API de modelo que consume este runtime (design D12:
     * capabilities del registro). La mayoria consume uno; OpenCode consume
     * AMBOS (openai-compatible y Anthropic): su provider custom elige el
     * paquete npm según el tipo del proveedor.
     *
     * @return list<string> `openai` y/o `anthropic`
     */
    public function modelFormats(): array
    {
        $caps = $this->capabilities();
        $out = [];
        if (in_array('openai', $caps, true)) {
            $out[] = 'openai';
        }
        if (in_array('anthropic', $caps, true)) {
            $out[] = 'anthropic';
        }

        return $out;
    }

    /**
     * Formato principal (el primero declarado) o null si el runtime no
     * declara ninguno. Para runtimes de un solo formato es EL formato;
     * los duales (OpenCode) usan `modelFormats()`.
     */
    public function modelFormat(): ?string
    {
        return $this->modelFormats()[0] ?? null;
    }

    /**
     * Tipos de proveedor compatibles con los formatos que consume este
     * runtime (spec model-management: los incompatibles se excluyen de la
     * seleccion). `anthropic` solo si el runtime declara el formato
     * `anthropic`; `openai`/`other` si declara `openai` o no declara
     * formatos (design v1.0: `other` se trata como OpenAI; un runtime sin
     * formatos es path defensivo de robustez).
     *
     * Una sola fuente de verdad: la usan el filtro y la validacion de la
     * web (Livewire) y los de la API v1 (ios-model-runtime-compatibility).
     *
     * @return list<string>
     */
    public function compatibleProviderTypes(): array
    {
        $formats = $this->modelFormats();
        $types = [];
        if (in_array('openai', $formats, true) || $formats === []) {
            $types = ['openai', 'other'];
        }
        if (in_array('anthropic', $formats, true)) {
            $types[] = 'anthropic';
        }

        return $types;
    }
}
