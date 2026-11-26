import SwiftUI

struct LoginView: View {
    @EnvironmentObject var appState: AppState
    @State private var email = ""
    @State private var password = ""
    @State private var serviceURL = ""
    @State private var busy = false
    @State private var error: String?

    // --- Reto 2FA (opcional) ---
    @State private var twoFactorShown = false
    @State private var twoFactorCode = ""
    @State private var twoFactorRecovery = false
    @State private var twoFactorError = false

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
        }
        .padding(24)
        .frame(maxWidth: 480)
        .sheet(isPresented: $twoFactorShown) {
            TwoFactorSheet(
                code: $twoFactorCode,
                useRecovery: $twoFactorRecovery,
                busy: busy,
                twoFactorError: twoFactorError,
                onVerify: { Task { await completeTwoFactor() } },
                onCancel: { cancelTwoFactor() }
            )
        }
    }

    private func submit() async {
        busy = true
        error = nil
        if !serviceURL.isEmpty {
            appState.baseURLString = serviceURL
        }
        switch await appState.login(email: email, password: password) {
        case .loggedIn:
            break
        case .requiresTwoFactor:
            twoFactorShown = true
            twoFactorCode = ""
            twoFactorRecovery = false
            twoFactorError = false
        case .failed:
            error = appState.t("login.error_offline")
        }
        busy = false
    }

    private func completeTwoFactor() async {
        busy = true
        twoFactorError = false
        let ok: Bool
        if twoFactorRecovery {
            ok = await appState.completeTwoFactor(code: "", recoveryCode: twoFactorCode)
        } else {
            ok = await appState.completeTwoFactor(code: twoFactorCode)
        }
        busy = false
        if ok {
            twoFactorShown = false
        } else {
            twoFactorError = true
        }
    }

    private func cancelTwoFactor() {
        appState.cancelTwoFactor()
        twoFactorShown = false
        twoFactorCode = ""
        twoFactorError = false
    }
}

struct TwoFactorSheet: View {
    @EnvironmentObject var appState: AppState
    @Binding var code: String
    @Binding var useRecovery: Bool
    let busy: Bool
    let twoFactorError: Bool
    let onVerify: () -> Void
    let onCancel: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            VStack(spacing: 4) {
                Text(appState.t("login.two_factor_title"))
                    .font(.title2.bold())
                Text(appState.t("login.two_factor_hint"))
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }

            TextField(appState.t("login.two_factor_code"), text: $code)
                .keyboardType(useRecovery ? .default : .numberPad)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .textFieldStyle(.roundedBorder)
                .textFieldStyle(.roundedBorder)
                .submitLabel(.go)
                .onSubmit(onVerify)

            Toggle(isOn: $useRecovery) {
                Text(appState.t("login.two_factor_recovery_toggle"))
                    .font(.footnote)
            }
            .toggleStyle(.switch)

            if twoFactorError {
                Text(appState.t("login.two_factor_error"))
                    .font(.footnote)
                    .foregroundStyle(.red)
            }

            HStack(spacing: 12) {
                Button(role: .cancel) {
                    onCancel()
                } label: {
                    Text(appState.t("login.two_factor_cancel"))
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)

                Button {
                    onVerify()
                } label: {
                    if busy {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    } else {
                        Text(appState.t("login.two_factor_submit"))
                            .frame(maxWidth: .infinity)
                    }
                }
                .buttonStyle(.borderedProminent)
                .disabled(busy || code.trimmingCharacters(in: .whitespaces).isEmpty)
            }
            .padding(.horizontal, 8)
        }
        .padding(32)
        .frame(maxWidth: 420)
    }
}
