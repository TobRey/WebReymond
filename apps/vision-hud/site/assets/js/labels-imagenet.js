/**
 * Die 1000 Klassen der Zweitstufe (ImageNet) mit deutschem Namen und Gruppe.
 *
 * Reihenfolge = Ausgabereihenfolge des Modells. Eine Zeile je Klasse,
 * Format „deutscher Name|Gruppe“. Bei den 120 Hunderassen steht die Rasse
 * in Klammern hinter „Hund“ – der Rahmen zeigt dann „HUND (BEAGLE)“.
 *
 * Gruppen wie in labels-oiv7.js.
 */

const TABLE = `Schleie|animal
Goldfisch|animal
Weisser Hai|animal
Tigerhai|animal
Hammerhai|animal
Zitterrochen|animal
Stachelrochen|animal
Hahn|bird
Henne|bird
Strauss|bird
Bergfink|bird
Stieglitz|bird
Hausgimpel|bird
Junko|bird
Indigofink|bird
Rotkehlchen|bird
Bülbül|bird
Häher|bird
Elster|bird
Meise|bird
Wasseramsel|bird
Milan|bird
Weisskopfseeadler|bird
Geier|bird
Bartkauz|bird
Feuersalamander|animal
Molch|animal
Molch|animal
Salamander|animal
Axolotl|animal
Ochsenfrosch|animal
Laubfrosch|animal
Frosch|animal
Meeresschildkröte|animal
Lederschildkröte|animal
Schlammschildkröte|animal
Sumpfschildkröte|animal
Dosenschildkröte|animal
Gecko|animal
Leguan|animal
Anolis|animal
Rennechse|animal
Agame|animal
Kragenechse|animal
Alligatorschleiche|animal
Krustenechse|animal
Smaragdeidechse|animal
Chamäleon|animal
Komodowaran|animal
Krokodil|animal
Alligator|animal
Triceratops|toy
Schlange|animal
Halsbandnatter|animal
Hakennasennatter|animal
Grüne Natter|animal
Königsnatter|animal
Strumpfbandnatter|animal
Wassernatter|animal
Peitschennatter|animal
Nachtnatter|animal
Boa|animal
Python|animal
Kobra|animal
Mamba|animal
Seeschlange|animal
Hornviper|animal
Klapperschlange|animal
Klapperschlange|animal
Trilobit|misc
Weberknecht|animal
Skorpion|animal
Gartenspinne|animal
Spinne|animal
Kreuzspinne|animal
Schwarze Witwe|animal
Vogelspinne|animal
Wolfsspinne|animal
Zecke|animal
Hundertfüsser|animal
Birkhuhn|bird
Schneehuhn|bird
Kragenhuhn|bird
Präriehuhn|bird
Pfau|bird
Wachtel|bird
Rebhuhn|bird
Graupapagei|bird
Ara|bird
Kakadu|bird
Lori|bird
Spornkuckuck|bird
Bienenfresser|bird
Nashornvogel|bird
Kolibri|bird
Glanzvogel|bird
Tukan|bird
Erpel|bird
Säger|bird
Gans|bird
Schwarzschwan|bird
Elefant|animal
Ameisenigel|animal
Schnabeltier|animal
Wallaby|animal
Koala|animal
Wombat|animal
Qualle|animal
Seeanemone|animal
Koralle|animal
Plattwurm|animal
Fadenwurm|animal
Meeresschnecke|animal
Schnecke|animal
Nacktschnecke|animal
Meeresschnecke|animal
Käferschnecke|animal
Nautilus|animal
Krabbe|animal
Krabbe|animal
Winkerkrabbe|animal
Königskrabbe|animal
Hummer|animal
Languste|animal
Flusskrebs|animal
Einsiedlerkrebs|animal
Assel|animal
Storch|bird
Schwarzstorch|bird
Löffler|bird
Flamingo|bird
Reiher|bird
Silberreiher|bird
Rohrdommel|bird
Kranich|bird
Rallenkranich|bird
Purpurhuhn|bird
Blässhuhn|bird
Trappe|bird
Steinwälzer|bird
Strandläufer|bird
Rotschenkel|bird
Schlammläufer|bird
Austernfischer|bird
Pelikan|bird
Königspinguin|bird
Albatros|bird
Grauwal|animal
Orca|animal
Dugong|animal
Seelöwe|animal
Hund (Chihuahua)|dog
Hund (Japan-Chin)|dog
Hund (Malteser)|dog
Hund (Pekinese)|dog
Hund (Shih Tzu)|dog
Hund (Cavalier)|dog
Hund (Papillon)|dog
Hund (Toy-Terrier)|dog
Hund (Ridgeback)|dog
Hund (Afghane)|dog
Hund (Basset)|dog
Hund (Beagle)|dog
Hund (Bluthund)|dog
Hund (Bluetick)|dog
Hund (Coonhound)|dog
Hund (Walker Hound)|dog
Hund (Foxhound)|dog
Hund (Redbone)|dog
Hund (Barsoi)|dog
Hund (Wolfshund)|dog
Hund (Windspiel)|dog
Hund (Whippet)|dog
Hund (Podenco)|dog
Hund (Elchhund)|dog
Hund (Otterhund)|dog
Hund (Saluki)|dog
Hund (Deerhound)|dog
Hund (Weimaraner)|dog
Hund (Staffordshire)|dog
Hund (Am. Staffordshire)|dog
Hund (Bedlington)|dog
Hund (Border Terrier)|dog
Hund (Kerry Blue)|dog
Hund (Irish Terrier)|dog
Hund (Norfolk Terrier)|dog
Hund (Norwich Terrier)|dog
Hund (Yorkshire)|dog
Hund (Foxterrier)|dog
Hund (Lakeland)|dog
Hund (Sealyham)|dog
Hund (Airedale)|dog
Hund (Cairn)|dog
Hund (Australian Terrier)|dog
Hund (Dandie Dinmont)|dog
Hund (Boston Terrier)|dog
Hund (Zwergschnauzer)|dog
Hund (Riesenschnauzer)|dog
Hund (Schnauzer)|dog
Hund (Scotch Terrier)|dog
Hund (Tibet-Terrier)|dog
Hund (Silky Terrier)|dog
Hund (Wheaten Terrier)|dog
Hund (Westie)|dog
Hund (Lhasa Apso)|dog
Hund (Flat-Coated Retriever)|dog
Hund (Curly-Coated Retriever)|dog
Hund (Golden Retriever)|dog
Hund (Labrador)|dog
Hund (Chesapeake Retriever)|dog
Hund (Kurzhaar)|dog
Hund (Vizsla)|dog
Hund (English Setter)|dog
Hund (Irish Setter)|dog
Hund (Gordon Setter)|dog
Hund (Brittany)|dog
Hund (Clumber)|dog
Hund (Springer Spaniel)|dog
Hund (Welsh Springer)|dog
Hund (Cocker Spaniel)|dog
Hund (Sussex Spaniel)|dog
Hund (Water Spaniel)|dog
Hund (Kuvasz)|dog
Hund (Schipperke)|dog
Hund (Groenendael)|dog
Hund (Malinois)|dog
Hund (Briard)|dog
Hund (Kelpie)|dog
Hund (Komondor)|dog
Hund (Bobtail)|dog
Hund (Sheltie)|dog
Hund (Collie)|dog
Hund (Border Collie)|dog
Hund (Bouvier)|dog
Hund (Rottweiler)|dog
Hund (Schäferhund)|dog
Hund (Dobermann)|dog
Hund (Zwergpinscher)|dog
Hund (Grosser Schweizer Sennenhund)|dog
Hund (Berner Sennenhund)|dog
Hund (Appenzeller)|dog
Hund (Entlebucher)|dog
Hund (Boxer)|dog
Hund (Bullmastiff)|dog
Hund (Tibet-Mastiff)|dog
Hund (Französische Bulldogge)|dog
Hund (Dogge)|dog
Hund (Bernhardiner)|dog
Hund (Eskimohund)|dog
Hund (Malamute)|dog
Hund (Husky)|dog
Hund (Dalmatiner)|dog
Hund (Affenpinscher)|dog
Hund (Basenji)|dog
Hund (Mops)|dog
Hund (Leonberger)|dog
Hund (Neufundländer)|dog
Hund (Pyrenäenberghund)|dog
Hund (Samojede)|dog
Hund (Zwergspitz)|dog
Hund (Chow-Chow)|dog
Hund (Keeshond)|dog
Hund (Brabanter Griffon)|dog
Hund (Corgi)|dog
Hund (Cardigan Corgi)|dog
Hund (Zwergpudel)|dog
Hund (Kleinpudel)|dog
Hund (Pudel)|dog
Hund (Nackthund)|dog
Wolf|animal
Polarwolf|animal
Rotwolf|animal
Kojote|animal
Dingo|animal
Rothund|animal
Wildhund|animal
Hyäne|animal
Rotfuchs|animal
Fuchs|animal
Polarfuchs|animal
Graufuchs|animal
Katze (getigert)|cat
Katze|cat
Perserkatze|cat
Siamkatze|cat
Katze|cat
Puma|animal
Luchs|animal
Leopard|animal
Schneeleopard|animal
Jaguar|animal
Löwe|animal
Tiger|animal
Gepard|animal
Braunbär|animal
Schwarzbär|animal
Eisbär|animal
Lippenbär|animal
Manguste|animal
Erdmännchen|animal
Sandlaufkäfer|animal
Marienkäfer|animal
Laufkäfer|animal
Bockkäfer|animal
Blattkäfer|animal
Mistkäfer|animal
Nashornkäfer|animal
Rüsselkäfer|animal
Fliege|animal
Biene|animal
Ameise|animal
Heuschrecke|animal
Grille|animal
Stabheuschrecke|animal
Kakerlake|animal
Gottesanbeterin|animal
Zikade|animal
Zikade|animal
Florfliege|animal
Libelle|animal
Kleinlibelle|animal
Admiral (Falter)|animal
Schmetterling|animal
Monarchfalter|animal
Kohlweissling|animal
Zitronenfalter|animal
Bläuling|animal
Seestern|animal
Seeigel|animal
Seegurke|animal
Kaninchen|animal
Hase|animal
Angorakaninchen|animal
Hamster|animal
Stachelschwein|animal
Eichhörnchen|animal
Murmeltier|animal
Biber|animal
Meerschweinchen|animal
Pferd|animal
Zebra|animal
Schwein|animal
Wildschwein|animal
Warzenschwein|animal
Nilpferd|animal
Ochse|animal
Wasserbüffel|animal
Bison|animal
Widder|animal
Dickhornschaf|animal
Steinbock|animal
Kuhantilope|animal
Impala|animal
Gazelle|animal
Dromedar|animal
Lama|animal
Wiesel|animal
Nerz|animal
Iltis|animal
Frettchen|animal
Otter|animal
Stinktier|animal
Dachs|animal
Gürteltier|animal
Faultier|animal
Orang-Utan|animal
Gorilla|animal
Schimpanse|animal
Gibbon|animal
Siamang|animal
Meerkatze|animal
Husarenaffe|animal
Pavian|animal
Makak|animal
Langur|animal
Stummelaffe|animal
Nasenaffe|animal
Marmosette|animal
Kapuziner|animal
Brüllaffe|animal
Springaffe|animal
Klammeraffe|animal
Totenkopfäffchen|animal
Katta|animal
Indri|animal
Elefant|animal
Elefant|animal
Roter Panda|animal
Panda|animal
Fisch|animal
Aal|animal
Lachs|animal
Kaiserfisch|animal
Clownfisch|animal
Stör|animal
Knochenhecht|animal
Feuerfisch|animal
Kugelfisch|animal
Abakus|office
Abaya|clothing
Talar|clothing
Akkordeon|instrument
Gitarre|instrument
Flugzeugträger|vehicle
Flugzeug|vehicle
Luftschiff|vehicle
Altar|building
Krankenwagen|vehicle
Amphibienfahrzeug|vehicle
Uhr|accessory
Bienenstock|nature
Schürze|clothing
Mülleimer|container
Sturmgewehr|weapon
Rucksack|bag
Bäckerei|building
Schwebebalken|sports
Ballon|toy
Kugelschreiber|office
Pflaster|medical
Banjo|instrument
Geländer|building
Langhantel|sports
Friseurstuhl|furniture
Friseursalon|building
Scheune|building
Barometer|appliance
Fass|container
Schubkarre|tool
Baseball|sports
Basketball|sports
Stubenwagen|baby
Fagott|instrument
Badekappe|sports
Badetuch|bathroom
Badewanne|bathroom
Kombi|vehicle
Leuchtturm|building
Becherglas|container
Bärenfellmütze|clothing
Bierflasche|drink
Bierglas|drink
Glockenturm|building
Lätzchen|baby
Tandem|vehicle
Bikini|clothing
Ordner|office
Fernglas|accessory
Vogelhaus|nature
Bootshaus|building
Bob|sports
Bolotie|accessory
Haube|clothing
Bücherregal|furniture
Buchhandlung|building
Kronkorken|misc
Bogen|weapon
Fliege (Krawatte)|clothing
Messingtafel|misc
BH|clothing
Wellenbrecher|building
Brustpanzer|misc
Besen|tool
Eimer|container
Schnalle|accessory
Schutzweste|clothing
Schnellzug|vehicle
Metzgerei|building
Taxi|vehicle
Kessel|kitchen
Kerze|light
Kanone|weapon
Kanu|vehicle
Dosenöffner|kitchen
Strickjacke|clothing
Autospiegel|vehicle
Karussell|misc
Werkzeugkasten|tool
Paket|container
Autorad|vehicle
Geldautomat|electronics
Kassette|audio
Kassettenspieler|audio
Burg|building
Katamaran|vehicle
CD-Player|audio
Cello|instrument
Handy|phone
Kette|tool
Maschendrahtzaun|building
Kettenhemd|misc
Kettensäge|tool
Truhe|furniture
Kommode|furniture
Windspiel|misc
Vitrine|furniture
Weihnachtsstrumpf|misc
Kirche|building
Kino|building
Hackbeil|kitchen
Felsenwohnung|building
Umhang|clothing
Holzschuh|footwear
Cocktailshaker|kitchen
Kaffeebecher|kitchen
Kaffeekanne|kitchen
Spule|misc
Zahlenschloss|accessory
Tastatur|computer
Süsswaren|food
Containerschiff|vehicle
Cabrio|vehicle
Korkenzieher|kitchen
Kornett|instrument
Cowboystiefel|footwear
Cowboyhut|clothing
Wiege|baby
Kran|vehicle
Helm|sports
Kiste|container
Kinderbett|baby
Schongarer|appliance
Krocketball|sports
Krücke|medical
Rüstung|misc
Staudamm|building
Schreibtisch|office
Computer|computer
Wählscheibentelefon|phone
Windel|baby
Digitaluhr|appliance
Digitaluhr|accessory
Esstisch|furniture
Spüllappen|kitchen
Geschirrspüler|appliance
Scheibenbremse|vehicle
Anlegestelle|building
Hundeschlitten|vehicle
Kuppel|building
Fussmatte|furniture
Bohrinsel|building
Trommel|instrument
Trommelstock|instrument
Hantel|sports
Bräter|kitchen
Ventilator|appliance
E-Gitarre|instrument
E-Lok|vehicle
Fernsehschrank|furniture
Briefumschlag|office
Espressomaschine|appliance
Puder|cosmetics
Federboa|clothing
Aktenschrank|office
Feuerlöschboot|vehicle
Feuerwehrauto|vehicle
Kaminschirm|furniture
Fahnenmast|misc
Flöte|instrument
Klappstuhl|furniture
Footballhelm|sports
Gabelstapler|vehicle
Brunnen|building
Füller|office
Himmelbett|furniture
Güterwagen|vehicle
Waldhorn|instrument
Bratpfanne|kitchen
Pelzmantel|clothing
Müllwagen|vehicle
Gasmaske|misc
Zapfsäule|traffic
Kelch|kitchen
Gokart|vehicle
Golfball|sports
Golfwagen|vehicle
Gondel|vehicle
Gong|instrument
Abendkleid|clothing
Flügel|instrument
Gewächshaus|building
Kühlergrill|vehicle
Supermarkt|building
Guillotine|misc
Haarspange|accessory
Haarspray|cosmetics
Halbkettenfahrzeug|vehicle
Hammer|tool
Wäschekorb|container
Föhn|appliance
Handheld|computer
Taschentuch|accessory
Festplatte|computer
Mundharmonika|instrument
Harfe|instrument
Mähdrescher|vehicle
Beil|tool
Holster|weapon
Heimkino|screen
Bienenwabe|food
Haken|tool
Reifrock|clothing
Reck|sports
Pferdewagen|vehicle
Sanduhr|accessory
iPod|audio
Bügeleisen|appliance
Kürbislaterne|light
Jeans|clothing
Jeep|vehicle
Trikot|clothing
Puzzle|toy
Rikscha|vehicle
Joystick|electronics
Kimono|clothing
Knieschützer|sports
Knoten|misc
Laborkittel|clothing
Schöpfkelle|kitchen
Lampenschirm|light
Laptop|computer
Rasenmäher|tool
Objektivdeckel|electronics
Brieföffner|office
Bibliothek|building
Rettungsboot|vehicle
Feuerzeug|misc
Limousine|vehicle
Kreuzfahrtschiff|vehicle
Lippenstift|cosmetics
Slipper|footwear
Lotion|cosmetics
Lautsprecher|audio
Lupe|accessory
Sägewerk|building
Kompass|accessory
Posttasche|bag
Briefkasten|building
Badeanzug|clothing
Badeanzug|clothing
Kanaldeckel|traffic
Maraca|instrument
Marimba|instrument
Maske|misc
Streichholz|misc
Maibaum|misc
Labyrinth|misc
Messbecher|kitchen
Medizinschrank|medical
Megalith|misc
Mikrofon|audio
Mikrowelle|appliance
Uniform|clothing
Milchkanne|container
Kleinbus|vehicle
Minirock|clothing
Van|vehicle
Rakete|weapon
Fäustling|clothing
Rührschüssel|kitchen
Wohnwagen|vehicle
Oldtimer|vehicle
Modem|electronics
Kloster|building
Monitor|screen
Moped|vehicle
Mörser|kitchen
Doktorhut|clothing
Moschee|building
Moskitonetz|furniture
Roller|vehicle
Mountainbike|vehicle
Zelt|sports
Computermaus|computer
Mausefalle|tool
Umzugswagen|vehicle
Maulkorb|misc
Nagel|tool
Halskrause|medical
Halskette|accessory
Schnuller|baby
Notebook|computer
Obelisk|building
Oboe|instrument
Okarina|instrument
Tacho|vehicle
Ölfilter|vehicle
Orgel|instrument
Oszilloskop|electronics
Überrock|clothing
Ochsenkarren|vehicle
Sauerstoffmaske|medical
Päckchen|container
Paddel|sports
Schaufelrad|vehicle
Vorhängeschloss|accessory
Pinsel|tool
Pyjama|clothing
Palast|building
Panflöte|instrument
Küchenpapier|kitchen
Fallschirm|sports
Barren|sports
Parkbank|furniture
Parkuhr|traffic
Waggon|vehicle
Terrasse|building
Telefonzelle|phone
Sockel|misc
Federmäppchen|office
Spitzer|office
Parfüm|cosmetics
Petrischale|medical
Kopierer|electronics
Plektrum|instrument
Pickelhaube|misc
Lattenzaun|building
Pickup|vehicle
Pier|building
Sparschwein|misc
Pillendose|medical
Kissen|furniture
Tischtennisball|sports
Windrad|toy
Piratenschiff|vehicle
Kanne|container
Hobel|tool
Planetarium|building
Plastiktüte|bag
Tellerregal|kitchen
Pflug|vehicle
Saugglocke|bathroom
Sofortbildkamera|electronics
Mast|misc
Polizeiwagen|vehicle
Poncho|clothing
Billardtisch|sports
Getränkeflasche|drink
Blumentopf|plant
Töpferscheibe|tool
Bohrmaschine|tool
Gebetsteppich|furniture
Drucker|electronics
Gefängnis|building
Geschoss|weapon
Beamer|electronics
Puck|sports
Boxsack|sports
Handtasche|bag
Federkiel|office
Steppdecke|furniture
Rennwagen|vehicle
Schläger|sports
Heizkörper|appliance
Radio|audio
Radioteleskop|building
Regentonne|container
Wohnmobil|vehicle
Rolle|tool
Spiegelreflexkamera|electronics
Kühlschrank|appliance
Fernbedienung|electronics
Restaurant|building
Revolver|weapon
Gewehr|weapon
Schaukelstuhl|furniture
Grill|appliance
Radiergummi|office
Rugbyball|sports
Lineal|office
Turnschuh|footwear
Tresor|furniture
Sicherheitsnadel|misc
Salzstreuer|kitchen
Sandale|footwear
Sarong|clothing
Saxofon|instrument
Scheide|weapon
Waage|appliance
Schulbus|vehicle
Segelschiff|vehicle
Anzeigetafel|sports
Bildschirm|screen
Schraube|tool
Schraubenzieher|tool
Sicherheitsgurt|vehicle
Nähmaschine|appliance
Schild (Wappen)|weapon
Schuhgeschäft|building
Schiebewand|building
Einkaufskorb|container
Einkaufswagen|container
Schaufel|tool
Duschhaube|bathroom
Duschvorhang|bathroom
Ski|sports
Skimaske|clothing
Schlafsack|sports
Rechenschieber|office
Schiebetür|building
Spielautomat|electronics
Schnorchel|sports
Schneemobil|vehicle
Schneepflug|vehicle
Seifenspender|bathroom
Fussball|sports
Socke|clothing
Solarspiegel|electronics
Sombrero|clothing
Suppenschüssel|kitchen
Leertaste|computer
Heizlüfter|appliance
Raumfähre|vehicle
Pfannenwender|kitchen
Motorboot|vehicle
Spinnennetz|nature
Spindel|tool
Sportwagen|vehicle
Scheinwerfer|light
Bühne|building
Dampflok|vehicle
Bogenbrücke|building
Steeldrum|instrument
Stethoskop|medical
Stola|clothing
Steinmauer|building
Stoppuhr|accessory
Herd|appliance
Sieb|kitchen
Strassenbahn|vehicle
Trage|medical
Couch|furniture
Stupa|building
U-Boot|vehicle
Anzug|clothing
Sonnenuhr|misc
Sonnenbrille|accessory
Sonnenbrille|accessory
Sonnencreme|cosmetics
Hängebrücke|building
Wattestäbchen|medical
Sweatshirt|clothing
Badehose|clothing
Schaukel|toy
Schalter|electronics
Spritze|medical
Tischlampe|light
Panzer|vehicle
Kassettenrekorder|audio
Teekanne|kitchen
Teddybär|toy
Fernseher|screen
Tennisball|sports
Strohdach|building
Theatervorhang|misc
Fingerhut|tool
Dreschmaschine|vehicle
Thron|furniture
Ziegeldach|building
Toaster|appliance
Tabakladen|building
Toilettensitz|bathroom
Fackel|light
Totempfahl|misc
Abschleppwagen|vehicle
Spielzeugladen|building
Traktor|vehicle
Sattelschlepper|vehicle
Tablett|kitchen
Trenchcoat|clothing
Dreirad|vehicle
Trimaran|vehicle
Stativ|electronics
Triumphbogen|building
Trolleybus|vehicle
Posaune|instrument
Wanne|bathroom
Drehkreuz|building
Schreibmaschine|office
Schirm|accessory
Einrad|vehicle
Klavier|instrument
Staubsauger|appliance
Vase|furniture
Gewölbe|building
Samt|misc
Automat|electronics
Messgewand|clothing
Viadukt|building
Geige|instrument
Volleyball|sports
Waffeleisen|appliance
Wanduhr|accessory
Geldbörse|accessory
Kleiderschrank|furniture
Kampfjet|vehicle
Waschbecken|bathroom
Waschmaschine|appliance
Wasserflasche|drink
Wasserkrug|container
Wasserturm|building
Krug|container
Trillerpfeife|misc
Perücke|cosmetics
Fliegengitter|building
Rollo|furniture
Krawatte|clothing
Weinflasche|drink
Tragfläche|vehicle
Wok|kitchen
Kochlöffel|kitchen
Wolle|misc
Holzzaun|building
Wrack|vehicle
Segelboot|vehicle
Jurte|building
Webseite|screen
Comic|office
Kreuzworträtsel|office
Strassenschild|traffic
Ampel|traffic
Buchumschlag|office
Speisekarte|office
Teller|kitchen
Guacamole|food
Brühe|food
Eintopf|food
Trifle|food
Eis|food
Eis am Stiel|food
Baguette|food
Bagel|food
Brezel|food
Cheeseburger|food
Hotdog|food
Kartoffelpüree|food
Kohlkopf|food
Brokkoli|food
Blumenkohl|food
Zucchini|food
Spaghettikürbis|food
Eichelkürbis|food
Butternusskürbis|food
Gurke|food
Artischocke|food
Paprika|food
Kardone|food
Pilz|food
Apfel|food
Erdbeere|food
Orange|food
Zitrone|food
Feige|food
Ananas|food
Banane|food
Jackfrucht|food
Zimtapfel|food
Granatapfel|food
Heu|nature
Carbonara|food
Schokoladensauce|food
Teig|food
Hackbraten|food
Pizza|food
Pastete|food
Burrito|food
Rotwein|drink
Espresso|drink
Tasse|kitchen
Eierlikör|drink
Alpen|nature
Seifenblase|misc
Klippe|nature
Korallenriff|nature
Geysir|nature
Seeufer|nature
Landzunge|nature
Sandbank|nature
Strand|nature
Tal|nature
Vulkan|nature
Baseballspieler|person
Bräutigam|person
Taucher|person
Raps|plant
Gänseblümchen|plant
Frauenschuh|plant
Mais|food
Eichel|plant
Hagebutte|plant
Rosskastanie|plant
Korallenpilz|plant
Fliegenpilz|plant
Lorchel|plant
Stinkmorchel|plant
Erdstern|plant
Klapperschwamm|plant
Steinpilz|plant
Maiskolben|food
Toilettenpapier|bathroom`;

