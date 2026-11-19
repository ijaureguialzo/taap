import SwiftUI

struct AgentSettingsSheet: View {
    @EnvironmentObject var appState: AppState
    @Environment(\.dismiss) private var dismiss
    let agentId: Int
    var onSaved: (AgentDTO) -> Void

    @State private var agent: AgentDTO?
    @State private var models: [ModelDTO] = []
    @State private var selectedModel: Int?
    @State private var internet = false
    @State private var autoRestart = true
    @State private var systemPrompt = ""
    @State private var workingDir = ""
    @State private var mcpCatalog: [McpSummary] = []
    @State private var selectedMcp = Set<Int>()
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section(appState.t("settings.model")) {
                    Picker("Modelo", selection: $selectedModel) {
                        Text("—").tag(nil as Int?)
                        ForEach(models) { model in
                            Text("\(model.displayName) (\(model.provider ?? "?"))").tag(model.id)
                        }
                    }
                }

                Section {
                    Toggle(appState.t("settings.internet"), isOn: $internet)
                    Toggle(appState.t("settings.auto_restart"), isOn: $autoRestart)
                }

                Section("MCP") {
                    if mcpCatalog.isEmpty {
                        Text("Sin servidores MCP disponibles")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                    ForEach(mcpCatalog) { server in
                        Toggle(isOn: bindingFor(server.id)) {
                            Label(server.name, systemImage: server.type == "http" ? "network" : "terminal")
                                .font(.body)
                        }
                    }
                }

                Section("Recursos") {
                    TextField("working_dir (opcional)", text: $workingDir)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }

                Section(appState.t("settings.system_prompt")) {
                    TextField("…", text: $systemPrompt, axis: .vertical)
                        .lineLimit(3...8)
                }

                if let error {
                    Section {
                        Text(error).font(.footnote).foregroundStyle(.red)
                    }
                }

                Section {
                    Button {
                        Task { await save() }
                    } label: {
                        if busy {
                            ProgressView().frame(maxWidth: .infinity)
                        } else {
                            Text(appState.t("settings.save")).frame(maxWidth: .infinity)
                        }
                    }
                    .disabled(busy)
                }
            }
            .navigationTitle(appState.t("settings.title"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("OK") { dismiss() }
                }
            }
            .task {
                do {
                    let a = try await appState.api.agent(id: agentId)
                    agent = a
                    internet = a.internetEnabled
                    autoRestart = a.autoRestart
                    systemPrompt = a.systemPrompt ?? ""
                    workingDir = a.workingDir ?? ""
                    selectedModel = a.model?.id
                    selectedMcp = Set((a.mcpServers ?? []).map { $0.id })
                    models = try await appState.api.models()
                    mcpCatalog = (try? await appState.api.mcpServers()) ?? []
                } catch {
                    // el detalle sigue funcionando; solo no hay config
                }
            }
        }
    }

    private func bindingFor(_ id: Int) -> Binding<Bool> {
        Binding(
            get: { selectedMcp.contains(id) },
            set: { on in
                if on { selectedMcp.insert(id) } else { selectedMcp.remove(id) }
            }
        )
    }

    private func save() async {
        guard let agent else { return }
        busy = true
        error = nil
        do {
            let updated = try await appState.api.updateAgent(id: agentId, AgentUpdate(
                name: agent.name, // la API lo exige (required); no se edita en v1
                description: agent.description,
                model_id: selectedModel,
                system_prompt: systemPrompt.isEmpty ? nil : systemPrompt,
                internet_enabled: internet,
                auto_restart: autoRestart,
                working_dir: workingDir.isEmpty ? nil : workingDir,
                mcp_server_ids: Array(selectedMcp).sorted()
            ))
            onSaved(updated)
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
        busy = false
    }
}
