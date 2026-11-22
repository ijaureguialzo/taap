<?php

namespace App\Livewire\Agents;

use App\Agents\ChatService;
use App\Models\Agent;
use App\Models\ChatMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Cliente de chat (web): historial + streaming en vivo por el canal
 * Reverb `agent.{id}`. Los deltas no se persisten; el bloque en streaming
 * se asienta con el message.final.
 */
class Chat extends Component
{
    public Agent $agent;

    public string $content = '';

    /** Texto parcial del mensaje que esta escribiendo el agente. */
    public ?string $streaming = null;

    /**
     * Progreso del run actual (herramienta en uso, etc.).
     *
     * @var array<string, mixed>|null
     */
    public ?array $activity = null;

    /** id de la aprobacion pendiente (approval.request). */
    public ?string $pendingApproval = null;

    /**
     * Detalle de la aprobacion pendiente para la tarjeta: prompt legible y
     * opciones (Claude: aprobar / «permitir siempre» / denegar).
     *
     * @var array{prompt: ?string, options: list<array{value: string, label: string}>}|null
     */
    public ?array $pendingApprovalInfo = null;

    /**
     * Eventos de proceso recientes (tool.start/end, run.*) para la UI.
     *
     * @var list<array{type: string, label: string, at: string}>
     */
    public array $events = [];

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * Acepta el contenido explicito del cliente (Enter/click via JS) para
     * que no dependa del round-trip de `wire:model` (debounce 400ms):
     * pulsar Enter justo tras teclear no debe perder el mensaje.
     */
    public function send(?string $content = null): void
    {
        $content = trim($content ?? $this->content);
        if ($content === '') {
            return;
        }

        $user = Auth::user();
        if (! $user->isAdmin() && $user->id !== $this->agent->owner_id) {
            return;
        }

        try {
            app(ChatService::class)->send($this->agent, $user, $content);
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->content = '';
        $this->streaming = '';

        // JS lo usa para vaciar el input enfocado (Livewire no pisa el
        // DOM de un input enfocado: sin esto el texto viejo seguiria
        // visible tras el envio).
        $this->dispatch('message-sent');
    }

    public function approve(bool $approved, ?string $note = null): void
    {
        $approvalId = $this->pendingApproval;
        if (! $approvalId) {
            return;
        }

        $this->pendingApproval = null;
        $this->pendingApprovalInfo = null;

        app(ChatService::class)->resolveApproval(
            $this->agent,
            Auth::user(),
            $approvalId,
            $approved,
            $note,
        );
    }

    /**
     * Recibe los eventos del canal `agent.{id}` (via JS/Echo).
     *
     * @param  array<string, mixed>  $payload
     */
    public function agentEvent(array $payload): void
    {
        if (($payload['agent_id'] ?? null) != $this->agent->id) {
            return;
        }

        $type = $payload['type'] ?? '';
        $data = $payload['payload'] ?? [];

        switch ($type) {
            case 'message.delta':
                $this->streaming = ($this->streaming ?? '').($data['content'] ?? ($data['text'] ?? ''));
                break;

            case 'message.final':
                // El mensaje ya esta persistido; recargamos el historial.
                $this->streaming = null;
                // Si el agente ha seguido (p. ej. tras el timeout de una
                // aprobacion), la tarjeta pendiente deja de serlo.
                $this->pendingApproval = null;
                $this->pendingApprovalInfo = null;
                $this->refreshHistory();
                break;

            case 'run.start':
                $this->streaming = $this->streaming ?? '';
                $this->activity = ['kind' => 'run', 'label' => __('chat.run')];
                $this->pushEvent($type, $data);
                break;

            case 'run.end':
                $this->activity = null;
                $this->pushEvent($type, $data);
                break;

            case 'tool.start':
                $this->activity = ['kind' => 'tool', 'label' => $data['tool'] ?? $data['name'] ?? 'tool', 'detail' => $data['detail'] ?? null];
                $this->pushEvent($type, $data);
                break;

            case 'tool.end':
                $this->activity = null;
                $this->pushEvent($type, $data);
                break;

            case 'approval.request':
                $this->pendingApproval = $data['id'] ?? ($data['approval_id'] ?? null);
                $options = [];
                foreach ((array) ($data['options'] ?? []) as $opt) {
                    if (is_array($opt) && isset($opt['value'])) {
                        $options[] = [
                            'value' => (string) $opt['value'],
                            'label' => (string) ($opt['label'] ?? $opt['value']),
                        ];
                    }
                }
                $this->pendingApprovalInfo = [
                    'prompt' => isset($data['prompt']) ? (string) $data['prompt'] : null,
                    'options' => $options,
                ];
                $this->pushEvent($type, $data);
                break;

            case 'approval.resolved':
                if (($data['id'] ?? null) === $this->pendingApproval) {
                    $this->pendingApproval = null;
                    $this->pendingApprovalInfo = null;
                }
                $this->pushEvent($type, $data);
                break;

            case 'status.change':
                // El badge del detalle se actualiza por su propio listener.
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function pushEvent(string $type, array $data): void
    {
        $this->events[] = [
            'type' => $type,
            'label' => $data['name'] ?? $data['label'] ?? $type,
            'at' => now()->toIso8601String(),
        ];
        if (count($this->events) > 12) {
            array_shift($this->events);
        }
    }

    protected function refreshHistory(): void
    {
        // Livewire 4 re-renderiza en cada update (render()); la consulta del
        // historial se ejecuta de nuevo con el mensaje finalizado. (v3 usaba
        // forceRefresh(); ya no existe).
    }

    /** @return array<int, array<string, mixed>> */
    protected function history(): array
    {
        return ChatMessage::where('agent_id', $this->agent->id)
            ->latest('id')
            ->take(200)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'author' => $m->author,
                'content' => $m->content,
                'at' => $m->created_at?->toIso8601String(),
                'meta' => $m->meta ?? null,
            ])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.agents.chat', [
            'history' => $this->history(),
        ]);
    }
}
