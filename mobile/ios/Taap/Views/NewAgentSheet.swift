import SwiftUI

struct NewAgentSheet: View {
    @EnvironmentObject var appState: AppState
    @Environment(\.dismiss) private var dismiss

    var onCreated: (Int) -> Void

    @State private var name = ""
    /// Runtimes del REGISTRO del servicio (GET /v1/runtimes), no
    /// hardcodeados (spec agent-runtimes / ios-client "Runtimes desde el
    /// servicio"). Vacio + error visible si la carga falla (design D6).
    @State private var runtimes: [RuntimeDTO] = []
    @State private var runtime: String?
    @State private var models: [ModelDTO] = []
    @State private var modelId: Int?
    /// Modelo descartado al cambiar de runtime (aviso visible +
    /// re-restauracion, misma semantica que la web sobre ids de modelo).
    @State private var discardedModel: ModelDTO?
    @State private var internet = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("mi-agente", text: $name)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    // Picker construido desde la API: los runtimes exactos
                    // del registro, con las etiquetas de registro.
                    Picker("Runtime", selection: $runtime) {
                        ForEach(runtimes) { rt in
                            Text(rt.label).tag(Optional(rt.id))
                        }
                    }
                    Picker(appState.t("agents.model"), selection: $modelId) {
                        Text("—").tag(nil as Int?)
                        ForEach(models) { model in
                            modelRow(model).tag(model.id)
                        }
                    }
                    // Marca de salud (paridad web): proveedor con status
                    // "error" -> atenuado + etiqueta, sin bloquear.
                    if let discarded = discardedModel {
                        Text(appState.t("agents.model_discarded", vars: [
                            "model": discarded.displayName,
                            "provider": discarded.provider ?? "?",
                            "runtime": runtimeLabel,
                        ]))
                        .font(.footnote)
                        .foregroundStyle(TaapColor.warning)
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
                    // Sin runtimes cargados (orquestador viejo: 404) o sin
                    // modelo elegido, la creacion queda deshabilitada.
                    .disabled(busy || name.isEmpty || modelId == nil || runtimes.isEmpty)
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
                await loadRuntimesAndModels()
            }
            .onChange(of: runtime) { _, newRuntime in
                handleRuntimeChange(newRuntime)
            }
            .onChange(of: modelId) { _, newModelId in
                // Al elegir (de nuevo) un modelo, el aviso de descarte deja
                // de aplicar (igual que la web).
                if newModelId != nil { discardedModel = nil }
            }
        }
    }

    // MARK: - Carga

    /// Primer carga: runtimes del registro y, con el primero, los modelos
    /// filtrados. Fallo de runtimes → error visible + creacion deshabilitada
    /// (sin fallback hardcodeado, design D6).
    private func loadRuntimesAndModels() async {
        do {
            let list = try await appState.api.runtimes()
            runtimes = list
            error = nil
            if let first = list.first, runtime == nil {
                // Fijar el runtime dispara .onChange -> carga los modelos.
                runtime = first.id
            } else {
                // Runtime ya fijado (path defensivo; p. ej. vista re-montada
                // con estado previo): la carga no depende de onChange.
                await refreshForRuntime(runtime ?? "")
            }
        } catch {
            runtimes = []
            models = []
            self.error = appState.t("agents.runtimes_load_error")
        }
    }

    /// Al cambiar de runtime (design D5): re-fetch de `models(runtime:)` y
    /// descarte/re-restauracion replicando la logica de la web (que opera
    /// sobre ids de modelo, no sobre formato: el cliente NUNCA decide la
    /// compatibilidad, la lista filtrada por el servidor es la respuesta).
    private func refreshForRuntime(_ newRuntime: String) async {
        guard !newRuntime.isEmpty else { return }
        let previousSelection = modelId.flatMap { id in models.first(where: { $0.id == id }) }
        let previousDiscard = discardedModel

        guard let list = try? await appState.api.models(runtime: newRuntime) else {
            // Fallo de red / orquestador viejo: se conserva la lista previa;
            // la creacion sigue gobernada por el estado de runtimes.
            return
        }
        models = list

        if let previousSelection {
            if list.contains(where: { $0.id == previousSelection.id }) {
                // Sigue compatible: un descarte previo queda resuelto.
                if discardedModel?.id == previousSelection.id {
                    modelId = previousSelection.id
                    discardedModel = nil
                }
            } else if modelId == previousSelection.id {
                // Incompatible con el nuevo runtime: descarte + aviso.
                discardedModel = previousSelection
                modelId = nil
            }
        }

        if let previousDiscard, modelId == nil,
           list.contains(where: { $0.id == previousDiscard.id }) {
            // Volver a un runtime compatible: se re-restaura sin obligar a
            // re-seleccionar.
            modelId = previousDiscard.id
            discardedModel = nil
        }
    }

    private func handleRuntimeChange(_ newRuntime: String?) {
        guard let newRuntime else { return }
        Task { await refreshForRuntime(newRuntime) }
    }

    // MARK: - Filas del picker

    private var runtimeLabel: String {
        runtimes.first(where: { $0.id == runtime })?.label ?? runtime ?? "?"
    }

    private func modelRow(_ model: ModelDTO) -> some View {
        Group {
            if model.isUnhealthy {
                Text("\(model.displayName) (\(model.provider ?? "?")) · \(appState.t("agents.unhealthy"))")
            } else {
                Text("\(model.displayName) (\(model.provider ?? "?"))")
            }
        }
        .foregroundStyle(model.isUnhealthy ? AnyShapeStyle(.secondary) : AnyShapeStyle(.primary))
    }

    // MARK: - Creacion

    private func create() async {
        guard let modelId, let runtime else { return }
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
        } catch let error as ApiClient.ApiError {
            // El 422 de formato (el servidor lo aplica en todo caso) se
            // muestra con la cadena localizada, no el crudo de Laravel.
            self.error = error == .incompatibleModel
                ? appState.t("agents.model_incompatible")
                : error.localizedDescription
        } catch {
            self.error = error.localizedDescription
        }
        busy = false
    }
}
