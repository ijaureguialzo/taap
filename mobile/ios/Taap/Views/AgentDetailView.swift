import SwiftUI

struct AgentDetailView: View {
    @EnvironmentObject var appState: AppState
    let agentId: Int

    init(agentId: Int) {
        self.agentId = agentId
        // Se re-enlaza al api de AppState en el `.task` (mismo token/URL).
        _session = StateObject(wrappedValue: ChatSession(agentId: agentId, api: ApiClient()))
    }

    @State private var agent: AgentDTO?
    @State private var draft = ""
    /// Chat (historial + deltas + aprobacion): logica en ChatSession
    /// (testeable), la vista solo renderiza.
    @StateObject private var session: ChatSession
    // El canal lo gestiona AppState (config Reverb real + token compartidos).
    @State private var showSettings = false
    @State private var showTimeline = false
    @State private var deleting = false
    @State private var live = false
    @State private var windows: [TimelineWindowDTO] = []
    @State private var hasBeenLive = false

    var body: some View {
        VStack(spacing: 0) {
            header
            Divider()
            messageList
            Divider()
            composer
        }
        .navigationTitle(agent?.name ?? "…")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Menu {
                    Button {
                        showSettings = true
                    } label: {
                        Label(appState.t("settings.title"), systemImage: "gear")
                    }
                    Button {
                        showTimeline = true
                    } label: {
                        Label(appState.t("timeline.title"), systemImage: "clock.arrow.circlepath")
                    }
                    Button {
                        Task { await lifecycle("start") }
                    } label: {
                        Label(appState.t("agents.start"), systemImage: "play.fill")
                    }
                    Button {
                        Task { await lifecycle("stop") }
                    } label: {
                        Label(appState.t("agents.stop"), systemImage: "stop.fill")
                    }
                    Button(role: .destructive) {
                        deleting = true
                    } label: {
                        Label(appState.t("settings.delete"), systemImage: "trash")
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
            }
        }
        .sheet(isPresented: $showSettings) {
            AgentSettingsSheet(agentId: agentId) { updated in
                agent = updated
            }
        }
        .sheet(isPresented: $showTimeline) {
            TimelineSheet(agentId: agentId, windows: windows) { updated in
                windows = updated
            }
            .presentationDetents([.medium, .large])
        }
        .alert(appState.t("settings.delete"), isPresented: $deleting) {
            Button("OK", role: .destructive) {
                Task {
                    try? await appState.api.deleteAgent(id: agentId)
                    await appState.refreshAgents()
                    dismissSelf()
                }
            }
            Button("Cancelar", role: .cancel) {}
        }
        .task {
            await load()
            wireChannel()
            // Comodidad de tests: abre la hoja de config directamente.
            if ProcessInfo.processInfo.arguments.contains("--taap-settings") {
                showSettings = true
            }
        }
        .onDisappear {
            appState.channelManager.close()
        }
    }

    // MARK: - Cabecera

