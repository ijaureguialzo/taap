<?php

namespace App\Agents;

/**
 * Evento normalizado de runtime. Es EL modelo que consumen el cliente web
 * y la app iOS; los adaptadores traducen las superficies nativas a este.
 *
 * Tipos:
 *  - message.delta         payload: {run_id, content}
 *  - message.final         payload: {run_id, content}
 *  - tool.start            payload: {run_id, tool, args}
 *  - tool.result           payload: {run_id, tool, ok, summary}
 *  - approval.request      payload: {run_id, id, prompt, options}
 *  - approval.resolved     payload: {run_id, id, approved, note}
 *  - status.change         payload: {status, detail?}
 *  - run.start             payload: {run_id}
 *  - run.end               payload: {run_id, ok}
 *  - error                 payload: {message}
 */
class AgentEvent
{
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?\DateTimeImmutable $at = null,
    ) {
        $this->at ??= new \DateTimeImmutable();
    }

    public static function messageDelta(?string $runId, string $content): self
    {
        return new self('message.delta', ['run_id' => $runId, 'content' => $content]);
    }

    public static function messageFinal(?string $runId, string $content): self
    {
        return new self('message.final', ['run_id' => $runId, 'content' => $content]);
    }

    public static function toolStart(?string $runId, string $tool, array $args = []): self
    {
        return new self('tool.start', ['run_id' => $runId, 'tool' => $tool, 'args' => $args]);
    }

    public static function toolResult(?string $runId, string $tool, bool $ok, string $summary = ''): self
    {
        return new self('tool.result', ['run_id' => $runId, 'tool' => $tool, 'ok' => $ok, 'summary' => $summary]);
    }

    public static function approvalRequest(?string $runId, string $id, string $prompt, array $options = []): self
    {
        return new self('approval.request', ['run_id' => $runId, 'id' => $id, 'prompt' => $prompt, 'options' => $options]);
    }

    public static function approvalResolved(?string $runId, string $id, bool $approved, ?string $note = null): self
    {
        return new self('approval.resolved', ['run_id' => $runId, 'id' => $id, 'approved' => $approved, 'note' => $note]);
    }

    public static function statusChange(string $status, ?string $detail = null): self
    {
        return new self('status.change', array_filter(['status' => $status, 'detail' => $detail]));
    }

    public static function runStart(string $runId): self
    {
        return new self('run.start', ['run_id' => $runId]);
    }

    public static function runEnd(string $runId, bool $ok = true): self
    {
        return new self('run.end', ['run_id' => $runId, 'ok' => $ok]);
    }

    public static function error(string $message): self
    {
        return new self('error', ['message' => $message]);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'at' => $this->at->format('c'),
        ];
    }
}
