<?php

namespace App\Services;

use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda full-text sobre mensajes del chat de un agente (chat-search-export).
 * Postgres: tsvector + ts_rank (ranking por relevancia). Otros drivers:
 * LIKE (sqlite; mismo contrato, misma escala de datos).
 */
class ChatSearch
{
    /** @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} */
    public function search(int $agentId, string $query, int $perPage = 50, int $page = 1): array
    {
        $query = trim($query);
        $perPage = min(max($perPage, 1), 100);

        if ($query === '') {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1];
        }

        $base = ChatMessage::with('user')->where('agent_id', $agentId);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $base->whereRaw("body_tsvector @@ websearch_to_tsquery('simple', ?)", [$query])
                ->orderByRaw("ts_rank(body_tsvector, websearch_to_tsquery('simple', ?)) DESC", [$query]);
        } else {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $base->where('content', 'like', '%'.$escaped.'%')
                ->orderByDesc('created_at');
        }

        $paginator = $base->paginate($perPage, ['*'], 'page', $page);

        $messages = collect($paginator->items())->map(fn (ChatMessage $m) => [
            'id' => $m->id,
            'agent_id' => $m->agent_id,
            'author' => $m->author,
            'content' => $m->content,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->values()->all();

        return [
            'data' => $messages,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /** Exportación completa (Markdown o JSON) de la historia de mensajes. */
    public function export(int $agentId, string $format): array
    {
        $messages = ChatMessage::where('agent_id', $agentId)->orderBy('created_at')->get();

        if ($format === 'md') {
            $lines = ['# '.($messages->first()?->agent?->name ?? 'chat')];
            $lines[] = '';
            $lines[] = 'Exportado '.now()->toIso8601String().' — '.count($messages).' mensajes';
            $lines[] = '';

            foreach ($messages as $m) {
                $role = $m->author === 'user' ? '**Usuario**' : ($m->author === 'agent' ? '**Agente**' : '**Sistema**');
                $lines[] = '---';
                $lines[] = sprintf('### %s — %s', $role, $m->created_at?->format('Y-m-d H:i:s') ?? '');
                $lines[] = $m->content;
                $lines[] = '';
            }

            return ['format' => 'md', 'content' => implode("\n", $lines)];
        }

        return [
            'format' => 'json',
            'content' => [
                'agent_id' => $agentId,
                'exported_at' => now()->toIso8601String(),
                'messages' => $messages->map(fn (ChatMessage $m) => [
                    'id' => $m->id,
                    'author' => $m->author,
                    'content' => $m->content,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->values(),
            ],
        ];
    }
}
