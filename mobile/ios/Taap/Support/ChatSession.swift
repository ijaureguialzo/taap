import Foundation
import Combine

/// Aprobacion pendiente de un agente (esperando allow/deny del usuario).
struct PendingApproval: Equatable {
    let id: String
    let prompt: String
}

/// Estado del chat de un agente: historial, delta en vivo y aprobacion
/// pendiente.
///
/// Toda la logica de aplicacion de eventos `agent-event` vive aqui (la vista
/// solo renderiza) para que sea testeable sin UI. Los metodos se invocan en
/// la cola principal (ReverbSocket entrega los eventos ahi).
final class ChatSession: ObservableObject {
    @Published var messages: [ChatMessageDTO] = []
    @Published var liveDelta = ""
    @Published var pendingApproval: PendingApproval?

    let agentId: Int
    /// Cliente API: se enlaza al de `AppState` en el `.task` de la vista
    /// (mismo token/baseURL). Es `var` para eso y para inyectar en tests.
    var api: ApiClient
    private var tempId = 10_000
    private var sending = false

    init(agentId: Int, api: ApiClient) {
        self.agentId = agentId
        self.api = api
    }

    // MARK: - Carga / resincronizacion

    /// Carga (o recarga) el historial desde el servidor. Se usa al abrir el
    /// chat y al re-sincronizar tras una reconexion (8.5): lo que paso sin
    /// conexion se recupera de la fuente, no depende de haber visto los
    /// eventos. (Cola principal: ReverbSocket entrega los eventos ahi.)
    func load() async {
        do {
            let history = try await api.history(agentId: agentId)
            self.messages = history
        } catch {
            // Sin conexion: se conserva lo local; el proximo resync lo
            // re-cargara.
        }
    }

    // MARK: - Eventos del canal

    /// Aplica un evento `agent-event` del canal `agent.{id}`.
    func apply(_ event: AgentEventPayload) {
        guard event.agentId == agentId else { return }

        switch event.type {
        case "message.delta":
            if let delta = event.payload?["content"]?.stringValue {
                liveDelta += delta
            }

        case "message.final":
            if let content = event.payload?["content"]?.stringValue, !content.isEmpty {
                liveDelta = ""
                appendAgentMessage(content, dedupe: true)
            } else {
                liveDelta = ""
            }

        case "run.end":
            // Si el final no llego (el runtime murio a mitad de frase), el
            // delta acumulado es lo que se muestra.
            if !liveDelta.isEmpty {
                let content = liveDelta
                liveDelta = ""
                appendAgentMessage(content, dedupe: true)
            }

        case "approval.request":
            if let id = event.payload?["id"]?.stringValue,
               let prompt = event.payload?["prompt"]?.stringValue {
                pendingApproval = PendingApproval(id: id, prompt: prompt)
            }

        case "approval.resolved":
            pendingApproval = nil

        case "status.change", "run.start", "tool.start", "tool.result", "error":
            // La vista refresca el estado del agente por REST cuando lo
            // necesita; el chat no cambia con estos.
            break

        default:
            break
        }
    }

    // MARK: - Acciones

    /// Envía un mensaje al agente. La confirmacion del servidor (REST) es la
    /// burbuja de usuario; los deltas/final del canal llenan la respuesta.
    func send(_ content: String) async throws {
        let text = content.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !sending else { return }
        sending = true
        defer { sending = false }
        do {
            let sent = try await api.send(agentId: agentId, content: text)
            messages.append(sent)
        } catch {
            throw SendError.failed
        }
    }

    /// Resuelve (allow/deny) la aprobacion pendiente.
    func resolveApproval(approved: Bool) async throws {
        guard let approval = pendingApproval else { return }
        do {
            try await api.resolveApproval(agentId: agentId, approvalId: approval.id, approved: approved)
            pendingApproval = nil
        } catch {
            throw SendError.failed
        }
    }

    enum SendError: LocalizedError {
        case failed
        var errorDescription: String? { "No se pudo enviar" }
    }

    // MARK: - Privados

    private func appendAgentMessage(_ content: String, dedupe: Bool) {
        if dedupe, let last = messages.last, last.isAgent, last.content == content {
            return
        }
        tempId += 1
        messages.append(ChatMessageDTO(
            id: tempId,
            agentId: agentId,
            author: "agent",
            content: content,
            meta: nil,
            createdAt: nil,
        ))
    }
}
