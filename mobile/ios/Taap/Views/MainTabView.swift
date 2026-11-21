import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var appState: AppState
    @State private var selectedTab: Tab = .agents
    // brand-identity D5: conmutador de tema (3 estados).
    @AppStorage("taap-theme") private var theme = "auto"

    enum Tab: Hashable {
        case agents
        case settings
    }

    var body: some View {
        NavigationStack {
            if let selected = appState.selectedAgentId {
                AgentDetailView(agentId: selected)
                    .id(selected)
                    .onDisappear {
                        appState.selectedAgentId = nil
                    }
            } else {
                AgentListView(onSelect: { id in
                    appState.selectedAgentId = id
                })
            }
        }
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Menu {
                    Picker(appState.t("menu.theme"), selection: $theme) {
                        Text(appState.t("theme.auto")).tag("auto")
                        Text(appState.t("theme.light")).tag("light")
                        Text(appState.t("theme.dark")).tag("dark")
                    }
                    Picker(appState.t("menu.language"), selection: $appState.locale) {
                        Text("es").tag("es")
                        Text("en").tag("en")
                        Text("eu").tag("eu")
                    }
                    Button {
                        appState.logout()
                    } label: {
                        Label(appState.t("menu.logout"), systemImage: "rectangle.portrait.and.arrow.right")
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
            }
        }
        .navigationTitle("TAAP")
        .task {
            await appState.refreshAgents()
        }
        .overlay {
            if let banner = appState.banner {
                BannerView(banner: banner)
                    .transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.default, value: appState.banner?.id)
    }
}

struct BannerView: View {
    let banner: AppState.Banner

    var body: some View {
        VStack {
            Text(banner.text)
                .font(.footnote)
                .padding(10)
                .background(banner.kind == .error ? TaapColor.danger.opacity(0.9) : TaapColor.primary.opacity(0.9), in: RoundedRectangle(cornerRadius: 8))
                .foregroundStyle(.white)
                .padding(.top, 8)
            Spacer()
        }
    }
}
