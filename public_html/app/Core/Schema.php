<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Legt die Tabellen an und hält sie aktuell.
 *
 * Die Tabellen werden einmal beschrieben und für MySQL wie für SQLite
 * übersetzt. So laufen die automatischen Tests ohne MySQL-Server, während
 * auf dem Hosting dieselbe Struktur entsteht.
 *
 * WICHTIG: Das hier ist ausschliesslich die Datenbank von WebAtze selbst.
 * Generierte Kundenwebsites bekommen niemals von sich aus eine Datenbank.
 */
final class Schema
{
    /** Wird bei jeder Änderung erhöht; migrate() spielt fehlende Schritte nach. */
    public const VERSION = 1;

    public static function migrate(): void
    {
        $pdo = Db::connection();
        $sqlite = Db::isSqlite();

        // Meta-Tabelle zuerst – sie merkt sich den Stand.
        $pdo->exec(self::translate(
            'CREATE TABLE IF NOT EXISTS migrations (
                name {string:191} NOT NULL PRIMARY KEY,
                applied_at {datetime} NOT NULL
            )',
            $sqlite
        ));

        foreach (self::statements() as $name => $sql) {
            $done = Db::value('SELECT 1 FROM migrations WHERE name = :n', ['n' => $name]);
            if ($done) {
                continue;
            }
            foreach (self::split($sql) as $part) {
                self::execute($pdo, self::translate($part, $sqlite));
            }
            Db::insert('migrations', ['name' => $name, 'applied_at' => Db::now()]);
        }
    }

    /** @return array<string,string> */
    private static function statements(): array
    {
        return [
            // ---------------------------------------------------------- Konten
            '001_users' => '
                CREATE TABLE IF NOT EXISTS users (
                    id {id},
                    username {string:64} NOT NULL,
                    password_hash {string:255} NOT NULL,
                    display_name {string:120} NOT NULL DEFAULT \'\',
                    role {string:20} NOT NULL DEFAULT \'owner\',
                    created_at {datetime} NOT NULL,
                    last_login_at {datetime} NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users (username);
            ',

            '002_sessions' => '
                CREATE TABLE IF NOT EXISTS auth_sessions (
                    id {string:64} NOT NULL PRIMARY KEY,
                    user_id {int} NOT NULL,
                    ip {string:45} NOT NULL DEFAULT \'\',
                    user_agent {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    last_seen_at {datetime} NOT NULL,
                    expires_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_sessions_user ON auth_sessions (user_id);
                CREATE INDEX IF NOT EXISTS idx_sessions_expires ON auth_sessions (expires_at);
            ',

            '003_login_attempts' => '
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id {id},
                    ip {string:45} NOT NULL,
                    username {string:64} NOT NULL DEFAULT \'\',
                    success {bool} NOT NULL DEFAULT 0,
                    attempted_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts (ip, attempted_at);
                CREATE INDEX IF NOT EXISTS idx_attempts_user ON login_attempts (username, attempted_at);
            ',

            // ---------------------------------------------------------- Projekte
            '004_projects' => '
                CREATE TABLE IF NOT EXISTS projects (
                    id {id},
                    slug {string:120} NOT NULL,
                    name {string:191} NOT NULL,
                    status {string:24} NOT NULL DEFAULT \'draft\',
                    brief {text} NULL,
                    theme {text} NULL,
                    plan {text} NULL,
                    locale {string:5} NOT NULL DEFAULT \'de\',
                    locales {string:64} NOT NULL DEFAULT \'de\',
                    domain {string:191} NOT NULL DEFAULT \'\',
                    wants_admin {bool} NOT NULL DEFAULT 0,
                    admin_username {string:64} NOT NULL DEFAULT \'\',
                    admin_password_hash {string:255} NOT NULL DEFAULT \'\',
                    assistant_token {string:64} NOT NULL DEFAULT \'\',
                    preview_token {string:64} NOT NULL DEFAULT \'\',
                    preview_expires_at {datetime} NULL,
                    published_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_slug ON projects (slug);
                CREATE INDEX IF NOT EXISTS idx_projects_status ON projects (status);
                CREATE INDEX IF NOT EXISTS idx_projects_preview ON projects (preview_token);
            ',

            '005_project_pages' => '
                CREATE TABLE IF NOT EXISTS project_pages (
                    id {id},
                    project_id {int} NOT NULL,
                    path {string:191} NOT NULL,
                    title {string:191} NOT NULL,
                    meta_description {string:255} NOT NULL DEFAULT \'\',
                    sort_order {int} NOT NULL DEFAULT 0,
                    in_navigation {bool} NOT NULL DEFAULT 1,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_pages_project ON project_pages (project_id, sort_order);
            ',

            '006_project_sections' => '
                CREATE TABLE IF NOT EXISTS project_sections (
                    id {id},
                    project_id {int} NOT NULL,
                    page_id {int} NOT NULL,
                    type {string:40} NOT NULL,
                    template_key {string:60} NOT NULL,
                    content {text} NULL,
                    overrides {text} NULL,
                    hidden {bool} NOT NULL DEFAULT 0,
                    sort_order {int} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_sections_page ON project_sections (page_id, sort_order);
                CREATE INDEX IF NOT EXISTS idx_sections_project ON project_sections (project_id);
            ',

            '007_project_assets' => '
                CREATE TABLE IF NOT EXISTS project_assets (
                    id {id},
                    project_id {int} NOT NULL,
                    kind {string:24} NOT NULL DEFAULT \'image\',
                    path {string:255} NOT NULL,
                    original_name {string:255} NOT NULL DEFAULT \'\',
                    alt {string:255} NOT NULL DEFAULT \'\',
                    width {int} NOT NULL DEFAULT 0,
                    height {int} NOT NULL DEFAULT 0,
                    bytes {int} NOT NULL DEFAULT 0,
                    source_url {string:500} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_assets_project ON project_assets (project_id);
            ',

            '008_section_changes' => '
                CREATE TABLE IF NOT EXISTS section_changes (
                    id {id},
                    project_id {int} NOT NULL,
                    section_id {int} NOT NULL,
                    source {string:24} NOT NULL DEFAULT \'ai\',
                    actor {string:64} NOT NULL DEFAULT \'\',
                    instruction {text} NULL,
                    summary {string:500} NOT NULL DEFAULT \'\',
                    patch {text} NULL,
                    before_state {text} NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_changes_section ON section_changes (section_id, id);
                CREATE INDEX IF NOT EXISTS idx_changes_project ON section_changes (project_id, id);
            ',

            // ---------------------------------------------------------- Aufträge
            '009_jobs' => '
                CREATE TABLE IF NOT EXISTS jobs (
                    id {id},
                    project_id {int} NULL,
                    type {string:40} NOT NULL,
                    step {string:40} NOT NULL DEFAULT \'\',
                    status {string:20} NOT NULL DEFAULT \'queued\',
                    progress {int} NOT NULL DEFAULT 0,
                    message {string:255} NOT NULL DEFAULT \'\',
                    payload {text} NULL,
                    state {text} NULL,
                    error {text} NULL,
                    attempts {int} NOT NULL DEFAULT 0,
                    locked_until {datetime} NULL,
                    run_after {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    started_at {datetime} NULL,
                    finished_at {datetime} NULL
                );
                CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs (status, run_after);
                CREATE INDEX IF NOT EXISTS idx_jobs_project ON jobs (project_id, id);
            ',

            '010_ai_calls' => '
                CREATE TABLE IF NOT EXISTS ai_calls (
                    id {id},
                    project_id {int} NULL,
                    job_id {int} NULL,
                    purpose {string:40} NOT NULL DEFAULT \'\',
                    model {string:60} NOT NULL DEFAULT \'\',
                    input_tokens {int} NOT NULL DEFAULT 0,
                    cache_write_tokens {int} NOT NULL DEFAULT 0,
                    cache_read_tokens {int} NOT NULL DEFAULT 0,
                    output_tokens {int} NOT NULL DEFAULT 0,
                    cost_micro {int} NOT NULL DEFAULT 0,
                    duration_ms {int} NOT NULL DEFAULT 0,
                    ok {bool} NOT NULL DEFAULT 1,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_ai_project ON ai_calls (project_id);
                CREATE INDEX IF NOT EXISTS idx_ai_created ON ai_calls (created_at);
            ',

            // ---------------------------------------------------------- Auslieferung
            '011_builds' => '
                CREATE TABLE IF NOT EXISTS builds (
                    id {id},
                    project_id {int} NOT NULL,
                    version {int} NOT NULL DEFAULT 1,
                    zip_path {string:255} NOT NULL DEFAULT \'\',
                    zip_bytes {int} NOT NULL DEFAULT 0,
                    files_count {int} NOT NULL DEFAULT 0,
                    notes {string:500} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_builds_project ON builds (project_id, version);
            ',

            '012_deploy_targets' => '
                CREATE TABLE IF NOT EXISTS deploy_targets (
                    id {id},
                    project_id {int} NOT NULL,
                    protocol {string:10} NOT NULL DEFAULT \'ftp\',
                    host {string:191} NOT NULL DEFAULT \'\',
                    port {int} NOT NULL DEFAULT 21,
                    username {string:191} NOT NULL DEFAULT \'\',
                    secret {text} NULL,
                    remote_path {string:255} NOT NULL DEFAULT \'/public_html\',
                    last_result {string:500} NOT NULL DEFAULT \'\',
                    last_deployed_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_deploy_project ON deploy_targets (project_id);
            ',

            '013_domain_transfers' => '
                CREATE TABLE IF NOT EXISTS domain_transfers (
                    id {id},
                    project_id {int} NOT NULL,
                    domain {string:191} NOT NULL,
                    mode {string:20} NOT NULL DEFAULT \'transfer\',
                    registrar {string:60} NOT NULL DEFAULT \'\',
                    target_ip {string:45} NOT NULL DEFAULT \'\',
                    target_nameservers {string:500} NOT NULL DEFAULT \'\',
                    current_step {int} NOT NULL DEFAULT 0,
                    steps {text} NULL,
                    checks {text} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_transfer_project ON domain_transfers (project_id);
            ',

            // ---------------------------------------------------------- Website
            '014_leads' => '
                CREATE TABLE IF NOT EXISTS leads (
                    id {id},
                    name {string:191} NOT NULL DEFAULT \'\',
                    email {string:191} NOT NULL DEFAULT \'\',
                    phone {string:60} NOT NULL DEFAULT \'\',
                    company {string:191} NOT NULL DEFAULT \'\',
                    subject {string:191} NOT NULL DEFAULT \'\',
                    message {text} NULL,
                    locale {string:5} NOT NULL DEFAULT \'de\',
                    source {string:60} NOT NULL DEFAULT \'website\',
                    status {string:20} NOT NULL DEFAULT \'new\',
                    ip {string:45} NOT NULL DEFAULT \'\',
                    mail_sent {bool} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_leads_status ON leads (status, id);
            ',

            '015_references' => '
                CREATE TABLE IF NOT EXISTS showcase (
                    id {id},
                    project_id {int} NULL,
                    slug {string:120} NOT NULL,
                    title {string:191} NOT NULL,
                    subtitle {string:255} NOT NULL DEFAULT \'\',
                    body_de {text} NULL,
                    body_en {text} NULL,
                    tags {string:255} NOT NULL DEFAULT \'\',
                    live_url {string:255} NOT NULL DEFAULT \'\',
                    preview_path {string:255} NOT NULL DEFAULT \'\',
                    accent_color {string:9} NOT NULL DEFAULT \'\',
                    published {bool} NOT NULL DEFAULT 1,
                    sort_order {int} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_showcase_slug ON showcase (slug);
                CREATE INDEX IF NOT EXISTS idx_showcase_pub ON showcase (published, sort_order);
            ',

            '016_settings' => '
                CREATE TABLE IF NOT EXISTS settings (
                    key_name {string:120} NOT NULL PRIMARY KEY,
                    value {text} NULL,
                    updated_at {datetime} NOT NULL
                );
            ',

            '017_audit_log' => '
                CREATE TABLE IF NOT EXISTS audit_log (
                    id {id},
                    user_id {int} NULL,
                    actor {string:64} NOT NULL DEFAULT \'\',
                    action {string:80} NOT NULL,
                    subject {string:191} NOT NULL DEFAULT \'\',
                    meta {text} NULL,
                    ip {string:45} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);
                CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log (action);
            ',

            '018_rate_limits' => '
                CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket {string:191} NOT NULL PRIMARY KEY,
                    hits {int} NOT NULL DEFAULT 0,
                    reset_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_rate_reset ON rate_limits (reset_at);
            ',
        ];
    }

    /** Mini-Platzhalter in echten SQL-Typ übersetzen. */
    private static function translate(string $sql, bool $sqlite): string
    {
        $map = $sqlite
            ? [
                '{id}' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '{int}' => 'INTEGER',
                '{bool}' => 'INTEGER',
                '{text}' => 'TEXT',
                '{datetime}' => 'TEXT',
            ]
            : [
                '{id}' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                '{int}' => 'INT',
                '{bool}' => 'TINYINT(1)',
                '{text}' => 'MEDIUMTEXT',
                '{datetime}' => 'DATETIME',
            ];

        $sql = strtr($sql, $map);

        // {string:191} -> VARCHAR(191) bzw. TEXT bei SQLite
        $sql = preg_replace_callback(
            '/\{string:(\d+)\}/',
            static fn (array $m): string => $sqlite ? 'TEXT' : 'VARCHAR(' . $m[1] . ')',
            $sql
        ) ?? $sql;

        $sql = rtrim(trim($sql), ';');

        if (!$sqlite) {
            // Nur Tabellen bekommen die Speicher-Angabe, Indizes nicht.
            if (stripos($sql, 'CREATE TABLE') === 0) {
                $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            }
            // MySQL (anders als MariaDB) kennt "IF NOT EXISTS" bei Indizes nicht.
            // Doppelte Anlagen fängt execute() ohnehin ab.
            if (stripos($sql, 'CREATE INDEX') === 0 || stripos($sql, 'CREATE UNIQUE INDEX') === 0) {
                $sql = str_ireplace(' IF NOT EXISTS ', ' ', $sql);
            }
        }

        return trim($sql);
    }

    /**
     * Anweisung ausführen und "existiert schon" als Erfolg werten.
     *
     * Migrationen laufen nur einmal, aber wenn eine Tabelle von Hand angelegt
     * wurde oder ein früherer Durchlauf mittendrin abbrach, soll das Nachholen
     * nicht am bereits erledigten Teil scheitern.
     */
    private static function execute(\PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            $harmless = str_contains($message, 'already exists')
                || str_contains($message, 'duplicate key name')
                || str_contains($message, 'duplicate column');
            if (!$harmless) {
                throw $e;
            }
        }
    }

    /** Mehrere Anweisungen in einem Block trennen. */
    private static function split(string $sql): array
    {
        $parts = array_map('trim', explode(';', $sql));
        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
