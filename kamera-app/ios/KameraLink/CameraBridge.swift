// Kamera-Link iOS-Hülle: Dieselbe Web-App läuft in einer WKWebView.
// Statt der Kamera-Brücke (Python) beantwortet dieser Scheme-Handler die Pfade
//   /            -> app.html (Modus "bridge")
//   /assets/…    -> gebündelte Web-Dateien (Ordner "web")
//   /bridge/…    -> Status, Kameraadresse, Suche, Livebild
//   /cam/ccapi/… -> nur lesende GET-Anfragen an die Kamera (wie die Brücke; DELETE gesperrt)
// Native Apps unterliegen keinem CORS und keinem Mixed-Content-Verbot.

import Foundation
import WebKit

final class CameraBridge: NSObject, WKURLSchemeHandler, URLSessionDataDelegate {
    static let scheme = "kameralink"
    static let startURL = URL(string: "\(scheme)://app/")!

    private let webRoot: URL
    private var session: URLSession!
    private let lock = NSLock()
    /// laufende Kamera-Anfragen: Daten-Task -> Scheme-Task
    private var running: [Int: Relay] = [:]
    private var stopped = Set<ObjectIdentifier>()

    var cameraAddress: String? {
        get { UserDefaults.standard.string(forKey: "cameraAddress") }
        set { UserDefaults.standard.set(newValue, forKey: "cameraAddress") }
    }
    private var manifest: [String: Any]?
    private var device: [String: Any]?

    init(webRoot: URL) {
        self.webRoot = webRoot
        super.init()
        let cfg = URLSessionConfiguration.default
        cfg.httpMaximumConnectionsPerHost = 2   // die Kamera verträgt wenige Verbindungen
        cfg.requestCachePolicy = .reloadIgnoringLocalCacheData
        cfg.timeoutIntervalForRequest = 30
        session = URLSession(configuration: cfg, delegate: self, delegateQueue: nil)
    }

    // MARK: - WKURLSchemeHandler

    func webView(_ webView: WKWebView, start task: WKURLSchemeTask) {
        guard let url = task.request.url else { return }
        let path = url.path.isEmpty ? "/" : url.path
        let method = task.request.httpMethod ?? "GET"
        let query = Dictionary(uniqueKeysWithValues: (URLComponents(url: url, resolvingAgainstBaseURL: false)?
            .queryItems ?? []).map { ($0.name, $0.value ?? "") })

        if path.hasPrefix("/cam/") {
            guard method == "GET" || method == "HEAD" else { return reply(task, 405, json: ["error": "not_allowed", "message": "Nur lesender Zugriff."]) }
            return proxy(task, camPath: String(path.dropFirst(4)), query: url.query)
        }
        switch (method, path) {
        case ("GET", "/bridge/status"):
            status { self.reply(task, 200, json: $0) }
        case ("POST", "/bridge/camera"):
            guard let addr = Self.normalize(query["address"] ?? "") else {
                return reply(task, 400, json: ["error": "bad_address", "message": "Ungültige Adresse."])
            }
            cameraAddress = addr; manifest = nil; device = nil
            status { self.reply(task, 200, json: ["ok": true, "camera": $0["camera"] ?? [:]]) }
        case ("POST", "/bridge/discover"):
            discover(port: Int(query["port"] ?? "") ?? 8080) { found in
                if found.count == 1, self.cameraAddress == nil { self.cameraAddress = found[0]["address"] as? String }
                self.reply(task, 200, json: ["found": found])
            }
        case ("POST", "/bridge/liveview"):
            liveview(size: query["size"] ?? "off") { code, body in self.reply(task, code, json: body) }
        case ("GET", _), ("HEAD", _):
            serveFile(task, path: path)
        default:
            reply(task, 405, json: ["error": "not_allowed", "message": "Diese Aktion ist nicht erlaubt."])
        }
    }

    func webView(_ webView: WKWebView, stop task: WKURLSchemeTask) {
        lock.lock(); stopped.insert(ObjectIdentifier(task))
        let ids = running.filter { $0.value.task === task }.map { $0.key }
        lock.unlock()
        session.getAllTasks { all in all.filter { ids.contains($0.taskIdentifier) }.forEach { $0.cancel() } }
    }

