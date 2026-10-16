<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\I18n;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;

final class I18nController
{
    public function __construct(private App $app)
    {
    }

    /** GET /api/i18n/{lang} */
    public function bundle(Request $request): Response
    {
        $lang = I18n::normalize($request->attributes['lang'] ?? 'de');
        $r = Response::json($this->app->i18n->bundle($lang));
        $r->headers['Cache-Control'] = 'public, max-age=300';
        return $r;
    }
}
