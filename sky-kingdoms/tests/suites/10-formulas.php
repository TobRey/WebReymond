<?php

declare(strict_types=1);

use SkyKingdoms\Core\Num;
use SkyKingdoms\Game\Formulas;

return function (): void {
    Test::suite('Formeln: unbegrenzte Level, Kosten, MAX');

    // --- Wirkung ---------------------------------------------------
    Test::near(Formulas::value(100, 1, 1.01), 100.0, 0.001, 'Stufe 1 entspricht dem Basiswert');
    Test::near(Formulas::value(100, 2, 1.01), 101.0, 0.001, 'Stufe 2 bringt +1 %');
    Test::near(Formulas::value(100, 101, 1.01), 100 * (1.01 ** 100), 0.01, 'Stufe 101 rechnet korrekt weiter');
    Test::greater(Formulas::value(100, 5000, 1.01), 1e19, 'Auch Stufe 5000 liefert noch einen Wert');
    Test::ok(is_finite(Formulas::value(100, 200000, 1.01)), 'Extreme Stufen liefern keinen ungültigen Wert');

    // --- Einzelkosten ----------------------------------------------
    $base = ['wood' => 100, 'stone' => 50];
    Test::eq(Formulas::costAt($base, 1, 1.05)['wood'], 100, 'Erste Stufe kostet den Basispreis');
    Test::eq(Formulas::costAt($base, 2, 1.05)['wood'], 105, 'Zweite Stufe kostet 5 % mehr');

    // --- Mehrfachkosten als geschlossene Reihe ----------------------
    $sumManual = 0.0;
    for ($i = 0; $i < 10; $i++) {
        $sumManual += 100 * (1.05 ** $i);
    }
    $bulk = Formulas::bulkCost($base, 1, 10, 1.05);
    Test::near((float) $bulk['wood'], ceil($sumManual), 1.0, '+10 entspricht der Summe der Einzelstufen');

    $bulk100 = Formulas::bulkCost($base, 1, 100, 1.05);
    Test::greater((float) $bulk100['wood'], (float) $bulk['wood'], '+100 kostet mehr als +10');

    // --- Maximum bezahlbar ------------------------------------------
    $available = ['wood' => 100000, 'stone' => 100000];
    $max = Formulas::maxAffordable($base, 1, $available, 1.05);
    Test::ok($max > 0, 'MAX liefert mindestens eine Stufe');
    Test::ok(Formulas::canAfford(Formulas::bulkCost($base, 1, $max, 1.05), $available), 'MAX-Stufen sind bezahlbar');
    Test::ok(!Formulas::canAfford(Formulas::bulkCost($base, 1, $max + 1, 1.05), $available), 'Eine Stufe mehr ist nicht bezahlbar');

    // Gegenprobe mit stumpfem Durchzählen
    $brute = 0;
    while ($brute < 500 && Formulas::canAfford(Formulas::bulkCost($base, 1, $brute + 1, 1.05), $available)) {
        $brute++;
    }
    Test::eq($max, $brute, 'MAX stimmt mit dem Durchzählen überein');

    Test::eq(Formulas::maxAffordable($base, 1, ['wood' => 10, 'stone' => 10], 1.05), 0, 'Ohne Mittel sind 0 Stufen bezahlbar');
    Test::eq(Formulas::maxAffordable($base, 1, ['wood' => 1e18, 'stone' => 1e18], 1.0575) > 0, true, 'Sehr grosse Bestände ergeben ein sinnvolles Maximum');

    // --- Sicherer Zahlenbereich -------------------------------------
    $huge = Formulas::costAt($base, 100000, 1.0575);
    Test::ok(!Formulas::withinSafeRange($huge), 'Unbezahlbar grosse Kosten gelten als ausserhalb des sicheren Bereichs');
    Test::ok(Formulas::withinSafeRange(Formulas::costAt($base, 50, 1.0575)), 'Normale Kosten liegen im sicheren Bereich');

    // --- Meilensteine -----------------------------------------------
    Test::eq(Formulas::tier(1), 0, 'Stufe 1 hat die erste Optik');
    Test::eq(Formulas::tier(10), 1, 'Stufe 10 erreicht den ersten Meilenstein');
    Test::eq(Formulas::tier(1000), 6, 'Sehr hohe Stufen erreichen die höchste Optik');
    Test::eq(Formulas::nextMilestone(1), 10, 'Nächster Meilenstein nach Stufe 1 ist 10');
    Test::eq(Formulas::nextMilestone(9999), null, 'Jenseits aller Meilensteine gibt es keinen nächsten mehr');

    // --- Zahlenformat ------------------------------------------------
    Test::eq(Num::compact(942), '942', 'Kleine Zahlen bleiben unverändert');
    Test::eq(Num::compact(12400), '12,4K', 'Tausender werden zu K');
    Test::eq(Num::compact(3200000), '3,2M', 'Millionen werden zu M');
    Test::eq(Num::compact(1100000000), '1,1B', 'Milliarden werden zu B');
    Test::eq(Num::compact(-4500), '-4,5K', 'Negative Zahlen behalten das Vorzeichen');
    Test::eq(Num::clampAmount(-5), 0, 'Negative Mengen werden auf 0 begrenzt');
    Test::eq(Num::clampAmount(INF), (int) 9.0e17, 'Unendlich wird auf den sicheren Höchstwert begrenzt');
};
