import SwiftUI

struct NewAgentSheet: View {
    @EnvironmentObject var appState: AppState
    @Environment(\.dismiss) private var dismiss

    var onCreated: (Int) -> Void

    @State private var name = ""
    @State private var runtime = "pi"
    @State private var modelId: Int?
    @State private var internet = false
    @State private var busy = false
    @State private var error: String?
    @State private var models: [ModelDTO] = []

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("mi-agente", text: $name)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    Picker("Runtime", selection: $runtime) {
                        Text("Pi").tag("pi")
                        Text("Hermes").tag("hermes")
                    }
                    Picker(appState.t("agents.model"), selection: $modelId) {
                        Text("—").tag(nil as Int?)
                        ForEach(models) { model in
                            Text("\(model.displayName) (\(model.provider ?? "?"))").tag(model.id)
                        }
                    }
                }

                Section {
                    Toggle(appState.t("agents.internet"), isOn: $internet)
                }

                if let error {
                    Section {
                        Text(error).foregroundStyle(.red).font(.footnote)
                    }
                }

                Section {
                    Button {
                        Task { await create() }
                    } label: {
                        if busy {
                            ProgressView().frame(maxWidth: .infinity)
                        } else {
                            Text(appState.t("agents.start"))
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .disabled(busy || name.isEmpty || modelId == nil)
                }
            }
            .navigationTitle(appState.t("agents.new"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("OK") { dismiss() }
                }
            }
            .task {
                do {
                    models = try await appState.api.models()
                } catch {
                    // sin modelos: se puede crear y elegir luego
                }
            }
        }
    }

    private func create() async {
        guard let modelId else { return }
        busy = true
        error = nil
        do {
            let agent = try await appState.api.createAgent(
                name: name,
                runtime: runtime,
                modelId: modelId,
                internet: internet
            )
            await appState.refreshAgents()
            onCreated(agent.id)
        } catch {
            self.error = error.localizedDescription
        }
        busy = false
    }
}
