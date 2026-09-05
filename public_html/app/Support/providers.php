<?php

/**
 * Wo findet man bei welchem Anbieter was?
 *
 * Diese Angaben stehen im Formular als ausklappbare Hilfe und im
 * Domain-Assistenten. Sie sind bewusst als Daten abgelegt und nicht im
 * Text verstreut – so lässt sich ein Anbieter ergänzen, ohne Code zu
 * ändern.
 *
 * Hinweis: Anbieter bauen ihre Oberflächen gelegentlich um. Die Angaben
 * beschreiben deshalb den Weg ("Verwaltung → Domains → ..."), nicht die
 * genaue Beschriftung eines Knopfes.
 */

declare(strict_types=1);

return [

    // ------------------------------------------------------------------
    // Hosting: wo bekomme ich die FTP-Zugangsdaten?
    // ------------------------------------------------------------------

    'hosting' => [
        'cpanel' => [
            'name' => 'cPanel (viele Anbieter)',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/public_html',
            'steps' => [
                'In cPanel anmelden.',
                'Im Bereich „Dateien" auf <strong>FTP-Konten</strong> klicken.',
                'Entweder ein bestehendes Konto verwenden oder ein neues anlegen.',
                'Beim gewünschten Konto auf <strong>Konfigurieren Sie den FTP-Client</strong> klicken – dort stehen Server, Benutzername und Port.',
                'Das Passwort ist das, das beim Anlegen vergeben wurde. Es lässt sich dort auch neu setzen.',
            ],
            'note' => 'Der Server ist die Domain oder der Servername aus cPanel – <strong>nie</strong> '
                . '<code>ftp.</code> davor, diesen Eintrag legt cPanel nicht an. '
                . 'Beim Verzeichnis kommt es auf den Zugang an: Ein Unterkonto (Benutzername mit '
                . '<code>@</code>) sitzt bereits in seinem Ordner, dort ist es <code>/</code>. '
                . 'Beim Hauptkonto ist es <code>/public_html</code>, bei einer Subdomain '
                . '<code>/public_html/subdomain.deine-domain.ch</code>.',
        ],

        'godaddy' => [
            'name' => 'GoDaddy (cPanel)',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/public_html',
            'steps' => [
                'Bei GoDaddy anmelden und zu <strong>Meine Produkte</strong> gehen.',
                'Beim Webhosting-Paket auf <strong>Verwalten</strong> klicken.',
                'Über <strong>cPanel-Admin</strong> das cPanel öffnen.',
                'Unter „Dateien" auf <strong>FTP-Konten</strong>. Ein bestehendes Konto nehmen oder ein neues anlegen.',
                'Als <strong>Server</strong> die Domain selbst eintragen, also <code>deine-domain.ch</code> – <strong>nicht</strong> <code>ftp.deine-domain.ch</code>. Diesen Namen gibt es bei cPanel nicht. Alternativ der Servername, der in cPanel rechts unter „Allgemeine Informationen" steht.',
                'Als <strong>Benutzername</strong> genau das, was cPanel anzeigt – die volle Form mit <code>@</code>, also <code>web@deine-domain.ch</code>.',
                'Port <strong>21</strong>, Übertragungsart <strong>FTP</strong>. SFTP ist bei GoDaddy im Standardpaket meist nicht freigeschaltet.',
            ],
            'note' => '<strong>Das Verzeichnis ist die häufigste Stolperstelle.</strong> '
                . 'Ein FTP-Unterkonto (Benutzername mit <code>@</code>) wird beim Anlegen auf sein '
                . 'Verzeichnis festgenagelt: Nach der Anmeldung ist man bereits darin, und der Pfad, '
                . 'der in cPanel stand, existiert von dort aus nicht mehr. Für ein solches Konto ist '
                . 'das Verzeichnis <code>/</code>. '
                . 'Nur beim <em>Haupt</em>-cPanel-Konto (Benutzername ohne <code>@</code>) ist es der '
                . 'volle Pfad, also <code>/public_html</code> bzw. bei einer Subdomain '
                . '<code>/public_html/subdomain.deine-domain.ch</code>. '
                . 'Im Zweifel einmal „Verbindung testen" – der Test sieht nach der Anmeldung nach '
                . 'und schlägt den passenden Ordner vor.',
        ],

        'plesk' => [
            'name' => 'Plesk',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/httpdocs',
            'steps' => [
                'In Plesk anmelden.',
                'Links auf <strong>Websites &amp; Domains</strong>.',
                'Bei der Domain auf <strong>FTP-Zugang</strong> klicken.',
                'Dort steht der Benutzername; das Passwort kann neu gesetzt werden.',
                'Der Server ist meist die Domain selbst oder die Serveradresse aus der Willkommens-E-Mail.',
            ],
            'note' => 'Bei Plesk heisst das Zielverzeichnis <code>/httpdocs</code>, nicht public_html.',
        ],

        'hostpoint' => [
            'name' => 'Hostpoint (Schweiz)',
            'protocol' => 'sftp',
            'port' => 22,
            'path' => '/www',
            'steps' => [
                'Im Hostpoint Control Panel anmelden.',
                'Auf <strong>Hosting</strong> und dann die betreffende Domain wählen.',
                'Unter <strong>FTP/SFTP</strong> die Zugänge verwalten.',
                'Neuen Zugang anlegen oder Passwort eines bestehenden zurücksetzen.',
            ],
            'note' => 'Hostpoint empfiehlt SFTP über Port 22 – das ist verschlüsselt und die bessere Wahl.',
        ],

        'infomaniak' => [
            'name' => 'Infomaniak (Schweiz)',
            'protocol' => 'sftp',
            'port' => 22,
            'path' => '/web',
            'steps' => [
                'Im Infomaniak Manager anmelden.',
                'Zu <strong>Hosting</strong> und dann zur Website wechseln.',
                'Unter <strong>FTP/SSH</strong> einen Zugang anlegen oder ansehen.',
                'Server ist in der Regel die Domain oder die angezeigte Serveradresse.',
            ],
            'note' => 'Infomaniak bietet SFTP; das Web-Verzeichnis heisst meist <code>/web</code>.',
        ],

        'ionos' => [
            'name' => 'IONOS / 1&1',
            'protocol' => 'sftp',
            'port' => 22,
            'path' => '/',
            'steps' => [
                'Im IONOS-Konto anmelden.',
                'Auf <strong>Hosting</strong> und dort auf <strong>SFTP &amp; SSH</strong>.',
                'Zugang auswählen oder neu anlegen; Server und Benutzername werden angezeigt.',
                'Das Passwort wird dort gesetzt.',
            ],
            'note' => 'IONOS nutzt SFTP über Port 22. Das Zielverzeichnis ist häufig direkt <code>/</code>.',
        ],

        'strato' => [
            'name' => 'Strato',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/',
            'steps' => [
                'Im Strato-Kundenlogin anmelden.',
                'Zum Paket wechseln und <strong>FTP-Verwaltung</strong> öffnen.',
                'Hauptzugang verwenden oder einen Unterzugang anlegen.',
                'Der Server heisst meist <code>ftp.deine-domain.ch</code>.',
            ],
            'note' => 'Bei Strato liegt die Website meist direkt im Hauptverzeichnis.',
        ],

        'hostinger' => [
            'name' => 'Hostinger',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/public_html',
            'steps' => [
                'Im hPanel anmelden.',
                'Zu <strong>Dateien → FTP-Konten</strong> gehen.',
                'Dort stehen Server, Benutzername und Port; das Passwort kann geändert werden.',
            ],
            'note' => 'Zielverzeichnis ist <code>/public_html</code>.',
        ],

        'other' => [
            'name' => 'Anderer Anbieter',
            'protocol' => 'ftp',
            'port' => 21,
            'path' => '/public_html',
            'steps' => [
                'Im Kundenbereich des Anbieters nach <strong>FTP</strong>, <strong>SFTP</strong> oder <strong>Dateizugriff</strong> suchen.',
                'Häufig steht alles in der Willkommens-E-Mail nach der Bestellung.',
                'Gebraucht werden vier Angaben: Server, Benutzername, Passwort und Verzeichnis.',
                'Notfalls beim Support danach fragen – das ist eine Standardauskunft.',
            ],
            'note' => 'Das Zielverzeichnis heisst je nach Anbieter <code>public_html</code>, <code>httpdocs</code>, <code>www</code> oder <code>web</code>.',
        ],
    ],

    // ------------------------------------------------------------------
    // Registrare: wo bekomme ich den Auth-Code, wo hebe ich die Sperre auf,
    // wo stelle ich die Nameserver oder den A-Eintrag um?
    // ------------------------------------------------------------------

    'registrar' => [
        'godaddy' => [
            'name' => 'GoDaddy',
            'authcode' => 'Meine Produkte → bei der Domain auf <strong>Domain-Einstellungen</strong> → ganz unten <strong>Autorisierungscode abrufen</strong>. Der Code kommt per E-Mail.',
            'lock' => 'Meine Produkte → Domain-Einstellungen → <strong>Domänensperre</strong> ausschalten.',
            'dns' => 'Meine Produkte → bei der Domain auf <strong>DNS</strong> → Eintrag vom Typ <strong>A</strong> mit Name <code>@</code> bearbeiten.',
            'nameserver' => 'Meine Produkte → Domain-Einstellungen → <strong>Nameserver</strong> → „Eigene Nameserver verwenden".',
        ],
        'hostpoint' => [
            'name' => 'Hostpoint',
            'authcode' => 'Control Panel → <strong>Domains</strong> → Domain wählen → <strong>Transfer / Auth-Code</strong>.',
            'lock' => 'Control Panel → Domains → Domain wählen → <strong>Transfer-Sperre</strong> aufheben.',
            'dns' => 'Control Panel → Domains → Domain wählen → <strong>DNS-Einträge</strong> → A-Eintrag für <code>@</code>.',
            'nameserver' => 'Control Panel → Domains → Domain wählen → <strong>Nameserver</strong>.',
        ],
        'infomaniak' => [
            'name' => 'Infomaniak',
            'authcode' => 'Manager → <strong>Domains</strong> → Domain wählen → <strong>Transfer</strong> → Auth-Code anzeigen.',
            'lock' => 'Manager → Domains → Domain wählen → <strong>Sicherheit</strong> → Transfersperre deaktivieren.',
            'dns' => 'Manager → Domains → Domain wählen → <strong>DNS-Zone</strong> → A-Eintrag bearbeiten.',
            'nameserver' => 'Manager → Domains → Domain wählen → <strong>Nameserver</strong>.',
        ],
        'switch' => [
            'name' => 'SWITCH (.ch direkt)',
            'authcode' => 'Bei .ch-Domains vergibt der jeweilige Registrar den Auth-Code, nicht SWITCH direkt. Beim Anbieter anfragen, über den die Domain läuft.',
            'lock' => 'Ebenfalls beim Registrar; .ch-Domains haben oft keine zusätzliche Sperre.',
            'dns' => 'Über den Registrar, bei dem die Domain verwaltet wird.',
            'nameserver' => 'Über den Registrar, bei dem die Domain verwaltet wird.',
        ],
        'ionos' => [
            'name' => 'IONOS / 1&1',
            'authcode' => 'Kundenkonto → <strong>Domains &amp; SSL</strong> → Domain wählen → <strong>Auth-Code anfordern</strong>.',
            'lock' => 'Domains &amp; SSL → Domain wählen → <strong>Transfer-Schutz</strong> aufheben.',
            'dns' => 'Domains &amp; SSL → Domain wählen → <strong>DNS</strong> → A-Eintrag bearbeiten.',
            'nameserver' => 'Domains &amp; SSL → Domain wählen → <strong>Nameserver</strong> anpassen.',
        ],
        'strato' => [
            'name' => 'Strato',
            'authcode' => 'Kundenlogin → <strong>Domainverwaltung</strong> → Domain wählen → <strong>Auth-Code anfordern</strong>.',
            'lock' => 'Domainverwaltung → Domain wählen → Transfersperre deaktivieren.',
            'dns' => 'Domainverwaltung → Domain wählen → <strong>DNS-Einstellungen</strong>.',
            'nameserver' => 'Domainverwaltung → Domain wählen → <strong>Nameserver</strong>.',
        ],
        'namecheap' => [
            'name' => 'Namecheap',
            'authcode' => 'Domain List → <strong>Manage</strong> → Sharing &amp; Transfer → <strong>Auth Code</strong>.',
            'lock' => 'Domain List → Manage → <strong>Registrar Lock</strong> ausschalten.',
            'dns' => 'Domain List → Manage → <strong>Advanced DNS</strong> → A-Record.',
            'nameserver' => 'Domain List → Manage → <strong>Nameservers</strong>.',
        ],
        'other' => [
            'name' => 'Anderer Registrar',
            'authcode' => 'Im Kundenbereich nach <strong>Auth-Code</strong>, <strong>EPP-Code</strong> oder <strong>Transfer</strong> suchen. Meist bei der Domain selbst.',
            'lock' => 'Nach <strong>Transfer-Sperre</strong>, <strong>Domain Lock</strong> oder <strong>Transfer-Schutz</strong> suchen und ausschalten.',
            'dns' => 'Nach <strong>DNS</strong>, <strong>DNS-Zone</strong> oder <strong>Namenseinträge</strong> suchen.',
            'nameserver' => 'Nach <strong>Nameserver</strong> suchen; dort lassen sich eigene eintragen.',
        ],
    ],
];
