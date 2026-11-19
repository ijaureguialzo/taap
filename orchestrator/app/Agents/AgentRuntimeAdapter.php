<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;

/**
 * Contrato de adaptador de runtime (design.md D2). Cada implementacion
 * encapsula la SUPERFICIE NATIVA del runtime; la logica transversal
 * (colas, estado, eventos) vive en el orquestador.
 */
interface AgentRuntimeAdapter
{
    /**
     * Establece (o re-establece) la conexion con el runtime del agente.
     * Pi: lector+escritor del RPC JSONL; Hermes: sin estado (HTTP).
     */
    public function attach(Agent $agent): void;

    /** Suelta la conexion sin detener el contenedor. */
    public function detach(Agent $agent): void;

    /** Si la conexion con el runtime esta viva en este momento. */
    public function isAttached(Agent $agent): bool;

    /**
     * Recoge los eventos disponibles del runtime (no bloqueante).
     * El bridge lo llama en cada vuelta del bucle.
     *
     * @return AgentEvent[]
     */
    public function drain(Agent $agent): array;

    /** Entrega un mensaje de usuario a la cola FIFO del agente. */
    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void;

    /**
     * Variante sin adjuntar (para workers de cola): escribe el prompt en la
     * superficie del runtime sin spawneaar coprocesos. Pi: append al fichero
     * IPC; el bridge entrega. Hermes: igual que sendUserMessage.
     */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void;

    /** Resuelve una aprobacion pendiente. */
    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void;

    /** Reaplica la configuracion (modelo, MCP, prompt) sin perder la sesion. */
    public function applyConfig(Agent $agent): void;

    /** Estado actual del agente (el bridge lo usa para sincronizar). */
    public function status(Agent $agent): AgentStatus;

    /** Detenidamente: mata la sesion/runtime (el contenedor sigue si auto_restart). */
    public function destroy(Agent $agent): void;
}
