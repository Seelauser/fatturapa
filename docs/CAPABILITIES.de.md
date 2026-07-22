# Fähigkeiten — was heute funktioniert (Plug and Play)

🇮🇹 [Italiano](CAPABILITIES.md) · 🇬🇧 [English](CAPABILITIES.en.md) · 🇩🇪 Deutsch

> Status-Audit von `seelauser/fatturapa`, verifiziert durch Ausführen des Codes auf PHP 8.3
> (Tests, XSD-Validierung gegen das offizielle Schema und ein Live-Concurrency-Test
> gegen MariaDB). Aktualisiert für **0.2.0** (siehe [CHANGELOG](../CHANGELOG.md)):
> XSD 1.2.3, TD01–TD29, bollo (Stempelsteuer), Split Payment, CIG/CUP, DatiPagamento,
> cedente als natürliche Person, Validierung auf Feldebene, **PEC-Transport**
> (autark, kein Drittanbieterdienst), Parser für SdI-Benachrichtigungen,
> API-Key-geschützter Microservice.
> Begleitdokumente: [GAPS.de.md](GAPS.de.md) für das, was fehlt,
> [ROADMAP.de.md](ROADMAP.de.md) für die Weiterentwicklung des Pakets.

## Verifiziert funktionsfähig

### 1. Erzeugung von FatturaPA-XML (`XmlBuilder`)

`build()` erzeugt XML, das **gegen das offizielle
`Schema_del_file_xml_FatturaPA_v1.2.2.xsd`** von fatturapa.gov.it validiert — für alle drei
unterstützten Archetypen:

| Fall | Formato | Verifiziert |
|---|---|---|
| B2C, Privatperson mit codice fiscale (Steuernummer), steuerbefreite Position (Natura N4) | FPR12 | ✅ XSD-valide |
| B2B, Unternehmen mit partita IVA (USt-IdNr.) + codice destinatario (Empfängercode), 22 % USt | FPR12 | ✅ XSD-valide |
| PA, öffentliche Einrichtung mit 6-stelligem codice destinatario | FPA12 | ✅ XSD-valide |

Ebenfalls verifiziert:

- **Escaping** — `&`, `<`, `>` in Beschreibungen/Namen überstehen den Round-Trip (`htmlspecialchars(ENT_XML1)` vor `createElement`).
- **USt-Berechnung** — Aggregation der `DatiRiepilogo` je (aliquota, natura), `Imposta` und `ImportoTotaleDocumento` werden korrekt berechnet (durch Unit-Tests abgedeckt, 7/7 grün).
- **CodiceDestinatario-Defaults** — `0000000` für FPR12, `999999` für FPA12; `PECDestinatario` wird nur im rechtlich vorgesehenen Fall ausgegeben (FPR12 + `0000000` + PEC vorhanden).
- **Negative Positionen** (Rabatt/Erstattung als negativer Preis) bestehen die XSD-Validierung.
- Eingabe ist ein einfaches PHP-Array — keine Framework-Typen, nur `ext-dom` erforderlich. Echtes Drop-in für CiviCRM, Laravel, Symfony oder pures PHP.

Unterstützte Eingabeoberfläche (siehe Klassen-Docblock): `tipo_documento` (`fattura_b2b` | `fattura_b2c` | `fattura_pa`), `numero`, `data`, `progressivo_invio` (automatisch zufällig, falls nicht angegeben), cedente (nur Unternehmen), cessionario (Unternehmen oder Person mit nome/cognome), Positionen mit `descrizione`/`quantita`/`prezzo_unitario`/`aliquota_iva`/`natura`, `riferimento_normativo`-Fallback je Position auf steuerbefreiten riepiloghi.

### 2. XSD-Validierung (`XmlBuilder::validate()`)

Funktioniert, sobald das offizielle XSD unter `resources/xsd/FatturaPA_v1.2.2.xsd`
abgelegt ist (bewusst nicht mitgeliefert — siehe `resources/xsd/README.md`). Liefert ein
sauberes `string[]` mit libxml-Fehlern; ein leeres Array = valide. Ohne die Datei degradiert
die Methode zu einer einzelnen informativen Meldung, statt fehlzuschlagen.