    private var header: some View {
        HStack(spacing: 8) {
            Circle()
                .fill(statusColor)
                .frame(width: 9, height: 9)
            Text(statusText)
                .font(.subheadline.weight(.medium))
            Spacer()
            if let model = agent?.model {
                Text(model.displayName)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            HStack(spacing: 3) {
                Circle()
                    .fill(live ? TaapColor.success : TaapColor.textSecondary)
                    .frame(width: 7, height: 7)
                Text(live ? "WSS" : "…")
                    .font(.caption2)
                    .foregroundStyle(live ? TaapColor.success : TaapColor.textSecondary)
            }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
    }

    private var statusText: String {
        guard let agent else { return "…" }
        return appState.t("status.\(agent.status)")
    }

    private var statusColor: Color {
        TaapColor.status(agent?.status ?? "")
    }

    private var isOwner: Bool {
        let ownerId: Int? = agent.flatMap { $0.owner }.flatMap { $0.id }
        return ownerId == appState.currentUser?.id
    }

    // MARK: - Mensajes

    private var messageList: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 10) {
                    ForEach(session.messages) { message in
                        MessageBubble(message: message)
                            .id(message.id)
                    }

                    // Delta en vivo (lo que el agente está escribiendo ahora)
                    if !session.liveDelta.isEmpty {
                        MessageBubble(
                            message: ChatMessageDTO(id: -1, agentId: agentId, author: "agent", content: session.liveDelta, meta: nil, createdAt: nil)
                        )
                        .id(-1)
                    }

                    if let pendingApproval = session.pendingApproval {
                        ApprovalBubble(prompt: pendingApproval.prompt, onApprove: {
                            Task { try? await session.resolveApproval(approved: true) }
                        }, onDeny: {
                            Task { try? await session.resolveApproval(approved: false) }
                        })
                    }
                }
                .padding(12)
            }
            .onChange(of: session.messages.count + (session.liveDelta.isEmpty ? 0 : 1)) {
                withAnimation {
                    proxy.scrollTo(session.messages.last?.id ?? -1, anchor: .bottom)
                }
            }
        }
    }

    private var composer: some View {
        HStack(spacing: 8) {
            TextField(appState.t("chat.placeholder"), text: $draft)
                .textFieldStyle(.roundedBorder)
                .onSubmit(send)
                .disabled(agent?.status == "offline" || agent?.status == "stopped")

            Button(action: send) {
                Image(systemName: "paperplane.fill")
                    .foregroundStyle(.white)
                    .padding(8)
                    .background(TaapColor.primary, in: Circle())
            }
            .disabled(draft.trimmingCharacters(in: .whitespaces).isEmpty)
        }
        .padding(12)
    }

    // MARK: - Acciones

    private func load() async {
        // El chat usa su sesión (mismo cliente API que AppState).
        session.api = appState.api
        do {
            async let a: AgentDTO? = appState.api.agent(id: agentId)
            async let t: TimelineResponse? = try? appState.api.timeline(agentId: agentId)
            agent = try await a
            if let t { windows = t.windows }
            await session.load()
        } catch {
            appState.flash(error.localizedDescription, kind: .error)
        }
    }

    private func wireChannel() {
        appState.channelManager.onEvent = { [session] event in
            // Chat: deltas/final/aprobaciones en la sesión.
            session.apply(event)
            // Estado del agente (la lista también lo escucha).
            if event.type == "status.change", let status = event.payload?["status"]?.stringValue {
                _ = status
                Task {
                    let updated = try? await appState.api.agentStatus(id: agentId)
                    if let updated {
                        agent = updated
                        await appState.refreshAgent(id: agentId)
                    }
                }
            }
        }
        // 8.5 (spec ios-client: Reconnection and resync): al reconectar tras
        // una caida (p. ej. modo avion), re-cargamos historial, estado,
        // aprobaciones y lineas de tiempo: lo que paso sin conexion se
        // recupera desde el servidor, no depende de haber visto los eventos.
        appState.channelManager.onLive = { isLive in
            DispatchQueue.main.async {
                live = isLive
                guard isLive else { return }
                // Primera conexion: load() ya cubre el estado inicial.
                if hasBeenLive {
                    Task { await load() }
                }
                hasBeenLive = true
            }
        }
        appState.channelManager.open(agentId: agentId)
        live = appState.channelManager.isLive
    }

    private func send() {
        let content = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !content.isEmpty else { return }
        draft = ""
        Task {
            do {
                try await session.send(content)
            } catch {
                draft = content
                appState.flash(error.localizedDescription, kind: .error)
            }
        }
    }

    private func lifecycle(_ action: String) async {
        do {
            let endpoint = action == "start" ? "start" : "stop"
            var req = URLRequest(url: appState.api.baseURL.appendingPathComponent("api/v1/agents/\(agentId)/\(endpoint)"))
            req.httpMethod = "POST"
            if let token = appState.api.token {
                req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            }
            _ = try await ApiClient.session.data(for: req)
            let updated = try await appState.api.agentStatus(id: agentId)
            agent = updated
            await appState.refreshAgent(id: agentId)
        } catch {
            appState.flash(error.localizedDescription, kind: .error)
        }
    }

    private func dismissSelf() {
        // El MainTabView gestiona la pila; usamos navigation
        NotificationCenter.default.post(name: .taapPopAgent, object: nil)
    }
}

extension Notification.Name {
    static let taapPopAgent = Notification.Name("taapPopAgent")
}

// MARK: - Burbujas

struct MessageBubble: View {
    let message: ChatMessageDTO

    var body: some View {
        HStack {
            if message.isUser { Spacer(minLength: 48) }
            Text(message.content)
                .textSelection(.enabled)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .background(message.isUser ? TaapColor.primary : TaapColor.surfaceElevated, in: RoundedRectangle(cornerRadius: 12))
                .foregroundStyle(message.isUser ? TaapColor.primaryText : TaapColor.textPrimary)
            if !message.isUser { Spacer(minLength: 48) }
        }
    }
}

struct ApprovalBubble: View {
    @EnvironmentObject var appState: AppState
    let prompt: String
    let onApprove: () -> Void
    let onDeny: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Aprobación", systemImage: "hand.raised")
                .font(.footnote.weight(.semibold))
                .foregroundStyle(TaapColor.warning)
            Text(prompt)
                .font(.footnote)
            HStack {
                Button(action: onApprove) {
                    Text(appState.t("chat.approve"))
                        .frame(minWidth: 80)
                }
                .buttonStyle(.borderedProminent)
                .tint(TaapColor.success)

                Button(action: onDeny) {
                    Text(appState.t("chat.deny"))
                        .frame(minWidth: 80)
                }
                .buttonStyle(.bordered)
                .tint(TaapColor.danger)
            }
        }
        .padding(12)
        .background(TaapColor.warning.opacity(0.12), in: RoundedRectangle(cornerRadius: 12))
    }
}
