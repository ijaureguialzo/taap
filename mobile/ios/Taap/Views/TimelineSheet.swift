import SwiftUI

/// Linea de tiempo del agente (7.2 / spec ios-client: chat parity):
/// ventanas de trabajo sintetizadas ("lo que hizo mientras no estabas"),
/// rematando en el estado actual.
struct TimelineSheet: View {
    @EnvironmentObject var appState: AppState
    let agentId: Int
    let windows: [TimelineWindowDTO]
    let onChanged: ([TimelineWindowDTO]) -> Void

    @State private var agentStatus: String?

    var body: some View {
        NavigationStack {
            Group {
                if windows.isEmpty {
                    ContentUnavailableView(
                        appState.t("timeline.empty"),
                        systemImage: "clock.arrow.circlepath"
                    )
                } else {
                    List {
                        Section {
                            ForEach(Array(windows.reversed()), id: \.id) { w in
                                windowRow(w)
                            }
                        } footer: {
                            if let agentStatus {
                                Text("\(appState.t("agents.status")): \(appState.t("status.\(agentStatus)"))")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                }
            }
            .navigationTitle(appState.t("timeline.title"))
            .navigationBarTitleDisplayMode(.inline)
            .task {
                do {
                    let res = try await appState.api.timeline(agentId: agentId)
                    agentStatus = res.agentStatus
                    onChanged(res.windows)
                } catch {
                    // Con lo que ya teniamos.
                }
            }
        }
    }

    @ViewBuilder
    private func windowRow(_ w: TimelineWindowDTO) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Image(systemName: w.open ? "clock.fill" : "checkmark.circle.fill")
                    .foregroundStyle(w.open ? .orange : .green)
                Text(w.open ? appState.t("timeline.open") : appState.t("timeline.done"))
                    .font(.subheadline.weight(.medium))
                Spacer()
                Text(w.duration)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            if let note = w.note, !note.isEmpty {
                Text(note)
                    .font(.caption)
                    .lineLimit(3)
            }

            HStack(spacing: 6) {
                if w.messages > 0 {
                    chip("\(appState.t("timeline.messages")): \(w.messages)", color: .gray)
                }
                if w.toolCalls > 0 {
                    chip("\(appState.t("timeline.tools")): \(w.toolCalls)", color: .blue)
                }
                if w.approvals > 0 {
                    chip("\(appState.t("timeline.approvals")): \(w.approvals)", color: .orange)
                }
                if w.errors > 0 {
                    chip("\(appState.t("timeline.errors")): \(w.errors)", color: .red)
                }
                if w.tools.isEmpty {
                    Text(timeText(w))
                        .font(.caption2)
                        .foregroundStyle(.tertiary)
                }
            }

            if !w.tools.isEmpty {
                Text(w.tools.joined(separator: ", "))
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }
        }
        .padding(.vertical, 2)
    }

    private func chip(_ text: String, color: Color) -> some View {
        Text(text)
            .font(.caption2)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(color.opacity(0.15), in: Capsule())
    }

    private func timeText(_ w: TimelineWindowDTO) -> String {
        let out = ISO8601DateFormatter()
        out.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let fmt = DateFormatter()
        fmt.dateFormat = "dd/MM HH:mm"
        guard let d = parseISO(w.start) else { return w.start }
        return fmt.string(from: d)
    }

    private func parseISO(_ iso: String) -> Date? {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f.date(from: iso)
    }
}
