<?php

namespace App\Agents;

use App\Models\AgentEventRow;
use Carbon\CarbonInterface;

/**
 * Linea de tiempo (spec agent-chat: "Activity timeline"; design D6).
 *
 * Sintetiza ventanas de trabajo a partir de agent_events persistidas:
 * una ventana abre con run.start (o con cualquier evento tras un hueco
 * >= gap) y cierra con run.end o al agotarse el hueco. Permite al usuario
 * volver y ver "lo que hizo mientras no estaba", rematando en el estado
 * actual (que lo pinta la vista, no la sintesis).
 */
final class Timeline
{
    /**
     * Ventanas de trabajo recientes (mas antiguas primero).
     *
     * @param  CarbonInterface|null  $since  limite inferior (default: 24h)
     * @param  int  $maxWindows  cuantas devolver (las mas recientes)
     * @return list<array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}>
     */
    public function windows(int $agentId, ?CarbonInterface $since = null, int $maxWindows = 10): array
    {
        $since ??= now()->subHours((int) config('taap.timeline.hours', 24));
        $gapSeconds = max(1, (int) config('taap.timeline.gap_minutes', 15) * 60);

        $rows = AgentEventRow::query()
            ->where('agent_id', $agentId)
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get();

        /** @var list<array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}> $windows */
        $windows = [];
        $current = null;
        /** @var array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}|null $current */
        $prevAt = null;

        foreach ($rows as $row) {
            $openNew = $current === null
                || $row->type === 'run.start'
                || ($prevAt !== null && $row->occurred_at->diffInSeconds($prevAt) >= $gapSeconds);

            if ($openNew) {
                if ($current !== null) {
                    $windows[] = $this->close($current, $row->occurred_at, $gapSeconds);
                }
                $current = [
                    'start' => $row->occurred_at,
                    'end' => null,
                    'open' => true,
                    'tool_calls' => 0,
                    'tools' => [],
                    'approvals' => 0,
                    'errors' => 0,
                    'messages' => 0,
                    'note' => null,
                ];
            }

            $this->fold($current, $row->type, $row->payload, $row->occurred_at);

            $prevAt = $row->occurred_at;
        }

        if ($current !== null) {
            $windows[] = $this->close($current, $prevAt ?? now(), $gapSeconds);
        }

        return array_slice($windows, -$maxWindows);
    }

    /**
     * @param  array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}  $window
     * @param  array<string, mixed>  $payload
     */
    private function fold(array &$window, string $type, array $payload, CarbonInterface $at): void
    {
        match ($type) {
            'run.end' => $window['end'] ??= $at,
            'tool.start' => $window['tool_calls']++,
            'message.final' => $window['messages']++,
            'approval.request' => $window['approvals']++,
            'error' => $window['errors']++,
            default => null,
        };

        $tool = $payload['tool'] ?? null;
        if (is_string($tool) && $tool !== '' && ! in_array($tool, $window['tools'], true)) {
            $window['tools'][] = $tool;
        }

        if ($type === 'run.end' && is_string($payload['summary'] ?? null)) {
            $window['note'] = $payload['summary'];
        }
    }

    /**
     * Duracion legible de una ventana ("1 h 05 min" / "12 min" /
     * "en curso" si sigue abierta).
     *
     * @param  array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}  $window
     */
    public function humanDuration(array $window): string
    {
        $to = $window['end'] ?? ($window['open'] ? now() : null);
        if ($to === null) {
            return now()->diffForHumans($window['start']);
        }
        $minutes = (int) floor($to->diffInSeconds($window['start']) / 60);
        if ($minutes < 1) {
            return '<1 min';
        }
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' min';
    }

    /**
     * Cierra una ventana: abierta solo si no termina con run.end y su
     * ultimo evento fue reciente (<= gap).
     *
     * @param  array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}  $window
     * @return array{start: CarbonInterface, end: ?CarbonInterface, open: bool, tool_calls: int, tools: list<string>, approvals: int, errors: int, messages: int, note: ?string}
     */
    private function close(array $window, CarbonInterface $lastAt, int $gapSeconds): array
    {
        $window['open'] = $window['end'] === null && $lastAt->diffInSeconds(now()) < $gapSeconds;

        return $window;
    }
}
