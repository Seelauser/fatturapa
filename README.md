# alpsplanner/fatturapa

A small, **framework-free** PHP library to build Italian **FatturaPA** (Sistema di
Interscambio / SdI) electronic-invoice XML and to reserve sequential invoice numbers —
plus an optional HTTP microservice. Extracted from [AlpsPlanner](https://alpsplanner.com)
and usable by any PHP project.

- **No framework dependency** — the core (`XmlBuilder`, `NumeratoreService`) needs only
  `ext-dom`. Drop it into CiviCRM, Laravel, Symfony, or plain PHP.
- **Self-sufficient by design** — the **PEC transport** sends to SdI with nothing but
  your own PEC mailbox (zero third-party services); the `NotificationParser` reads the
  SdI receipts (RC/NS/MC/NE/DT/AT) offline; validation runs locally against the
  official XSD 1.2.3. Conservazione can use the free Agenzia delle Entrate service.
- **Compliance built in** — TD01–TD29, granular Natura sub-codes, automatic €2
  imposta di bollo on exempt totals > €77.47, split payment (`EsigibilitaIVA=S`),
  CIG/CUP for PA, `DatiPagamento`, forfettario-friendly (person cedente, RF19, N2.2).
- **Concurrency-safe numbering** — atomic per-year/per-sezionale counter on MariaDB/MySQL.
- **Swappable SdI transport** — `SdiTransport` interface; PEC and Openapi.com adapters bundled.
- **Optional microservice** — a tiny Slim app exposes `build` / `numero` / `send` /
  `status` / `notifica` over HTTP, protected by an `X-Api-Key` header.

> ⚠️ Sending to SdI is an **irreversible fiscal operation**. This library never sends
> automatically — a human triggers `send`. Always verify fiscal treatment with an accountant.

## Install

```bash
composer require alpsplanner/fatturapa
```

Requires PHP 8.2+, `ext-dom`, `ext-libxml`.

## Build XML

```php
use AlpsFatturapa\XmlBuilder;

$xml = (new XmlBuilder())->build([
    'tipo_documento' => 'fattura_b2c',
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

More input options (see the `XmlBuilder` docblock for the full shape):

```php
'tipo_documento' => 'TD04',                     // any TD01…TD29 (TD04 = credit note)
'formato'        => 'FPA12',                    // public administration
'esigibilita_iva'=> 'S',                        // split payment (PA)
'ordine_acquisto'=> ['cig' => 'Z123456789', 'cup' => '...'],
'bollo'          => true,                       // omit for the automatic €2 rule (> €77.47 exempt)
'causale'        => 'Riferimento pratica ...',
'cedente'        => ['nome' => 'Max', 'cognome' => 'Muster', 'regime_fiscale' => 'RF19', ...], // freelancer/forfettario
'pagamento'      => ['condizioni' => 'TP02', 'dettagli' => [['modalita' => 'MP05', 'iban' => 'IT60...', 'scadenza' => '2026-08-01']]],
'ritenuta'       => ['tipo' => 'RT01', 'aliquota' => 20.0, 'causale' => 'A'],   // + 'ritenuta' => true on the lines
'cassa'          => ['tipo' => 'TC22', 'aliquota' => 4.0, 'aliquota_iva' => 22.0], // cassa previdenziale (auto-added to totals)
// per line: 'sconto_percentuale' => 10.0 or 'sconto_importo' => 5.0
```

Validation is two-layered: `build()` itself checks fields and SdI semantic rules
(natura ⇔ aliquota 0, granular sub-codes, codice destinatario length, …) and throws
with **all** errors listed. Optional XSD validation (place the official
`FatturaPA_v1.2.3.xsd` in `resources/xsd/`; 1.2.2 is picked up as fallback):

```php
$errors = (new XmlBuilder())->validate($xml); // [] when valid
```

## Reserve an invoice number

```php
use AlpsFatturapa\NumeratoreService;

$svc = new NumeratoreService($pdo);       // MariaDB/MySQL, PostgreSQL or SQLite ≥3.35; table configurable
$svc->ensureTable();                       // creates `sdi_sequence` if missing
$numero = $svc->next();                    // "2026/00001", "2026/00002", …
$numero = $svc->next(2026, 'EXT');         // "2026/00001/EXT" (separate sezionale)
```

## Send to SdI (manual)

### Via your own PEC mailbox — no third-party service

```php
use AlpsFatturapa\Transport\PecTransport;

$pec = new PecTransport(
    pecAddress:  'azienda@pec.example.it',
    cedentePiva: '01234567890',
    smtpHost:    'smtps.pec.aruba.it',   // your PEC provider's SMTP
    smtpUsername:'azienda@pec.example.it',
    smtpPassword:'***',
    // First send ever goes to sdi01@pec.fatturapa.it; SdI replies with your
    // dedicated address — pass it as $sdiAddress from then on.
);
$result = $pec->sendInvoice($xml, ['progressivo' => '00042']);
// ['identificativo' => 'IT01234567890_00042.xml', ...]
```

SdI receipts arrive back in the PEC inbox. Poll it automatically (own IMAP
client, handles the PEC `postacert.eml` nesting):

```php
use AlpsFatturapa\Notifications\PecInboxReader;

foreach (PecInboxReader::createFromEnv()->fetchNotifications() as $f) {
    // $f['filename'], $f['notification'] (SdiNotification)
}
```

…or parse a notification XML you already have:

```php
use AlpsFatturapa\Notifications\NotificationParser;

$n = (new NotificationParser())->parse($attachmentXml);
$n->tipo;          // 'RC' | 'NS' | 'MC' | 'NE' | 'DT' | 'AT'
$n->isRejection(); // true on scarto → fix and resend within 5 days (same numero allowed)
$n->errori;        // [['codice' => '00404', 'descrizione' => ...], ...]
```

Notes on the PEC channel: message ≤ 30 MB, invoice ≤ 5 MB, asynchronous (no
instant accept/reject). Signature is **not** required for B2B/B2C; FPA12 (PA)
invoices must be signed — that needs a qualified certificate regardless of channel.
For free 10-year conservazione, activate the Agenzia delle Entrate service in
"Fatture e Corrispettivi".

### Via Openapi.com (optional intermediary)

```php
use AlpsFatturapa\Transport\OpenapiClient;

$client = OpenapiClient::createFromEnv(testMode: true); // reads OPENAPI_TOKEN
$result = $client->sendInvoice($xml, ['numero' => '2026/00042']);
// ['identificativo' => '<uuid>', 'raw' => [...]]
```

Implement `AlpsFatturapa\Contracts\SdiTransport` to plug in a different provider.

## HTTP microservice (optional)

Install with dev/extra deps (Slim, Guzzle) and serve `public/`:

```bash
composer install
php -S 0.0.0.0:8080 -t public
```

All routes except `/health` require the `X-Api-Key` header matching the `API_KEY`
env var; the service refuses requests when `API_KEY` is unset.

| Method & path        | Body                          | Returns |
|----------------------|-------------------------------|---------|
| `GET  /health`       | —                             | `{status:"ok"}` |
| `POST /fattura/build`| `{ invoice: {…} }`            | `{ xml, valid, errors }` |
| `POST /fattura/numero`| `{ year?, sezionale? }`      | `{ numero }` (needs DB env) |
| `POST /fattura/send` | `{ xml, meta? }`              | `{ identificativo, raw }` |
| `GET  /fattura/status/{id}` | —                      | `{ status, raw }` |
| `POST /fattura/notifica` | `{ xml }`                 | parsed SdI notification |
| `GET  /fattura/inbox` | —                            | new SdI notifications from the PEC inbox (IMAP) |

Env: `API_KEY` (required), `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
`DB_PORT`, `SDI_SEQUENCE_TABLE`; transport selection `SDI_TRANSPORT` (`pec` |
`openapi`, default `openapi`). For `openapi`: `OPENAPI_TOKEN`, `SDI_MODE`
(`production` disables sandbox). For `pec`: `PEC_ADDRESS`, `PEC_SMTP_HOST`,
`PEC_SMTP_USERNAME`, `PEC_SMTP_PASSWORD`, `CEDENTE_PIVA`, optional
`PEC_SDI_ADDRESS` (after SdI assigns it), `PEC_SMTP_PORT`. Inbox polling:
`PEC_IMAP_HOST`, optional `PEC_IMAP_PORT` (993), `PEC_IMAP_USERNAME`/`PEC_IMAP_PASSWORD`
(default to the SMTP credentials).

## Documentation

- [docs/CAPABILITIES.md](docs/CAPABILITIES.md) — what works today, verified plug-and-play surface
- [docs/GAPS.md](docs/GAPS.md) — known gaps, bugs, and compliance holes (ordered by severity)
- [docs/ROADMAP.md](docs/ROADMAP.md) — market positioning, transport-adapter priorities, release plan

## Test

```bash
composer install && composer test
```

## License

MIT — see [LICENSE](LICENSE).
