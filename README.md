# alpsplanner/fatturapa

A small, **framework-free** PHP library to build Italian **FatturaPA** (Sistema di
Interscambio / SdI) electronic-invoice XML and to reserve sequential invoice numbers —
plus an optional HTTP microservice. Extracted from [AlpsPlanner](https://alpsplanner.com)
and usable by any PHP project.

- **No framework dependency** — the core (`XmlBuilder`, `NumeratoreService`) needs only
  `ext-dom`. Drop it into CiviCRM, Laravel, Symfony, or plain PHP.
- **Concurrency-safe numbering** — atomic per-year/per-sezionale counter on MariaDB/MySQL.
- **Swappable SdI transport** — `SdiTransport` interface; an Openapi.com adapter is bundled.
- **Optional microservice** — a tiny Slim app exposes `build` / `numero` / `send` over HTTP.

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

Optional XSD validation (place the official `FatturaPA_v1.2.2.xsd` in `resources/xsd/`):

```php
$errors = (new XmlBuilder())->validate($xml); // [] when valid
```

## Reserve an invoice number

```php
use AlpsFatturapa\NumeratoreService;

$svc = new NumeratoreService($pdo);       // any PDO (MariaDB/MySQL); table configurable
$svc->ensureTable();                       // creates `sdi_sequence` if missing
$numero = $svc->next();                    // "2026/00001", "2026/00002", …
$numero = $svc->next(2026, 'EXT');         // "2026/00001/EXT" (separate sezionale)
```

## Send to SdI (manual)

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

| Method & path        | Body                          | Returns |
|----------------------|-------------------------------|---------|
| `GET  /health`       | —                             | `{status:"ok"}` |
| `POST /fattura/build`| `{ invoice: {…} }`            | `{ xml, valid, errors }` |
| `POST /fattura/numero`| `{ year?, sezionale? }`      | `{ numero }` (needs DB env) |
| `POST /fattura/send` | `{ xml, meta? }`              | `{ identificativo, raw }` (needs `OPENAPI_TOKEN`) |

Env: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PORT`, `SDI_SEQUENCE_TABLE`,
`OPENAPI_TOKEN`, `SDI_MODE` (`production` disables sandbox).

## Test

```bash
composer install && composer test
```

## License

MIT — see [LICENSE](LICENSE).