    private func isStopped(_ task: WKURLSchemeTask) -> Bool {
        lock.lock(); defer { lock.unlock() }
        return stopped.contains(ObjectIdentifier(task))
    }

    // MARK: - Antworten

    private func reply(_ task: WKURLSchemeTask, _ status: Int, json: Any) {
        let data = (try? JSONSerialization.data(withJSONObject: json)) ?? Data("{}".utf8)
        reply(task, status, data: data, type: "application/json; charset=utf-8")
    }

    private func reply(_ task: WKURLSchemeTask, _ status: Int, data: Data, type: String) {
        DispatchQueue.main.async {
            guard !self.isStopped(task), let url = task.request.url else { return }
            let res = HTTPURLResponse(url: url, statusCode: status, httpVersion: "HTTP/1.1",
                                      headerFields: ["Content-Type": type, "Content-Length": "\(data.count)", "Cache-Control": "no-store"])!
            task.didReceive(res)
            task.didReceive(data)
            task.didFinish()
        }
    }

    private func serveFile(_ task: WKURLSchemeTask, path: String) {
        let rel = path == "/" || path == "/index.html" ? "app.html" : String(path.dropFirst())
        let file = webRoot.appendingPathComponent(rel).standardizedFileURL
        guard file.path.hasPrefix(webRoot.standardizedFileURL.path), var data = try? Data(contentsOf: file) else {
            return reply(task, 404, json: ["error": "not_found"])
        }
        if rel == "app.html", let html = String(data: data, encoding: .utf8) {
            data = Data(html.replacingOccurrences(of: "{{MODE}}", with: "bridge")
                .replacingOccurrences(of: "{{VERSION}}", with: "ios").utf8)
        }
        let types = ["html": "text/html; charset=utf-8", "js": "text/javascript; charset=utf-8", "css": "text/css",
                     "svg": "image/svg+xml", "png": "image/png", "webmanifest": "application/manifest+json"]
        reply(task, 200, data: data, type: types[file.pathExtension] ?? "application/octet-stream")
    }

    // MARK: - Kamera

    static func normalize(_ text: String) -> String? {
        var t = text.trimmingCharacters(in: .whitespaces)
        for p in ["http://", "https://"] where t.lowercased().hasPrefix(p) { t = String(t.dropFirst(p.count)) }
        t = String(t.split(separator: "/").first ?? "")
        let parts = t.split(separator: ":")
        guard let host = parts.first, !host.isEmpty,
              host.allSatisfy({ $0.isLetter || $0.isNumber || $0 == "." || $0 == "-" }) else { return nil }
        let port = parts.count > 1 ? Int(parts[1]) ?? 0 : 8080
        guard parts.count <= 2, (1..<65536).contains(port) else { return nil }
        return "\(host):\(port)"
    }

    private func cameraURL(_ pathAndQuery: String) -> URL? {
        guard let a = cameraAddress else { return nil }
        return URL(string: "http://\(a)\(pathAndQuery)")
    }

