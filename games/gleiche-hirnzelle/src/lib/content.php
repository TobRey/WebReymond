<?php
declare(strict_types=1);

/*
 * Eingebaute Inhalte als Ersatz, falls Claude gerade nicht erreichbar ist.
 */

if (!defined('GH_APP')) {
    http_response_code(404);
    exit;
}

const GH_FALLBACK_QUESTIONS = [
    'Welches Tier wäre der schlechteste Pilot?',
    'Was riecht in einer Turnhalle am meisten nach Verzweiflung?',
    'Welches Gemüse hat heimlich das grösste Ego?',
    'Was würde ein Toaster als Hobby wählen?',
    'Welches Wort schreit man, wenn man in einen Legostein tritt?',
    'Welche Superkraft wäre im Büro am nutzlosesten?',
    'Was liegt garantiert in jeder Krimskrams-Schublade?',
    'Welches Tier wäre der beste Barkeeper?',
    'Was isst ein Vampir zum Frühstück?',
    'Welcher Gegenstand hat am meisten Geheimnisse?',
    'Wie heisst der Hund eines Piraten?',
    'Was ist das langweiligste Geschenk der Welt?',
    'Welches Geräusch macht ein müder Roboter?',
    'Womit würde eine Oma eine Zombie-Apokalypse überleben?',
    'Welches Möbelstück hätte die schlechteste Laune?',
    'Was ist das erste, was ein Alien auf der Erde kauft?',
    'Welcher Geschmack fehlt bei Chips noch dringend?',
    'Welches Tier würde am meisten Steuern hinterziehen?',
    'Was trägt ein Pinguin an einer Hochzeit?',
    'Welches Küchengerät wäre der beste Bodyguard?',
    'Was sagt eine Katze, wenn niemand zuhört?',
    'Welche Farbe hat Montagmorgen?',
    'Welches Obst wäre der beste Detektiv?',
    'Was findet man im Rucksack eines Einhorns?',
    'Welcher Beruf wäre für einen Oktopus perfekt?',
    'Was ist das peinlichste Wort, um es aus Versehen laut zu sagen?',
    'Welches Getränk passt zu einer Weltraummission?',
    'Was würde ein Dinosaurier heute im Internet googeln?',
    'Welcher Snack ist der Endgegner jeder Diät?',
    'Welche Sportart würden Schnecken erfinden?',
    'Was ist der beste Name für ein Goldfisch-Duo-Album?',
    'Welches Tier wäre der schlechteste Mitbewohner?',
    'Was versteckt ein Schneemann unter seinem Hut?',
    'Womit bezahlt man in einem Feenland?',
    'Welches Wort beschreibt einen Kater am Sonntagmorgen?',
    'Welches Werkzeug benutzt ein Zauberer, wenn der Zauberstab streikt?',
    'Was ist das gruseligste Lebensmittel im Kühlschrank?',
    'Welches Instrument spielt ein Bär?',
    'Was bestellt ein Gespenst im Restaurant?',
    'Welcher Gegenstand wäre der beste Präsident?',
    'Was ist das Lieblingswort eines Teenagers?',
    'Welches Tier hat den coolsten Tanzstil?',
    'Was würde ein Kaktus umarmen wollen?',
    'Welche Jahreszeit wäre als Mensch am nervigsten?',
    'Was steht auf dem Einkaufszettel eines Riesen?',
    'Welches Gericht sollte man nie auf einem ersten Date bestellen?',
    'Welches Körperteil wäre der beste Sänger?',
    'Was benutzt ein Pirat als Kopfkissen?',
    'Welches Tier wäre ein schrecklicher Lehrer?',
    'Was hört eine Kuh im Kopfhörer?',
    'Welcher Gegenstand verschwindet in jedem Haushalt spurlos?',
    'Wie heisst das Lieblingsrestaurant von Mäusen?',
    'Welcher Wochentag würde in einer Bar die meisten Drinks bestellen?',
    'Welches Fahrzeug passt am besten zu einer Giraffe?',
    'Was isst ein Roboter als Dessert?',
    'Welche Emotion hat ein Kühlschrank um drei Uhr nachts?',
    'Was ist das beste Versteck bei einem Versteckspiel unter Erwachsenen?',
    'Welches Tier hätte den besten Schnurrbart?',
    'Was würde ein Baby als Erstes sagen, wenn es fluchen könnte?',
    'Welches Gewürz beschreibt deine Persönlichkeit am Freitagabend?',
    'Was tun Socken, wenn sie aus der Waschmaschine fliehen?',
    'Welcher Gegenstand wäre der beste Haustier-Ersatz?',
    'Welches Wort reimt sich auf Liebe und ist trotzdem unromantisch?',
    'Was nimmt man auf eine einsame Insel mit, wenn man nur Unsinn darf?',
    'Welches Tier würde einen Influencer-Account führen?',
    'Was sammelt ein Drache heimlich ausser Gold?',
    'Welche Süssigkeit wäre der beste Bösewicht?',
    'Welches Geräusch macht ein Herz, das gerade bricht?',
    'Was ist das unpraktischste Haustier für eine Wohnung?',
    'Wohin fährt eine Kartoffel in die Ferien?',
    'Welche Schuhart passt zu einem Wikinger?',
    'Was ist der Lieblingsfilm eines Hamsters?',
    'Welches Wort sagt man, wenn das WLAN ausfällt?',
    'Welcher Vogel wäre der beste Rapper?',
    'Was ist in der Handtasche einer Hexe ganz oben?',
    'Welcher Käse hat am meisten Drama?',
    'Was benutzt ein Ritter als Smartphone?',
    'Welches Tier wäre ein genialer Erfinder?',
    'Was ist die perfekte Pizza-Zutat für Aliens?',
    'Womit würde ein Bär seine Wohnung dekorieren?',
];

