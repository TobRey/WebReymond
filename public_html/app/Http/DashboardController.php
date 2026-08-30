<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, Request, Response, View};

/**
 * Übersicht, Kostenaufstellung und Protokoll.
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        $projects = Db::all(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM project_pages WHERE project_id = p.id) AS page_count,
                    (SELECT MAX(version) FROM builds WHERE project_id = p.id) AS build_version
             FROM projects p
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT 60'
        );

        $activeJobs = Db::all(
            "SELECT j.*, p.name AS project_name, p.slug AS project_slug
             FROM jobs j LEFT JOIN projects p ON p.id = j.project_id
             WHERE j.status IN ('queued', 'running')
             ORDER BY j.id DESC LIMIT 10"
        );

        $failedJobs = Db::all(
            "SELECT j.*, p.name AS project_name
             FROM jobs j LEFT JOIN projects p ON p.id = j.project_id
             WHERE j.status = 'failed' AND j.finished_at > :since
             ORDER BY j.id DESC LIMIT 10",
            ['since' => date('Y-m-d H:i:s', time() - 7 * 86400)]
        );

        return $this->page('Übersicht', 'admin/dashboard', [
            'projects' => $projects,
            'activeJobs' => $activeJobs,
            'failedJobs' => $failedJobs,
            'stats' => $this->stats(),
        ]);
    }

    public function costs(Request $request): Response
    {
        $month = date('Y-m');

        $summary = Db::first(
            "SELECT COUNT(*) AS calls,
                    COALESCE(SUM(cost_micro), 0) AS cost,
                    COALESCE(SUM(input_tokens + cache_read_tokens + cache_write_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens
             FROM ai_calls WHERE created_at >= :from",
            ['from' => $month . '-01 00:00:00']
        ) ?? [];

        $perProject = Db::all(
            "SELECT p.id, p.name, p.slug,
                    COUNT(a.id) AS calls,
                    COALESCE(SUM(a.cost_micro), 0) AS cost
             FROM ai_calls a
             JOIN projects p ON p.id = a.project_id
             GROUP BY p.id, p.name, p.slug
             ORDER BY cost DESC
             LIMIT 40"
        );

        $perMonth = Db::all(
            "SELECT " . (Db::isSqlite()
                ? "substr(created_at, 1, 7)"
                : "DATE_FORMAT(created_at, '%Y-%m')") . " AS month,
                    COUNT(*) AS calls,
                    COALESCE(SUM(cost_micro), 0) AS cost
             FROM ai_calls
             GROUP BY month
             ORDER BY month DESC
             LIMIT 12"
        );

        $total = (int) Db::value('SELECT COALESCE(SUM(cost_micro), 0) FROM ai_calls', [], 0);

        return $this->page('Kosten', 'admin/costs', [
            'summary' => $summary,
            'perProject' => $perProject,
            'perMonth' => $perMonth,
            'total' => $total,
            'month' => $month,
        ]);
    }

    public function auditLog(Request $request): Response
    {
        $page = max(1, $request->int('seite', 1));
        $perPage = 60;

        $entries = Audit::recent($perPage, ($page - 1) * $perPage);
        $total = (int) Db::value('SELECT COUNT(*) FROM audit_log', [], 0);

        return $this->page('Protokoll', 'admin/audit', [
            'entries' => $entries,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'total' => $total,
        ]);
    }

    // ------------------------------------------------------------------

    private function stats(): array
    {
        $costThisMonth = (int) Db::value(
            'SELECT COALESCE(SUM(cost_micro), 0) FROM ai_calls WHERE created_at >= :from',
            ['from' => date('Y-m') . '-01 00:00:00'],
            0
        );

        return [
            'projects' => (int) Db::value('SELECT COUNT(*) FROM projects', [], 0),
            'live' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE status = 'live'", [], 0),
            'leads_new' => (int) Db::value("SELECT COUNT(*) FROM leads WHERE status = 'new'", [], 0),
            'cost_month' => $costThisMonth,
        ];
    }

    private function page(string $title, string $template, array $data): Response
    {
        return Response::html(View::partial('layouts/admin', array_merge($data, [
            'title' => $title,
            'content' => View::partial($template, $data),
        ])))->noCache()->noIndex();
    }
}
