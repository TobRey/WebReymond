// Kamera-Link iOS-Hülle: App-Einstieg, WebView, Speichern in die Fotos-App.

import Photos
import SwiftUI
import WebKit

@main
struct KameraLinkApp: App {
    var body: some Scene {
        WindowGroup {
            WebAppView()
                .ignoresSafeArea()
                .background(Color.black)
                .preferredColorScheme(.dark)
        }
    }
}

struct WebAppView: UIViewRepresentable {
    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let web = Bundle.main.url(forResource: "web", withExtension: nil)!
        let bridge = CameraBridge(webRoot: web)
        context.coordinator.bridge = bridge
        let cfg = WKWebViewConfiguration()
        cfg.setURLSchemeHandler(bridge, forURLScheme: CameraBridge.scheme)
        cfg.userContentController.add(context.coordinator, name: "kameraNative")
        cfg.allowsInlineMediaPlayback = true
        let view = WKWebView(frame: .zero, configuration: cfg)
        view.isOpaque = false
        view.backgroundColor = .black
        view.scrollView.contentInsetAdjustmentBehavior = .never
        context.coordinator.webView = view
        view.load(URLRequest(url: CameraBridge.startURL))
        return view
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    final class Coordinator: NSObject, WKScriptMessageHandler {
        var bridge: CameraBridge?
        weak var webView: WKWebView?

        func userContentController(_ ucc: WKUserContentController, didReceive message: WKScriptMessage) {
            guard let body = message.body as? [String: Any], let id = body["id"] as? Int,
                  body["action"] as? String == "save", let items = body["items"] as? [[String: String]] else { return }
            PhotoSaver.save(items: items, camera: bridge?.cameraAddress) { ok, text in
                let msg = text.replacingOccurrences(of: "'", with: "’")
                DispatchQueue.main.async {
                    self.webView?.evaluateJavaScript("window.__kameraNativeReply(\(id), \(ok), '\(msg)')")
                }
            }
        }
    }
}

/// Lädt Originale unverändert direkt von der Kamera und legt sie in der Fotos-App ab.
enum PhotoSaver {
    static func save(items: [[String: String]], camera: String?, done: @escaping (Bool, String) -> Void) {
        guard let camera else { return done(false, "Keine Kamera verbunden.") }
        PHPhotoLibrary.requestAuthorization(for: .addOnly) { status in
            guard status == .authorized || status == .limited else {
                return done(false, "Kein Zugriff auf Fotos. Einstellungen › Kamera-Link › Fotos erlauben.")
            }
            next(items[...], camera: camera, done: done)
        }
    }

    private static func next(_ rest: ArraySlice<[String: String]>, camera: String, done: @escaping (Bool, String) -> Void) {
        guard let item = rest.first, let path = item["path"], let name = item["name"],
              let url = URL(string: "http://\(camera)\(path)") else { return done(true, "") }
        URLSession.shared.downloadTask(with: url) { tmp, res, err in
            guard let tmp, (res as? HTTPURLResponse)?.statusCode == 200 else {
                return done(false, "Download fehlgeschlagen: \(err?.localizedDescription ?? name)")
            }
            let file = FileManager.default.temporaryDirectory.appendingPathComponent(name)
            try? FileManager.default.removeItem(at: file)
            do { try FileManager.default.moveItem(at: tmp, to: file) } catch { return done(false, error.localizedDescription) }
            let isVideo = ["mp4", "mov"].contains(file.pathExtension.lowercased())
            PHPhotoLibrary.shared().performChanges({
                let req = PHAssetCreationRequest.forAsset()
                let opts = PHAssetResourceCreationOptions()
                opts.originalFilename = name
                opts.shouldMoveFile = true
                req.addResource(with: isVideo ? .video : .photo, fileURL: file, options: opts)
            }) { ok, error in
                if ok { next(rest.dropFirst(), camera: camera, done: done) }
                else { done(false, "\(name): \(error?.localizedDescription ?? "Fotos hat die Datei abgelehnt")") }
            }
        }.resume()
    }
}

enum LocalNetwork {
    /// IPv4-Adresse der WLAN-Schnittstelle (en0).
    static func wifiIPv4() -> String? {
        var addrs: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&addrs) == 0, let first = addrs else { return nil }
        defer { freeifaddrs(addrs) }
        for p in sequence(first: first, next: { $0.pointee.ifa_next }) {
            let a = p.pointee
            guard a.ifa_addr.pointee.sa_family == UInt8(AF_INET), String(cString: a.ifa_name) == "en0" else { continue }
            var host = [CChar](repeating: 0, count: Int(NI_MAXHOST))
            if getnameinfo(a.ifa_addr, socklen_t(a.ifa_addr.pointee.sa_len), &host, socklen_t(host.count), nil, 0, NI_NUMERICHOST) == 0 {
                return String(cString: host)
            }
        }
        return nil
    }
}