/** Reihenfolge = Ausgabereihenfolge des Modells. */
export const IMAGENET = TABLE.split('\n').map((line, index) => {
  const [de, group] = line.split('|');
  return { index, de, group: group ?? 'misc' };
});

if (IMAGENET.length !== 1000) {
  // Ein Fehler in der Tabelle würde sonst jede Zuordnung um eine Zeile verschieben.
  console.error(`labels-imagenet.js: ${IMAGENET.length} statt 1000 Einträge`);
}

/**
 * Landschaften, Gebäude und Personen taugen nicht als feinerer Name für
 * einen Gegenstand – ein Karton wird nie „Vulkan“.
 */
const NOT_AN_OBJECT = new Set(['nature', 'building', 'person']);

/**
 * Gruppen, die einander verfeinern dürfen. Ein „Karton“ (container) darf
 * zum „Paket“ (container) werden, ein „Schuh“ (footwear) nie zur
 * „Bärenfellmütze“ (clothing) – auch wenn die Zweitstufe sich sicher ist:
 * Sie sieht nur den Ausschnitt und rät bei fremden Dingen gern daneben.
 */
const FAMILIES = [
  ['container', 'kitchen', 'drink', 'food', 'bag', 'office'],
  [
    'appliance',
    'electronics',
    'kitchen',
    'screen',
    'computer',
    'phone',
    'audio',
    'light',
    'office',
  ],
  ['furniture', 'office', 'bathroom', 'light', 'baby'],
  ['toy', 'sports', 'instrument', 'baby'],
  ['vehicle', 'traffic'],
  ['clothing', 'accessory', 'cosmetics', 'bag'],
  ['footwear'],
  ['tool', 'weapon', 'office', 'sports'],
  ['plant', 'food'],
  ['animal', 'dog', 'cat', 'bird'],
  ['medical', 'bathroom', 'cosmetics'],
  ['misc'],
];

/** Dürfen zwei Gruppen einander verfeinern? */
export function groupsCompatible(a, b) {
  if (a === b) return true;
  return FAMILIES.some((family) => family.includes(a) && family.includes(b));
}

/**
 * Darf dieses Ergebnis der Zweitstufe den Detektornamen ersetzen?
 * @param {number} index  ImageNet-Index
 * @param {string} detectorGroup  Gruppe der Detektorklasse
 */
export function refinementAllowed(index, detectorGroup) {
  const entry = IMAGENET[index];
  if (!entry || NOT_AN_OBJECT.has(entry.group)) return false;
  // Tiergruppen dürfen einander verfeinern (Tier → Katze), sonst nicht wechseln.
  const animalGroups = new Set(['animal', 'dog', 'cat', 'bird']);
  if (animalGroups.has(detectorGroup)) return animalGroups.has(entry.group);
  if (animalGroups.has(entry.group)) return false;
  return groupsCompatible(entry.group, detectorGroup);
}
