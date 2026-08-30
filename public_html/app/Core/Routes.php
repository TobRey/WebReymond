<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Alle Adressen der Anwendung an einer Stelle.
 *
 * Öffentliche Seiten gibt es zweimal: deutsch ohne Vorsilbe ('/leistungen')
 * und englisch mit '/en' davor ('/en/services').
 */
final class Routes
{
    public static function register(Router $router): void
    {
        self::publicPages($router);
        self::machineFiles($router);
        self::api($router);
        self::preview($router);
        self::assistant($router);
        self::hiddenArea($router);
    }

    // ------------------------------------------------------------------
    // Öffentliche Website
    // ------------------------------------------------------------------

    private static function publicPages(Router $router): void
    {
        $pages = [
            '' => 'SiteController@home',
            'services' => 'SiteController@services',
            'process' => 'SiteController@process',
            'work' => 'SiteController@work',
            'contact' => 'SiteController@contact',
            'imprint' => 'SiteController@imprint',
            'privacy' => 'SiteController@privacy',
        ];

        foreach ($pages as $key => $handler) {
            foreach (I18n::LOCALES as $locale) {
                $path = self::pathFor($key, $locale);
                $router->get($path, $handler);
            }
        }

        // Einzelne Referenz
        $router->get('/referenzen/{slug}', 'SiteController@workDetail');
        $router->get('/en/work/{slug}', 'SiteController@workDetail');
    }

    private static function pathFor(string $key, string $locale): string
    {
        $default = (string) Config::get('default_locale', 'de');
        $prefix = $locale === $default ? '' : '/' . $locale;

        if ($key === '') {
            return $prefix === '' ? '/' : $prefix;
        }

        return $prefix . '/' . I18n::routeSegment($key, $locale);
    }

    // ------------------------------------------------------------------
    // Dateien für Suchmaschinen und Browser
    // ------------------------------------------------------------------

    private static function machineFiles(Router $router): void
    {
        $router->get('/robots.txt', 'SiteController@robots');
        $router->get('/sitemap.xml', 'SiteController@sitemap');
        $router->get('/manifest.webmanifest', 'SiteController@manifest');
    }

    // ------------------------------------------------------------------
    // Öffentliche Schnittstellen
    // ------------------------------------------------------------------

    private static function api(Router $router): void
    {
        $router->group(['csrf'], static function (Router $r): void {
            $r->post('/api/kontakt', 'ContactController@submit');
        });
    }

    // ------------------------------------------------------------------
    // Vorschau der erstellten Kundenwebsites
    // ------------------------------------------------------------------

    private static function preview(Router $router): void
    {
        $router->get('/vorschau/{token}', 'PreviewController@index');
        $router->get('/vorschau/{token}/', 'PreviewController@index');
        $router->get('/vorschau/{token}/{path:.+}', 'PreviewController@file');
    }

    // ------------------------------------------------------------------
    // Relais für die Kunden-Backends
    //
    // Ein Kundenbackend hat keinen eigenen Zugang zur KI. Es schickt seine
    // Anfrage hierher, wo der Schlüssel liegt. So verlässt der Schlüssel
    // niemals diesen Server.
    // ------------------------------------------------------------------

    private static function assistant(Router $router): void
    {
        $router->post('/assistant/v1/edit', 'AssistantController@edit');
        $router->post('/assistant/v1/ping', 'AssistantController@ping');
    }

    // ------------------------------------------------------------------
    // Der versteckte Bereich
    // ------------------------------------------------------------------

    private static function hiddenArea(Router $router): void
    {
        $base = '/' . trim((string) Config::get('create_path', 'create'), '/');

        // Anmeldung – ohne Vorprüfung, sonst käme man nie hinein.
        $router->get($base, 'AuthController@showLogin');
        $router->group(['csrf'], static function (Router $r) use ($base): void {
            $r->post($base, 'AuthController@login');
            $r->post($base . '/abmelden', 'AuthController@logout');
        });

        $router->group(['auth'], static function (Router $r) use ($base): void {
            // Übersicht
            $r->get($base . '/start', 'DashboardController@index');

            // Neues Projekt
            $r->get($base . '/neu', 'CreateController@form');
            $r->get($base . '/projekt/{id}', 'ProjectController@show');
            $r->get($base . '/projekt/{id}/sections', 'ProjectController@sections');
            $r->get($base . '/projekt/{id}/domain', 'DomainController@show');
            $r->get($base . '/projekt/{id}/veroeffentlichen', 'DeployController@show');
            $r->get($base . '/projekt/{id}/zip/{build}', 'DeployController@download');

            // Verwaltung
            $r->get($base . '/anfragen', 'LeadController@index');
            $r->get($base . '/referenzen', 'ShowcaseController@index');
            $r->get($base . '/kosten', 'DashboardController@costs');
            $r->get($base . '/protokoll', 'DashboardController@auditLog');
            $r->get($base . '/einstellungen', 'SettingsController@index');
        });

        $router->group(['auth', 'csrf'], static function (Router $r) use ($base): void {
            $r->post($base . '/neu', 'CreateController@submit');

            $r->post($base . '/projekt/{id}/bauen', 'ProjectController@rebuild');
            $r->post($base . '/projekt/{id}/loeschen', 'ProjectController@destroy');
            $r->post($base . '/projekt/{id}/zip', 'DeployController@createZip');
            $r->post($base . '/projekt/{id}/hochladen', 'DeployController@deploy');
            $r->post($base . '/projekt/{id}/ftp', 'DeployController@saveTarget');
            $r->post($base . '/projekt/{id}/ftp/testen', 'DeployController@testTarget');
            $r->post($base . '/projekt/{id}/domain', 'DomainController@save');
            $r->post($base . '/projekt/{id}/domain/pruefen', 'DomainController@check');
            $r->post($base . '/projekt/{id}/domain/schritt', 'DomainController@step');

            $r->post($base . '/anfragen/{id}/status', 'LeadController@setStatus');
            $r->post($base . '/referenzen/{id}', 'ShowcaseController@update');
            $r->post($base . '/einstellungen', 'SettingsController@save');
        });

        // Schnittstellen des Adminbereichs (JSON)
        $router->group(['auth', 'csrf'], static function (Router $r) use ($base): void {
            $r->post('/api/jobs/{id}/abbrechen', 'JobController@cancel');
            $r->post('/api/sections/{id}/anweisung', 'SectionController@instruct');
            $r->post('/api/sections/{id}/template', 'SectionController@switchTemplate');
            $r->post('/api/sections/{id}/template/neu', 'SectionController@generateTemplate');
            $r->post('/api/sections/{id}/sichtbar', 'SectionController@toggle');
            $r->post('/api/sections/{id}/reihenfolge', 'SectionController@reorder');
            $r->post('/api/sections/{id}/bild', 'SectionController@replaceImage');
            $r->post('/api/sections/{id}/zuruecksetzen', 'SectionController@revert');
        });

        $router->group(['auth'], static function (Router $r) use ($base): void {
            $r->get('/api/jobs/{id}', 'JobController@status');
            $r->get('/api/sections/{id}', 'SectionController@show');
            $r->get('/api/sections/{id}/templates', 'SectionController@templates');
            $r->get('/api/projekte/{id}/verlauf', 'ProjectController@history');
        });

        // Der Worker kann auch über eine Adresse angestossen werden, damit
        // ein Auftrag nicht bis zum nächsten Cron-Lauf wartet.
        $router->get('/worker/tick', 'WorkerController@tick');
        $router->post('/worker/tick', 'WorkerController@tick');
    }
}
