<?php

declare(strict_types=1);

use SkyKingdoms\Core\View;

return function (): void {
    Test::suite('Vorlagen: Variablen kommen vollständig an');

    // Eine Ansicht, die Variablen mit „heiklen" Namen verwendet.
    $dir = SK_ROOT . '/app/Views/_test';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    file_put_contents($dir . '/probe.php', '<?= $data["name"] ?>|<?= $template ?>|<?= $title ?>');

    $html = View::render('_test/probe', [
        'data'     => ['name' => 'Wolkenfeste'],
        'template' => 'eigenwert',
        'title'    => 'Titel',
    ]);

    Test::eq($html, 'Wolkenfeste|eigenwert|Titel', 'Auch Variablen namens data/template erreichen die Ansicht');

    @unlink($dir . '/probe.php');
    @rmdir($dir);

    Test::throws(
        static fn () => View::render('gibt/es/nicht'),
        'Fehlende Vorlage wirft eine Ausnahme'
    );

    $simple = View::renderSimple('Titel <b>', 'Text & mehr');
    Test::ok(str_contains($simple, 'Titel &lt;b&gt;'), 'Einfache Seite escapt den Titel');
    Test::ok(str_contains($simple, 'Text &amp; mehr'), 'Einfache Seite escapt den Text');
};
