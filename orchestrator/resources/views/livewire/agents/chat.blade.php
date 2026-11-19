<div class="agent-chat" data-agent-id="{{ $agent->id }}" style="display:flex;flex-direction:column;height:100%;">

    {{-- Historial + streaming --}}
    <div class="chat-scroll flex-grow-1 overflow-auto border rounded-2 p-3 bg-white mb-3" id="chat-box" style="min-height: 320px">
        @if (count($history) === 0 && $streaming === null)
            <div class="text-center text-muted py-5">{{ __('chat.empty') }}</div>
        @endif

        @foreach ($history as $msg)
            @if ($msg['author'] === 'user')
                <div class="d-flex justify-content-end mb-3">
                    <div class="bubble bubble-user">
                        <div class="small text-muted mb-1">{{ __('chat.you') }} · {{ $msg['at'] }}</div>
                        <div class="message-content">{{ $msg['content'] }}</div>
                    </div>
                </div>
            @else
                <div class="d-flex mb-3">
                    <div class="bubble bubble-agent">
                        <div class="small text-muted mb-1">{{ $agent->name }} · {{ $msg['at'] }}</div>
                        <div class="message-content">{{ $msg['content'] }}</div>
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Burbuja en streaming (deltas) --}}
        @if ($streaming !== null)
            <div class="d-flex mb-3">
                <div class="bubble bubble-agent">
                    <div class="small text-muted mb-1">
                        {{ $agent->name }} ·
                        @if ($activity)
                            <span wire:key="activity" class="text-info">{{ $activity['kind'] === 'tool' ? __('chat.tool').': '.$activity['label'] : __('chat.thinking') }}</span>
                        @else
                            <span class="text-info">{{ __('chat.streaming') }}</span>
                        @endif
                    </div>
                    <div class="message-content" wire:text="streaming"></div>
                </div>
            </div>
        @endif

        {{-- Aprobacion pendiente --}}
        @if ($pendingApproval)
            <div class="card border-warning mb-3" wire:key="approval-{{ $pendingApproval }}">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">{{ __('chat.approval') }}</span>
                        <div>
                            <button class="btn btn-sm btn-success me-2" wire:click="approve(true)">{{ __('chat.approve') }}</button>
                            <button class="btn btn-sm btn-outline-danger" wire:click="approve(false)">{{ __('chat.deny') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Indicador de actividad de herramientas --}}
    @if ($activity && $streaming !== null)
        <div class="small text-info mb-2" wire:key="activity-2">
            ⚙ {{ $activity['kind'] === 'tool' ? __('chat.tool').': '.$activity['label'] : __('chat.run') }}
            {{ $activity['detail'] ? '· '.$activity['detail'] : '' }}
        </div>
    @endif

    {{-- Entrada --}}
    <div class="input-group">
        <input type="text" wire:model.live.400ms="content" class="form-control"
               placeholder="{{ __('chat.placeholder') }}"
               @disabled(! $agent->status->isRunning() && $agent->status !== \App\Enums\AgentStatus::Starting)
               wire:keydown.enter="send">
        <button class="btn btn-primary" wire:click="send"
                @disabled(! $agent->status->isRunning() && $agent->status !== \App\Enums\AgentStatus::Starting || trim($content) === '')>
            {{ __('chat.send') }}
        </button>
    </div>
    @if (! $agent->status->isRunning() && $agent->status !== \App\Enums\AgentStatus::Starting)
        <div class="form-text mt-1">{{ __('chat.offline') }}</div>
    @endif

    <script>
        // Streaming en vivo: canal privado `agent.{id}`.
        (() => {
            const root = document.querySelector('.agent-chat');
            if (!root || !window.TaapEcho || !window.TaapPusher) return;
            const wire = Livewire.find(root.id);
            if (!wire) return;
            const id = root.dataset.agentId;
            const channel = window.TaapEcho.private(`agent.${id}`);
            channel.listen('agent-event', (payload) => {
                wire.agentEvent(payload);
            });
        })();

        // Autodesplazamiento al recibir contenido nuevo.
        const chatBox = document.querySelector('#chat-box');
        if (chatBox) {
            new MutationObserver(() => {
                chatBox.scrollTop = chatBox.scrollHeight;
            }).observe(chatBox, { childList: true, subtree: true, characterData: true });
        }
    </script>
</div>
