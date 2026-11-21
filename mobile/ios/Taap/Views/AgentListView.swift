import SwiftUI

struct AgentListView: View {
    @EnvironmentObject var appState: AppState
    var onSelect: (Int) -> Void

    @State private var showingNew = false

    var body: some View {
        Group {
            if appState.agents.isEmpty && !appState.agentsLoading {
                ContentUnavailableView {
                    Image(systemName: "cpu")
                } description: {
                    Text(appState.t("agents.empty"))
                } actions: {
                    Button(appState.t("agents.new")) { showingNew = true }
                        .buttonStyle(.borderedProminent)
                }
            } else if appState.agentsLoading && appState.agents.isEmpty {
                ProgressView()
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                List {
                    ForEach(appState.agents) { agent in
                        Button {
                            onSelect(agent.id)
                        } label: {
                            AgentRowView(agent: agent)
                        }
                        .buttonStyle(.plain)
                        .contentShape(Rectangle())
                        .accessibilityIdentifier("agent-\(agent.name)")
                    }
                }
                .listStyle(.insetGrouped)
                .refreshable {
                    await appState.refreshAgents()
                }
            }
        }
        .navigationTitle(appState.t("agents.title"))
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    showingNew = true
                } label: {
                    Image(systemName: "plus")
                }
            }
        }
        .sheet(isPresented: $showingNew) {
            NewAgentSheet(onCreated: { id in
                showingNew = false
                onSelect(id)
            })
        }
    }
}

struct AgentRowView: View {
    @EnvironmentObject var appState: AppState
    let agent: AgentDTO

    var body: some View {
        HStack(spacing: 12) {
            Circle()
                .fill(color)
                .frame(width: 10, height: 10)
                .overlay {
                    if agent.status == "working" {
                        Circle().stroke(color, lineWidth: 3)
                            .scaleEffect(1.6)
                            .opacity(0.4)
                    }
                }

            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(agent.name)
                        .font(.headline)
                    Text(agent.runtime.uppercased())
                        .font(.caption2)
                        .padding(.horizontal, 5)
                        .padding(.vertical, 1)
                        .background(.quaternary, in: Capsule())
                }
                Text(statusText)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                if let model = agent.model {
                    Text("\(appState.t("agents.model")): \(model.displayName)")
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                }
            }

            Spacer()

            if agent.internetEnabled {
                Image(systemName: "globe")
                    .foregroundStyle(.secondary)
                    .font(.caption)
            }
        }
        .padding(.vertical, 4)
    }

    private var color: Color {
        TaapColor.status(agent.status)
    }

    private var statusText: String {
        appState.t("status.\(agent.status)")
    }
}
