import SwiftUI

struct LoginView: View {
    @EnvironmentObject var appState: AppState
    @State private var email = ""
    @State private var password = ""
    @State private var serviceURL = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        VStack(spacing: 20) {
            VStack(spacing: 4) {
                Text("⚡ TAAP").font(.largeTitle.bold())
                Text(appState.t("login.title"))
                    .font(.title3)
                    .foregroundStyle(.secondary)
            }

            VStack(spacing: 12) {
                TextField(appState.t("login.service_url"), text: $serviceURL)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                TextField(appState.t("login.email"), text: $email)
                    .keyboardType(.emailAddress)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                SecureField(appState.t("login.password"), text: $password)
                    .textInputAutocapitalization(.never)
            }
            .textFieldStyle(.roundedBorder)

            if let error {
                Text(error)
                    .font(.footnote)
                    .foregroundStyle(.red)
            }

            Button {
                Task { await submit() }
            } label: {
                if busy {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                } else {
                    Text(appState.t("login.submit"))
                        .frame(maxWidth: .infinity)
                }
            }
            .buttonStyle(.borderedProminent)
            .disabled(busy || email.isEmpty || password.isEmpty)

            if !serviceURL.isEmpty {
                // la URL solo se aplica al enviar (evita peticiones a medias)
            }
        }
        .padding(24)
        .frame(maxWidth: 480)
    }

    private func submit() async {
        busy = true
        error = nil
        if !serviceURL.isEmpty {
            appState.baseURLString = serviceURL
        }
        let ok = await appState.login(email: email, password: password)
        busy = false
        if !ok {
            error = appState.t("login.error_offline")
        }
    }
}
