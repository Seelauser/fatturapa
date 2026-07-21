# alpsplanner/fatturapa

🇮🇹 [Italiano](README.md) · 🇬🇧 [English](README.en.md) · 🇩🇪 Deutsch

Eine **framework-freie** PHP-Bibliothek zum Erstellen des XML der italienischen
**FatturaPA** (Sistema di Interscambio / SdI), zum Reservieren fortlaufender
Rechnungsnummern und für den gesamten aktiven und passiven Rechnungszyklus — mit
optionalem HTTP-Microservice. Aus [AlpsPlanner](https://alpsplanner.com) extrahiert
und in jedem PHP-Projekt einsetzbar.

- **Keine Framework-Abhängigkeit** — der Kern (`XmlBuilder`, `NumeratoreService`)
  braucht nur `ext-dom`. Einsetzbar in CiviCRM, Laravel, Symfony oder purem PHP.
- **Bewusst autark** — der **PEC-Transport** sendet an den SdI ausschließlich über das
  eigene PEC-Postfach (keine Drittanbieter-Dienste); der `NotificationParser` liest die
  SdI-Quittungen (RC/NS/MC/NE/DT/AT) offline; die Validierung läuft lokal gegen das
  offizielle XSD 1.2.3. Für die Langzeitarchivierung (conservazione) kann der
  kostenlose Dienst der Agenzia delle Entrate genutzt werden.
- **Compliance eingebaut** — TD01–TD29, granulare Natura-Untercodes, automatische
  2-€-Stempelsteuer (imposta di bollo) auf steuerfreie Beträge über 77,47 €,
  Split Payment (`EsigibilitaIVA=S`), CIG/CUP für die öffentliche Verwaltung,
  `DatiPagamento`, Quellensteuer (ritenuta d'acconto), Vorsorgekasse (cassa
  previdenziale), Zeilenrabatte, geeignet für Forfettari (natürliche Person als
  Aussteller, RF19, N2.2).
- **Nebenläufigkeitssichere Nummerierung** — atomarer Zähler pro Jahr/Sektional auf
  MariaDB/MySQL, PostgreSQL und SQLite ≥ 3.35.
- **Austauschbarer SdI-Transport** — `SdiTransport`-Interface; PEC- und
  Openapi.com-Adapter enthalten.
- **Passiver Zyklus** — Eingangsrechnungen werden aus dem PEC-Postfach eingesammelt
  (`.xml` und signierte `.xml.p7m`), aus dem p7m extrahiert und in ein
  buchhaltungsfertiges Array geparst.
- **Optionaler Microservice** — eine kleine Slim-App stellt `build` / `numero` /
  `send` / `status` / `notifica` / `inbox` / `render` über HTTP bereit, geschützt
  durch einen `X-Api-Key`-Header.

> ⚠️ Der Versand an den SdI ist ein **unumkehrbarer steuerlicher Vorgang**. Diese
> Bibliothek versendet nie automatisch — der Versand wird immer von einem Menschen
> ausgelöst. Die steuerliche Behandlung stets mit dem Steuerberater abklären.

## Installation

```bash
composer require alpsplanner/fatturapa
```

Benötigt PHP 8.2+, `ext-dom`, `ext-libxml`.

## XML erstellen

```php
use AlpsFatturapa\XmlBuilder;

$xml = (new XmlBuilder())->build([
    'tipo_documento' => 'TD01',
    'numero'         => '2026/00042',
    'data'           => '2026-07-01',
    'cedente' => [
        'denominazione' => 'Musterverein Südtirol',
        'partita_iva'   => '01234567890',
        'regime_fiscale'=> 'RF01',
        'indirizzo' => 'Hauptplatz 1', 'cap' => '39100', 'comune' => 'Bozen',
        'provincia' => 'BZ', 'nazione' => 'IT',
    ],
    'cessionario' => [
        'nome' => 'Anna', 'cognome' => 'Gruber', 'codice_fiscale' => 'GRBNNA80A01A952G',
        'indirizzo' => 'Dorfweg 5', 'cap' => '39100', 'comune' => 'Bozen',
        'provincia' => 'BZ', 'nazione' => 'IT',
    ],
    'linee' => [
        ['descrizione' => 'Mitgliedsbeitrag 2026', 'quantita' => 1, 'prezzo_unitario' => 50.0, 'aliquota_iva' => 0.0, 'natura' => 'N4'],
    ],
]);
```

Weitere Eingabeoptionen (vollständige Struktur im Docblock von `XmlBuilder`):

```php
'tipo_documento' => 'TD04',                     // beliebiges TD01…TD29 (TD04 = Gutschrift)
'formato'        => 'FPA12',                    // öffentliche Verwaltung
'esigibilita_iva'=> 'S',                        // Split Payment (PA)
'ordine_acquisto'=> ['cig' => 'Z123456789', 'cup' => '...'],
'bollo'          => true,                       // weglassen für die automatische 2-€-Regel (> 77,47 € steuerfrei)
'causale'        => 'Riferimento pratica ...',
'cedente'        => ['nome' => 'Max', 'cognome' => 'Muster', 'regime_fiscale' => 'RF19', ...], // Freiberufler/Forfettario
'pagamento'      => ['condizioni' => 'TP02', 'dettagli' => [['modalita' => 'MP05', 'iban' => 'IT60...', 'scadenza' => '2026-08-01']]],
'ritenuta'       => ['tipo' => 'RT01', 'aliquota' => 20.0, 'causale' => 'A'],   // + 'ritenuta' => true auf den Zeilen
'cassa'          => ['tipo' => 'TC22', 'aliquota' => 4.0, 'aliquota_iva' => 22.0], // Vorsorgekasse (fließt in die Summen ein)
// pro Zeile: 'sconto_percentuale' => 10.0 oder 'sconto_importo' => 5.0
```

Die Validierung ist zweistufig: `build()` prüft Felder und semantische SdI-Regeln
(Natura ⇔ Steuersatz 0, granulare Untercodes, Länge des Empfängercodes, …) und wirft
eine Exception mit **allen** Fehlern. Optionale XSD-Validierung (offizielles Schema
`FatturaPA_v1.2.3.xsd` nach `resources/xsd/` legen; 1.2.2 dient als Fallback):

```php
$errors = (new XmlBuilder())->validate($xml); // [] wenn gültig
```

## Rechnungsnummer reservieren

```php
use AlpsFatturapa\NumeratoreService;

$svc = new NumeratoreService($pdo);       // MariaDB/MySQL, PostgreSQL oder SQLite ≥3.35; Tabelle konfigurierbar
$svc->ensureTable();                       // legt `sdi_sequence` an, falls nicht vorhanden
$numero = $svc->next();                    // "2026/00001", "2026/00002", …
$numero = $svc->next(2026, 'EXT');         // "2026/00001/EXT" (eigenes Sektional)
```

## An den SdI senden (manuell)

### Über das eigene PEC-Postfach — ohne Drittanbieter

```php
use AlpsFatturapa\Transport\PecTransport;

$pec = new PecTransport(
    pecAddress:  'firma@pec.example.it',
    cedentePiva: '01234567890',
    smtpHost:    'smtps.pec.aruba.it',   // SMTP des eigenen PEC-Anbieters
    smtpUsername:'firma@pec.example.it',
    smtpPassword:'***',
    // Der allererste Versand geht an sdi01@pec.fatturapa.it; der SdI antwortet mit
    // einer dedizierten Adresse — diese danach als $sdiAddress übergeben.
);
$result = $pec->sendInvoice($xml, ['progressivo' => '00042']);
// ['identificativo' => 'IT01234567890_00042.xml', ...]
```

Die SdI-Quittungen landen im PEC-Postfach. Automatisch abrufen (eigener
IMAP-Client, versteht die PEC-Umhüllung `postacert.eml`):

```php
use AlpsFatturapa\Notifications\PecInboxReader;

foreach (PecInboxReader::createFromEnv()->fetchNotifications() as $f) {
    // $f['filename'], $f['notification'] (SdiNotification)
}
```

`fetchAll()` liefert zusätzlich eingehende **Eingangsrechnungen** (passiver Zyklus,
`.xml` und signierte `.xml.p7m`), bereits als buchhaltungsfertiges Array geparst —
siehe `Passive\ReceivedInvoiceParser` und `Passive\P7mExtractor`. Der Status der
Ausgangsrechnungen wird mit `Lifecycle\InvoiceStore` verfolgt (erstellt → gesendet →
zugestellt/abgelehnt/…, `applyNotification()` schließt den Kreis automatisch).

…oder eine bereits vorliegende Quittungs-XML interpretieren:

```php
use AlpsFatturapa\Notifications\NotificationParser;

$n = (new NotificationParser())->parse($attachmentXml);
$n->tipo;          // 'RC' | 'NS' | 'MC' | 'NE' | 'DT' | 'AT'
$n->isRejection(); // true bei Ablehnung → korrigieren und binnen 5 Tagen neu senden (gleiche Nummer erlaubt)
$n->errori;        // [['codice' => '00404', 'descrizione' => ...], ...]
```

Hinweise zum PEC-Kanal: Nachricht ≤ 30 MB, Rechnung ≤ 5 MB, asynchron (keine
sofortige Rückmeldung). Eine Signatur ist für B2B/B2C **nicht** erforderlich;
FPA12-Rechnungen (öffentliche Verwaltung) müssen signiert werden — dafür braucht es
unabhängig vom Kanal ein qualifiziertes Zertifikat. Für die kostenlose zehnjährige
Archivierung den Dienst der Agenzia delle Entrate in „Fatture e Corrispettivi"
aktivieren.

### Über Openapi.com (optionaler Intermediär)

```php
use AlpsFatturapa\Transport\OpenapiClient;

$client = OpenapiClient::createFromEnv(testMode: true); // liest OPENAPI_TOKEN
$result = $client->sendInvoice($xml, ['numero' => '2026/00042']);
// ['identificativo' => '<uuid>', 'raw' => [...]]
```

Für andere Anbieter `AlpsFatturapa\Contracts\SdiTransport` implementieren.

## Lesbare Darstellung (offizielles Stylesheet)

```php
$html = (new AlpsFatturapa\Render\StylesheetRenderer())->renderHtml($xml);
```

Benötigt `php-xsl` und das offizielle AdE-Stylesheet in `resources/xsl/` (aus
Lizenzgründen nicht mitgeliefert, wie das XSD).

## HTTP-Microservice (optional)

Mit den Zusatzabhängigkeiten (Slim, Guzzle) installieren und `public/` ausliefern:

```bash
composer install
php -S 0.0.0.0:8080 -t public
```

Alle Routen außer `/health` verlangen den `X-Api-Key`-Header passend zur
Umgebungsvariable `API_KEY`; ohne konfigurierten `API_KEY` verweigert der Dienst
alle Anfragen.

| Methode & Pfad       | Body                          | Antwort |
|----------------------|-------------------------------|---------|
| `GET  /health`       | —                             | `{status:"ok"}` |
| `POST /fattura/build`| `{ invoice: {…} }`            | `{ xml, valid, errors }` |
| `POST /fattura/numero`| `{ year?, sezionale? }`      | `{ numero }` (braucht DB-Env) |
| `POST /fattura/send` | `{ xml, meta? }`              | `{ identificativo, raw }` |
| `GET  /fattura/status/{id}` | —                      | `{ status, raw }` |
| `POST /fattura/notifica` | `{ xml }`                 | interpretierte SdI-Quittung |
| `GET  /fattura/inbox` | —                            | neue SdI-Quittungen **und Eingangsrechnungen** aus dem PEC-Postfach (IMAP) |
| `POST /fattura/render` | `{ xml }`                   | lesbares HTML (offizielles Stylesheet; braucht php-xsl + XSL in `resources/xsl/`) |

Umgebungsvariablen: `API_KEY` (Pflicht), `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`, `DB_PORT`, `SDI_SEQUENCE_TABLE`; Transportwahl `SDI_TRANSPORT`
(`pec` | `openapi`, Standard `openapi`). Für `openapi`: `OPENAPI_TOKEN`, `SDI_MODE`
(`production` deaktiviert die Sandbox). Für `pec`: `PEC_ADDRESS`, `PEC_SMTP_HOST`,
`PEC_SMTP_USERNAME`, `PEC_SMTP_PASSWORD`, `CEDENTE_PIVA`, optional `PEC_SDI_ADDRESS`
(nach Zuweisung durch den SdI) und `PEC_SMTP_PORT`. Postfach-Abruf: `PEC_IMAP_HOST`,
optional `PEC_IMAP_PORT` (993), `PEC_IMAP_USERNAME`/`PEC_IMAP_PASSWORD`
(Standard: SMTP-Zugangsdaten).

## Dokumentation

- [docs/CAPABILITIES.md](docs/CAPABILITIES.de.md) — was heute funktioniert, verifizierte Plug-and-play-Oberfläche
- [docs/GAPS.md](docs/GAPS.de.md) — bekannte Lücken und ihr Status (nach Schweregrad)
- [docs/ROADMAP.md](docs/ROADMAP.de.md) — Marktpositionierung, Adapter-Prioritäten, Release-Plan

Alle Dokumente in docs/ sind auf Italienisch (Hauptsprache), Englisch und Deutsch verfügbar; das CHANGELOG wird auf Englisch gepflegt.

## Tests

```bash
composer install && composer test
```

## Lizenz

MIT — siehe [LICENSE](LICENSE).
