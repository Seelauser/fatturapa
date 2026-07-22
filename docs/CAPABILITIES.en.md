# Capabilities — what works today (plug and play)

🇮🇹 [Italiano](CAPABILITIES.en.md) · 🇬🇧 English · 🇩🇪 [Deutsch](CAPABILITIES.de.md)

> Status audit of `seelauser/fatturapa`, verified by running the code on PHP 8.3
> (tests, XSD validation against the official schema, and a live MariaDB
> concurrency test). Updated for **0.2.0** (see [CHANGELOG](../CHANGELOG.md)):
> XSD 1.2.3, TD01–TD29, bollo, split payment, CIG/CUP, DatiPagamento, person
> cedente, field-level validation, **PEC transport** (self-sufficient, no
> third-party service), SdI notification parser, API-key-protected microservice.
> Companion documents: [GAPS.md](GAPS.en.md) for what is missing,
> [ROADMAP.md](ROADMAP.en.md) for where to take the package.

## Verified working

### 1. FatturaPA XML building (`XmlBuilder`)

`build()` produces XML that **validates against the official
`Schema_del_file_xml_FatturaPA_v1.2.2.xsd`** from fatturapa.gov.it for all three
supported archetypes:

| Case | Formato | Verified |
|---|---|---|
| B2C, private person with codice fiscale, exempt line (Natura N4) | FPR12 | ✅ XSD-valid |
| B2B, company with partita IVA + codice destinatario, 22% VAT | FPR12 | ✅ XSD-valid |
| PA, public body with 6-char codice destinatario | FPA12 | ✅ XSD-valid |

Also verified:

- **Escaping** — `&`, `<`, `>` in descriptions/names survive round-trip (`htmlspecialchars(ENT_XML1)` before `createElement`).
- **VAT math** — per-(aliquota, natura) `DatiRiepilogo` aggregation, `Imposta` and `ImportoTotaleDocumento` computed correctly (covered by unit tests, 7/7 green).
- **CodiceDestinatario defaults** — `0000000` for FPR12, `999999` for FPA12; `PECDestinatario` emitted only in the legal case (FPR12 + `0000000` + pec present).
- **Negative lines** (discount/refund as negative price) pass XSD validation.
- Input is a plain PHP array — no framework types, only `ext-dom` needed. Genuinely drop-in for CiviCRM, Laravel, Symfony or plain PHP.

Supported input surface (see class docblock): `tipo_documento` (`fattura_b2b` | `fattura_b2c` | `fattura_pa`), `numero`, `data`, `progressivo_invio` (auto-random if absent), cedente (company only), cessionario (company or nome/cognome person), lines with `descrizione`/`quantita`/`prezzo_unitario`/`aliquota_iva`/`natura`, per-line `riferimento_normativo` fallback on exempt riepiloghi.

### 2. XSD validation (`XmlBuilder::validate()`)

Works once the official XSD is placed in `resources/xsd/FatturaPA_v1.2.2.xsd`
(deliberately not vendored — see `resources/xsd/README.md`). Returns a clean
`string[]` of libxml errors; empty array = valid. Without the file it degrades to a
single informational message instead of failing.

### 3. Sequential numbering (`NumeratoreService`)

- Format `YYYY/00042` and `YYYY/00042/SEZ` per year + sezionale.
- **Concurrency verified live**: 20 parallel processes × 10 increments against
  MariaDB produced exactly 200 with no gaps or duplicates (the
  `INSERT IGNORE` seed + `UPDATE … LAST_INSERT_ID()` upsert is race-free).
- `ensureTable()` bootstraps the table; table name is injection-guarded.
- Works with any injected PDO — **MariaDB/MySQL only** (uses `LAST_INSERT_ID()`).

### 4. SdI transport (`OpenapiClient` + `SdiTransport` contract)

- Clean `SdiTransport` interface (`sendInvoice`, `getInvoiceStatus`) so other
  providers can be plugged in without touching the core.
- Openapi.com adapter: bearer auth, sandbox/production base URLs, 3-attempt
  exponential backoff on 5xx/network errors, immediate failure on 4xx, injectable
  Guzzle client + sleeper for tests, and a logger that scrubs the bearer token from
  messages and bodies.
- Sending is **never automatic** — a deliberate design guard for an irreversible
  fiscal operation.

### 5. HTTP microservice (`public/index.php`)

Slim 4 app exposing `GET /health`, `POST /fattura/build`, `POST /fattura/numero`,
`POST /fattura/send`; env-driven DB + token config; Dockerfile builds a
non-root Alpine image. Suitable as an internal sidecar for non-PHP stacks.

> ⚠️ The microservice currently has **no authentication** — see GAPS.md #1. Do not
> expose it beyond localhost/a private network.

### 6. Packaging

`composer.json` is publish-ready for Packagist: PSR-4, PHP ^8.2, core deps only
`ext-dom`/`ext-libxml`, HTTP/transport extras in `require-dev` + `suggest`, MIT
license, tests wired to `composer test`.

## Practical "plug and play" recipes that work right now

```php
// 1. Build + validate + number, no service, any PHP app:
$numero = (new NumeratoreService($pdo))->next();          // "2026/00001"
$xml    = (new XmlBuilder())->build([...]);               // FatturaPA XML
$errors = (new XmlBuilder())->validate($xml);             // [] = XSD-valid

// 2. Send via Openapi.com sandbox (token in env):
$res = OpenapiClient::createFromEnv(testMode: true)->sendInvoice($xml);

// 3. Non-PHP stack: run the Docker image, POST JSON to /fattura/build.
```

## What "works" does NOT yet mean

XSD validity ≠ SdI acceptance. SdI applies ~100 additional semantic checks
(codes 00400–00476: coherence of totals, natura vs aliquota, codice fiscale
checksums, duplicate numbers, …) and the fiscal correctness of bollo, split
payment, ritenute etc. is the caller's responsibility. The current builder covers
the **TD01 immediate-invoice happy path only** — everything else is catalogued in
[GAPS.md](GAPS.en.md).