### 3. Fortlaufende Nummerierung (`NumeratoreService`)

- Format `YYYY/00042` und `YYYY/00042/SEZ` je Jahr + sezionale (Nummernkreis).
- **Nebenläufigkeit live verifiziert**: 20 parallele Prozesse × 10 Inkremente gegen
  MariaDB ergaben exakt 200 — ohne Lücken oder Duplikate (der
  `INSERT IGNORE`-Seed + `UPDATE … LAST_INSERT_ID()`-Upsert ist race-frei).
- `ensureTable()` legt die Tabelle an; der Tabellenname ist gegen Injection abgesichert.
- Funktioniert mit jedem injizierten PDO — **nur MariaDB/MySQL** (nutzt `LAST_INSERT_ID()`).

### 4. SdI-Transport (`OpenapiClient` + `SdiTransport`-Contract)

- Sauberes `SdiTransport`-Interface (`sendInvoice`, `getInvoiceStatus`), sodass weitere
  Anbieter eingebunden werden können, ohne den Kern anzufassen.
- Openapi.com-Adapter: Bearer-Auth, Sandbox-/Produktions-Base-URLs, exponentielles
  Backoff mit 3 Versuchen bei 5xx-/Netzwerkfehlern, sofortiger Abbruch bei 4xx,
  injizierbarer Guzzle-Client + Sleeper für Tests sowie ein Logger, der den Bearer-Token
  aus Meldungen und Bodies entfernt.
- Der Versand erfolgt **niemals automatisch** — eine bewusste Design-Schranke für eine
  unumkehrbare steuerliche Operation.

### 5. HTTP-Microservice (`public/index.php`)

Slim-4-App mit `GET /health`, `POST /fattura/build`, `POST /fattura/numero`,
`POST /fattura/send`; DB- und Token-Konfiguration über Umgebungsvariablen; das Dockerfile
baut ein Non-Root-Alpine-Image. Geeignet als interner Sidecar für Nicht-PHP-Stacks.

> ⚠️ Der Microservice hat derzeit **keine Authentifizierung** — siehe GAPS.de.md #1.
> Nicht über localhost/ein privates Netzwerk hinaus exponieren.

### 6. Packaging

`composer.json` ist bereit für die Veröffentlichung auf Packagist: PSR-4, PHP ^8.2,
Kernabhängigkeiten nur `ext-dom`/`ext-libxml`, HTTP-/Transport-Extras in `require-dev` +
`suggest`, MIT-Lizenz, Tests an `composer test` angebunden.

## Praktische „Plug and play“-Rezepte, die sofort funktionieren

```php
// 1. Build + validate + number, no service, any PHP app:
$numero = (new NumeratoreService($pdo))->next();          // "2026/00001"
$xml    = (new XmlBuilder())->build([...]);               // FatturaPA XML
$errors = (new XmlBuilder())->validate($xml);             // [] = XSD-valid

// 2. Send via Openapi.com sandbox (token in env):
$res = OpenapiClient::createFromEnv(testMode: true)->sendInvoice($xml);

// 3. Non-PHP stack: run the Docker image, POST JSON to /fattura/build.
```

## Was „funktioniert“ noch NICHT bedeutet

XSD-Validität ≠ SdI-Annahme. Das SdI wendet ~100 zusätzliche semantische Prüfungen an
(Codes 00400–00476: Kohärenz der Summen, natura vs. aliquota, Prüfsummen des codice
fiscale, doppelte Nummern, …), und die steuerliche Korrektheit von bollo, Split Payment,
ritenute (Quellensteuerabzügen) usw. liegt in der Verantwortung des Aufrufers. Der aktuelle
Builder deckt **ausschließlich den TD01-Happy-Path der Sofortrechnung** ab — alles Weitere
ist in [GAPS.de.md](GAPS.de.md) katalogisiert.
