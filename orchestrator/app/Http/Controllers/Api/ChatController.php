<?php

namespace App\Http\Controllers\Api;

use App\Agents\ChatService;
use App\Agents\Timeline;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class ChatController extends Controller
{
    public function __construct(protected ChatService $chat) {}

    /**
     * Historial del chat (mensajes + aprobaciones registradas).
     * Los deltas/tools van por el canal WSS; aqui va lo persistente.
     */
    public function messages(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize($request, $agent);

        $before = $request->query('before') ? (int) $request->query('before') : null;
        $limit = min((int) $request->query('limit', 200), 500);

        $messages = $agent->messages()
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->where(function ($q) {
                // Synthetic = mensajes que el sistema inyecta (aprobaciones).
                // La clave falta en los mensajes reales: `meta->synthetic` es
                // NULL en PG y `!= true` sobre NULL no filtra (queda oculto).
                $q->whereNull('meta')
                    ->orWhereNull('meta->synthetic')
                    ->orWhere('meta->>synthetic', 'false');
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
                'created_at' => $m->created_at->toIso8601String(),
            ])->values(),
            'pending_approvals' => $agent->events()
                ->where('type', 'approval.request')
                ->whereNot('payload->resolved', true)
                ->latest('occurred_at')
                ->limit(50)
                ->get()
                ->map(fn ($e) => [
                    'id' => ($e->payload)['id'] ?? null,
                    'prompt' => ($e->payload)['prompt'] ?? '',
                    'options' => ($e->payload)['options'] ?? [],
                    'created_at' => $e->occurred_at->toIso8601String(),
                ])
                // El bridge puede re-emitir el mismo request al re-adjuntar;
                // se deduplica por id (queda el mas reciente).
                ->filter(fn ($a) => $a['id'] !== null)
                ->unique(fn ($a) => $a['id'])
                ->values(),
        ]);
    }

    public function send(Request $request, Agent $agent): JsonResponse
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
        // eventos del agente para quienes solo escuchan WSS). API 13.x:
        // on()->as()->with() + send() (queda en cola; Broadcast::on no
        // acepta broadcast($nombre, $payload): eso seria un canal llamado
        // 'agent-event' sin payload).
        Broadcast::on('agent.'.$agent->id)
            ->as('agent-event')
            ->with([
                'type' => 'message.final',
                'payload' => ['content' => $content, 'author' => 'user'],
                'at' => now()->toIso8601String(),
            ])
            ->send();

        return response()->json([
            'message' => [
                'id' => $message->id,
                'author' => 'user',
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function approve(Request $request, Agent $agent): JsonResponse
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

    /**
     * Lineas de tiempo (7.2): ventanas de trabajo sintetizadas desde
     * agent_events, para la UI de "lo que hizo mientras no estabas"
     * (web e iOS, spec ios-client: chat parity).
     */
    public function timeline(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize($request, $agent);

        $hours = min(max((int) $request->query('hours', config('taap.timeline.hours', 24)), 1), 72);
        $windows = app(Timeline::class)->windows($agent->id, now()->subHours($hours));

        return response()->json([
            'agent_status' => $agent->status->value,
            'windows' => collect($windows)->map(fn ($w) => [
                'start' => $w['start']->toIso8601String(),
                'end' => $w['end']?->toIso8601String(),
                'open' => $w['open'],
                'duration' => app(Timeline::class)->humanDuration($w),
                'tool_calls' => $w['tool_calls'],
                'tools' => $w['tools'],
                'approvals' => $w['approvals'],
                'errors' => $w['errors'],
                'messages' => $w['messages'],
                'note' => $w['note'],
            ])->values(),
        ]);
    }

    protected function authorize(Request $request, Agent $agent): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $agent->owner_id !== $user->id) {
            abort(404);
        }
    }
}