    private func getJSON(_ path: String, timeout: TimeInterval = 4, done: @escaping (Int, [String: Any]?) -> Void) {
        guard let url = cameraURL(path) else { return done(0, nil) }
        var req = URLRequest(url: url); req.timeoutInterval = timeout
        URLSession.shared.dataTask(with: req) { data, res, _ in
            let code = (res as? HTTPURLResponse)?.statusCode ?? 0
            done(code, data.flatMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] })
        }.resume()
    }

    /// Pfad eines Features aus dem CCAPI-Manifest (höchste Version).
    private func feature(_ name: String) -> String? {
        guard let m = manifest else { return nil }
        var best: String?
        for version in m.keys.sorted() {
            for entry in (m[version] as? [[String: Any]]) ?? [] {
                if let p = entry["path"] as? String, p.hasSuffix("/\(version)/\(name)") { best = p }
            }
        }
        return best
    }

    private func status(done: @escaping ([String: Any]) -> Void) {
        var camera: [String: Any] = ["address": cameraAddress as Any, "state": "no_address", "message": "", "versions": [], "device": NSNull()]
        let bridge: [String: Any] = ["version": "ios", "secure": true, "httpUrls": [], "httpsUrls": [], "caUrl": NSNull()]
        guard cameraAddress != nil else { return done(["bridge": bridge, "camera": camera, "features": [:]]) }
        getJSON("/ccapi", timeout: 3) { code, body in
            if code == 200, let body, !body.isEmpty {
                self.manifest = body
                camera["state"] = "ok"
                camera["versions"] = body.keys.sorted()
                let finish = {
                    camera["device"] = self.device ?? NSNull()
                    let f: [String: Any] = ["polling": self.feature("event/polling") as Any, "liveview": self.feature("shooting/liveview") as Any,
                                            "flip": self.feature("shooting/liveview/flip") as Any, "battery": self.feature("devicestatus/battery") as Any,
                                            "storage": self.feature("devicestatus/storage") as Any, "contents": self.feature("contents") as Any]
                    done(["bridge": bridge, "camera": camera, "features": f.mapValues { $0 is String ? $0 : NSNull() }])
                }
                if self.device == nil, let p = self.feature("deviceinformation") {
                    self.getJSON(p) { c, d in if c == 200 { self.device = d }; finish() }
                } else { finish() }
            } else {
                camera["state"] = code == 0 ? "unreachable" : "not_ccapi"
                camera["message"] = code == 0 ? "Kamera nicht erreichbar." : "Keine CCAPI unter dieser Adresse (HTTP \(code))."
                done(["bridge": bridge, "camera": camera, "features": [:]])
            }
        }
    }

    private func liveview(size: String, done: @escaping (Int, [String: Any]) -> Void) {
        guard ["off", "small", "medium"].contains(size) else { return done(400, ["error": "bad_size", "message": "Ungültige Grösse."]) }
        guard let p = feature("shooting/liveview"), let url = cameraURL(p) else {
            return done(501, ["error": "unsupported", "message": "Kein Livebild über CCAPI."])
        }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try? JSONSerialization.data(withJSONObject: ["liveviewsize": size, "cameradisplay": "on"])
        URLSession.shared.dataTask(with: req) { data, res, err in
            let code = (res as? HTTPURLResponse)?.statusCode ?? 502
            let msg = data.flatMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] }?["message"] as? String
            done(code < 400 ? 200 : code, code < 400 ? ["ok": true] : ["error": "camera_refused", "message": msg ?? err?.localizedDescription ?? "Fehler"])
        }.resume()
    }

    private func discover(port: Int, done: @escaping ([[String: Any]]) -> Void) {
        guard let ip = LocalNetwork.wifiIPv4() else { return done([]) }
        let prefix = ip.split(separator: ".").prefix(3).joined(separator: ".")
        let group = DispatchGroup()
        var found: [[String: Any]] = []
        let q = DispatchQueue(label: "scan")
        let cfg = URLSessionConfiguration.ephemeral
        cfg.timeoutIntervalForRequest = 1.5
        cfg.httpMaximumConnectionsPerHost = 1
        let s = URLSession(configuration: cfg)
        for h in 1...254 where "\(prefix).\(h)" != ip {
            group.enter()
            let addr = "\(prefix).\(h):\(port)"
            s.dataTask(with: URL(string: "http://\(addr)/ccapi")!) { data, res, _ in
                if (res as? HTTPURLResponse)?.statusCode == 200,
                   let d = data, let obj = try? JSONSerialization.jsonObject(with: d) as? [String: Any], !obj.isEmpty {
                    q.sync { found.append(["address": addr, "name": "CCAPI-Kamera"]) }
                }
                group.leave()
            }.resume()
        }
        group.notify(queue: .global()) { s.invalidateAndCancel(); done(found) }
    }

    // MARK: - Kamera-Proxy mit Range-Unterstützung

    private final class Relay {
        let task: WKURLSchemeTask
        var skip: Int64 = 0
        var remaining: Int64 = -1
        let wantRange: (Int64, Int64?)?
        init(task: WKURLSchemeTask, range: (Int64, Int64?)?) { self.task = task; self.wantRange = range }
    }

    private func proxy(_ task: WKURLSchemeTask, camPath: String, query: String?) {
        guard camPath.hasPrefix("/ccapi"), !camPath.contains("..") else { return reply(task, 403, json: ["error": "forbidden"]) }
        guard let url = cameraURL(camPath + (query.map { "?\($0)" } ?? "")) else {
            return reply(task, 503, json: ["error": "no_camera", "message": "Keine Kameraadresse."])
        }
        var req = URLRequest(url: url)
        var range: (Int64, Int64?)?
        if let r = task.request.value(forHTTPHeaderField: "Range"), r.hasPrefix("bytes=") {
            let parts = r.dropFirst(6).split(separator: "-", omittingEmptySubsequences: false)
            if let a = Int64(parts.first ?? "") { range = (a, parts.count > 1 ? Int64(parts[1]) : nil) }
            req.setValue(r, forHTTPHeaderField: "Range")
        }
        let dt = session.dataTask(with: req)
        lock.lock(); running[dt.taskIdentifier] = Relay(task: task, range: range); lock.unlock()
        dt.resume()
    }

    private func relay(for t: URLSessionTask) -> Relay? {
        lock.lock(); defer { lock.unlock() }
        return running[t.taskIdentifier]
    }

    func urlSession(_ s: URLSession, dataTask: URLSessionDataTask, didReceive response: URLResponse,
                    completionHandler: @escaping (URLSession.ResponseDisposition) -> Void) {
        guard let r = relay(for: dataTask), let http = response as? HTTPURLResponse, let url = r.task.request.url else {
            return completionHandler(.cancel)
        }
        var status = http.statusCode
        var headers: [String: String] = ["Content-Type": http.value(forHTTPHeaderField: "Content-Type") ?? "application/octet-stream",
                                         "Cache-Control": "no-store", "Accept-Ranges": "bytes"]
        let total = http.expectedContentLength
        if let want = r.wantRange, status == 200, total > 0 {
            // Kamera ignoriert Range: selbst überspringen
            let end = min(want.1 ?? (total - 1), total - 1)
            r.skip = want.0
            r.remaining = end - want.0 + 1
            status = 206
            headers["Content-Range"] = "bytes \(want.0)-\(end)/\(total)"
            headers["Content-Length"] = "\(r.remaining)"
        } else {
            if let cr = http.value(forHTTPHeaderField: "Content-Range") { headers["Content-Range"] = cr }
            if total >= 0 { headers["Content-Length"] = "\(total)" }
        }
        DispatchQueue.main.async {
            if !self.isStopped(r.task) {
                r.task.didReceive(HTTPURLResponse(url: url, statusCode: status, httpVersion: "HTTP/1.1", headerFields: headers)!)
            }
        }
        completionHandler(.allow)
    }

    func urlSession(_ s: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        guard let r = relay(for: dataTask) else { return }
        var chunk = data
        if r.skip > 0 {
            let n = min(Int64(chunk.count), r.skip)
            chunk = chunk.dropFirst(Int(n)); r.skip -= n
        }
        if r.remaining >= 0 {
            if Int64(chunk.count) > r.remaining { chunk = chunk.prefix(Int(r.remaining)) }
            r.remaining -= Int64(chunk.count)
        }
        guard !chunk.isEmpty else { return }
        let out = Data(chunk)
        DispatchQueue.main.async { if !self.isStopped(r.task) { r.task.didReceive(out) } }
    }

    func urlSession(_ s: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        lock.lock(); let r = running.removeValue(forKey: task.taskIdentifier); lock.unlock()
        guard let r else { return }
        DispatchQueue.main.async {
            guard !self.isStopped(r.task) else { return }
            if let error { r.task.didFailWithError(error) } else { r.task.didFinish() }
        }
    }
}
