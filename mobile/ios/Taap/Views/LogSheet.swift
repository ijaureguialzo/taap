import SwiftUI

/// Hoja de logs del agente (read-only): tail de líneas con status y botón
/// de copiar al portapapeles.
struct LogSheet: View {
    @EnvironmentObject var appState: AppState
    @Environment(\.dismiss) private var dismiss

    let status: String
    let lines: [String]
    let loading: Bool
    let onCopy: () -> Void

    var body: some View {
        NavigationStack {
            ScrollView {
                if loading {
                    ProgressView()
                        .padding(40)
                } else if status == "no-container" {
                    ContentUnavailableView {
                        Label(appState.t("agents.logs_no_container"), systemImage: "terminal")
                    }
                } else if status == "unavailable" {
                    ContentUnavailableView {
                        Label(appState.t("agents.logs_unavailable"), systemImage: "exclamationmark.triangle")
                    }
                } else if lines.isEmpty {
                    Text(appState.t("agents.logs_none"))
                        .foregroundStyle(.secondary)
                        .padding(40)
                } else {
                    Text(lines.joined(separator: "\n"))
                        .font(.system(.footnote, design: .monospaced))
                        .textSelection(.enabled)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                }
            }
            .background(TaapColor.surface)
            .navigationTitle(appState.t("agents.logs_title"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button(appState.t("agents.logs_copy"), action: onCopy)
                    .buttonStyle(.glassProminent)
                }
                ToolbarItem(placement: .cancellationAction) {
                    Button(appState.t("actions.cancel")) { dismiss() }
                }
            }
        }
    }
}
