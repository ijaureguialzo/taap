import SwiftUI

/// Explorador de ficheros (API v1 de shared-filesystem): navegación por
/// ruta, subida, descarga (.zip de carpetas), renombrado y borrado.
struct FilesView: View {
    @EnvironmentObject var appState: AppState

    @State private var path = "/"
    @State private var entries: [FileEntryDTO] = []
    @State private var loading = true
    @State private var errorMessage: String?
    @State private var deleting: FileEntryDTO?
    @State private var renameTarget: FileEntryDTO?
    @State private var renameValue = ""
    @State private var creatingFolder = false
    @State private var newFolderName = ""
    @State private var showImporter = false
    @State private var shareURL: URL?

    var body: some View {
        VStack(spacing: 0) {
            headerBar

            if let errorMessage {
                errorBanner(errorMessage)
            }

            content
        }
        .navigationTitle(path == "/" ? appState.t("files.title") : (path as NSString).lastPathComponent)
        .toolbar {
            ToolbarItem(placement: .navigationBarLeading) {
                if path != "/" {
                    Button { goBack() } label: {
                        Label(appState.t("files.back"), systemImage: "chevron.left")
                    }
                }
            }
            ToolbarItem(placement: .navigationBarTrailing) {
                Menu {
                    Button { creatingFolder = true } label: {
                        Label(appState.t("files.new_folder"), systemImage: "folder.badge.plus")
                    }
                    Button { showImporter = true } label: {
                        Label(appState.t("files.upload"), systemImage: "square.and.arrow.up")
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
            }
        }
        .task { await load() }
        .confirmationDialog(appState.t("files.delete_confirm"), isPresented: Binding(
            get: { deleting != nil },
            set: { if !$0 { deleting = nil } }
        )) {
            Button(appState.t("files.delete"), role: .destructive) {
                if let entry = deleting {
                    Task { await delete(entry.name) }
                }
                deleting = nil
            }
            Button(appState.t("files.cancel"), role: .cancel) { deleting = nil }
        }
        .sheet(isPresented: Binding(get: { renameTarget != nil }, set: { if !$0 { renameTarget = nil } })) {
            if let target = renameTarget {
                NavigationStack {
                    Form {
                        TextField(appState.t("files.new_name"), text: $renameValue)
                        Section {
                            Button(appState.t("files.save")) {
                                Task { await doRename(target) }
                            }
                            .buttonStyle(.glassProminent)
                        }
                    }
                    .navigationTitle(appState.t("files.rename"))
                    .navigationBarTitleDisplayMode(.inline)
                }
            }
        }
        .sheet(isPresented: $creatingFolder) {
            NavigationStack {
                Form {
                    TextField(appState.t("files.new_folder_name"), text: $newFolderName)
                    Section {
                        Button(appState.t("files.create")) {
                            Task { await createFolder() }
                        }
                        .buttonStyle(.glassProminent)
                        Button(appState.t("files.cancel")) { creatingFolder = false }
                            .buttonStyle(.glass)
                    }
                }
                .navigationTitle(appState.t("files.new_folder"))
                .navigationBarTitleDisplayMode(.inline)
            }
        }
        .fileImporter(isPresented: $showImporter, allowedContentTypes: [.data]) { result in
            switch result {
            case .success(let url):
                Task { await upload(url) }
            case .failure(let error):
                self.errorMessage = error.localizedDescription
            }
        }
        .sheet(isPresented: Binding(get: { shareURL != nil }, set: { if !$0 { shareURL = nil } })) {
            if let shareURL {
                ShareSheet(activityItems: [shareURL])
            }
        }
    }

    // MARK: - Subviews

    private var headerBar: some View {
        HStack(spacing: 10) {
            Image(systemName: "folder.fill")
                .font(.title2)
                .foregroundStyle(TaapColor.primary)
            Text(path)
                .font(.headline)
                .lineLimit(1)
                .truncationMode(.middle)
            Spacer()
            if !loading {
                Text("\(entries.count)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 2)
                    .background(TaapColor.surfaceElevated, in: Capsule())
            }
        }
        .padding(12)
        .background(TaapColor.surface)
    }

    @ViewBuilder
    private func errorBanner(_ message: String) -> some View {
        HStack {
            Image(systemName: "exclamationmark.triangle")
            Text(message)
                .font(.footnote)
            Spacer()
            Button { self.errorMessage = nil } label: {
                Image(systemName: "xmark")
            }
            .buttonStyle(.plain)
        }
        .padding(8)
        .background(TaapColor.danger.opacity(0.15))
    }

    @ViewBuilder
    private var content: some View {
        if loading {
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if entries.isEmpty {
            ContentUnavailableView {
                Label(appState.t("files.empty"), systemImage: "folder")
            }
        } else {
            fileList
        }
    }

    private var fileList: some View {
        List {
            ForEach(entries) { entry in
                FileRow(
                    entry: entry,
                    onTap: {
                        if entry.isDir {
                            enter(entry)
                        } else {
                            Task { await download(entry) }
                        }
                    },
                    onRename: {
                        renameTarget = entry
                        renameValue = entry.name
                    },
                    onDelete: {
                        deleting = entry
                    }
                )
            }
        }
        .listStyle(.insetGrouped)
        .refreshable { await load() }
    }

    // MARK: - Lógica

    private func load() async {
        loading = true
        errorMessage = nil
        defer { loading = false }
        do {
            let res = try await appState.api.files(at: path)
            entries = res.entries
        } catch {
            errorMessage = error.localizedDescription
            entries = []
        }
    }

    private func enter(_ entry: FileEntryDTO) {
        path = path == "/" ? "/\(entry.name)" : "\(path)/\(entry.name)"
        Task { await load() }
    }

    private func goBack() {
        guard path != "/" else { return }
        let parts = path.split(separator: "/").map(String.init)
        if parts.count <= 1 {
            path = "/"
        } else {
            path = "/" + parts.dropLast().joined(separator: "/")
        }
        Task { await load() }
    }

    private func delete(_ name: String) async {
        let target = path == "/" ? "/\(name)" : "\(path)/\(name)"
        do {
            try await appState.api.deletePath(path: target)
            await load()
        } catch {
            self.errorMessage = error.localizedDescription
        }
    }

    private func doRename(_ target: FileEntryDTO) async {
        guard !renameValue.isEmpty else { return }
        let oldPath = path == "/" ? "/\(target.name)" : "\(path)/\(target.name)"
        do {
            try await appState.api.renameFile(path: oldPath, newName: renameValue)
            renameTarget = nil
            await load()
        } catch {
            self.errorMessage = error.localizedDescription
        }
    }

    private func createFolder() async {
        guard !newFolderName.isEmpty else { return }
        let target = path == "/" ? "/\(newFolderName)" : "\(path)/\(newFolderName)"
        do {
            try await appState.api.createFolder(path: target)
            newFolderName = ""
            creatingFolder = false
            await load()
        } catch {
            self.errorMessage = error.localizedDescription
        }
    }

    private func upload(_ url: URL) async {
        do {
            try await appState.api.uploadFile(path: path, fileURL: url)
            await load()
        } catch {
            self.errorMessage = error.localizedDescription
        }
    }

    private func download(_ entry: FileEntryDTO) async {
        let target = path == "/" ? "/\(entry.name)" : "\(path)/\(entry.name)"
        do {
            let (url, _) = try await appState.api.download(path: target)
            shareURL = url
        } catch {
            self.errorMessage = error.localizedDescription
        }
    }
}

/// Fila individual del listado de ficheros (aislada para mantener el tipo
/// de `content` simple).
private struct FileRow: View {
    @EnvironmentObject var appState: AppState

    let entry: FileEntryDTO
    let onTap: () -> Void
    let onRename: () -> Void
    let onDelete: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 12) {
                Image(systemName: entry.isDir ? "folder.fill" : "doc.fill")
                    .foregroundStyle(entry.isDir ? TaapColor.primary : TaapColor.textSecondary)
                    .frame(width: 34)

                VStack(alignment: .leading, spacing: 2) {
                    Text(entry.name)
                        .font(.body)
                        .lineLimit(1)
                    Text(entry.sizeDisplay)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                Spacer()

                if let owner = entry.owner {
                    Text(owner)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }

                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            .padding(.vertical, 4)
        }
        .buttonStyle(.plain)
        .swipeActions(edge: .trailing, allowsFullSwipe: false) {
            Button(action: onRename) {
                Label(appState.t("files.rename"), systemImage: "pencil")
            }
            .tint(.blue)

            Button(role: .destructive, action: onDelete) {
                Label(appState.t("files.delete"), systemImage: "trash")
            }
        }
    }
}

/// Hoja de compartir (UIActivityViewController) para el resultado de
/// descarga (fichero o .zip).
struct ShareSheet: UIViewControllerRepresentable {
    let activityItems: [Any]

    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }

    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
