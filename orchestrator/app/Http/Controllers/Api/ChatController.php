<?php

namespace App\Http\Controllers\Api;

use App\Agents\ChatService;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class ChatController extends Controller
{
    public function __construct(protected ChatService $chat)
    {
    }

    /**
     * Historial del chat (mensajes + aprobaciones registradas).
     * Los deltas/tools van por el canal WSS; aqui va lo persistente.
     */
    public function messages(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);

        $before = $request->query('before') ? (int) $request->query('before') : null;
        $limit = min((int) $request->query('limit', 200), 500);

        $messages = $agent->messages()
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->where(function ($q) {
                $q->whereNull('meta')->orWhereNot('meta->synthetic', true);
            })
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse();

        return response()->json([
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'author' => $m->author,
                'user' => $m->user?->only(['id', 'name']),
                'content' => $m->content,
                'meta' => $m->meta,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'pending_approvals' => $agent->events()
                ->where('type', 'approval.request')
                ->whereNot('payload->resolved', true)
                ->latest('occurred_at')
                ->limit(10)
                ->get()
                ->map(fn ($e) => [
                    'id' => ($e->payload)['id'] ?? null,
                    'prompt' => ($e->payload)['prompt'] ?? '',
                    'options' => ($e->payload)['options'] ?? [],
                    'created_at' => $e->occurred_at?->toIso8601String(),
                ]),
        ]);
    }

    public function send(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);

        $content = (string) $request->validate(['content' => ['required', 'string', 'max:32000']])['content'];

        try {
            $message = $this->chat->send($agent->refresh(), $request->user(), $content);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        // El mensaje de usuario tambien viaja por el canal (paridad con los
        // eventos del agente para quienes solo escuchan WSS)
        Broadcast::on('agent.' . $agent->id)->broadcast('agent-event', [
            'type' => 'message.final',
            'payload' => ['content' => $content, 'author' => 'user'],
            'at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'message' => [
                'id' => $message->id,
                'author' => 'user',
                'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function approve(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);

        $data = $request->validate([
            'approval_id' => ['required', 'string'],
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->chat->resolveApproval(
            $agent->refresh(),
            $request->user(),
            $data['approval_id'],
            (bool) $data['approved'],
            $data['note'] ?? null
        );

        return response()->json(['ok' => true]);
    }

    protected function authorize(Request $request, Agent $agent): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $agent->owner_id !== $user->id) {
            abort(404);
        }
    }
}