const GH_FALLBACK_COMMENTS = [
    10 => [
        'Eine Hirnzelle, zwei Körper. Wissenschaft hasst diesen Trick!',
        'Synchron wie zwei Wellensittiche auf derselben Stange!',
        'Habt ihr euch abgesprochen? Ehrlich jetzt!',
        'Telepathie-Level: Zwillinge, die zusammen Lotto gewinnen.',
    ],
    'high' => [
        'Fast dieselbe Hirnzelle – nur einmal kurz falsch abgebogen.',
        'So nah dran, dass es schon fast unheimlich ist.',
        'Gleiche Wellenlänge, nur leicht verrauschter Empfang.',
    ],
    'mid' => [
        'Ihr wohnt im selben Gehirn, aber in verschiedenen Stockwerken.',
        'Irgendwo in der Mitte habt ihr euch kurz zugewinkt.',
        'Ein Hauch von Verbindung, wie zwei Socken aus derselben Schublade.',
    ],
    'low' => [
        'Zwei Hirnzellen, null gemeinsame Postleitzahl.',
        'Das war kein Treffer, das war ein Ausflug in Paralleluniversen.',
        'Eure Gedanken haben sich nicht mal im Treppenhaus gesehen.',
    ],
];

const GH_FALLBACK_TITLES = [
    [90, 'Die Siamesische Hirnzelle', 'Ihr teilt euch offiziell ein Gehirn – und es läuft erschreckend gut.'],
    [75, 'Das Telepathische Toastbrot-Duo', 'Fast immer auf derselben Wellenlänge. Fast.'],
    [60, 'Die Halbsynchronen Wackelpuddings', 'Meistens im Takt, manchmal einfach nur am Wackeln.'],
    [45, 'Das Kuschelige Funkloch', 'Ihr empfangt euch – mit Rauschen.'],
    [30, 'Die Zwei Verirrten Socken', 'Aus derselben Wäsche, aber nie im selben Paar.'],
    [0, 'Das Paralleluniversen-Picknick', 'Ihr denkt in verschiedenen Galaxien – aber ihr bringt Snacks mit.'],
];

function gh_pick(array $list): string
{
    return $list[random_int(0, count($list) - 1)];
}

function gh_fallback_comment(int $score): string
{
    $bucket = $score >= 10 ? 10 : ($score >= 7 ? 'high' : ($score >= 4 ? 'mid' : 'low'));
    return gh_pick(GH_FALLBACK_COMMENTS[$bucket]);
}

function gh_fallback_title(int $total): array
{
    foreach (GH_FALLBACK_TITLES as [$min, $title, $subtitle]) {
        if ($total >= $min) {
            return ['title' => $title, 'subtitle' => $subtitle];
        }
    }
    return ['title' => 'Das Unergründliche Duo', 'subtitle' => 'Selbst die Wissenschaft ist ratlos.'];
}
