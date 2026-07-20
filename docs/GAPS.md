# Gaps — what is missing or broken

> Findings from a full source review + live testing (2026-07-20). Ordered by
> severity. Companion documents: [CAPABILITIES.md](CAPABILITIES.md),
> [ROADMAP.md](ROADMAP.md).

## Critical

### 1. Microservice has no authentication
`public/index.php` exposes `POST /fattura/send` — an **irreversible fiscal
operation** — with no API key, no allowlist, nothing. Anyone who can reach the
port can transmit invoices with your Openapi token, and `/fattura/numero` lets
them burn sequence numbers (numbering gaps are a fiscal-audit problem).
**Fix:** require an `X-Api-Key` header checked against an env var; refuse to start
`/fattura/send` routing when the key is unset.

### 2. Legal compliance gap: no `DatiBollo`
Exempt/fuori-campo invoices (Natura N2–N6, e.g. the library's own membership-fee
use case) over **€77.47** legally require the €2 imposta di bollo, expressed as
`<DatiBollo><BolloVirtuale>SI</BolloVirtuale></DatiBollo>`. The builder cannot emit
it at all, so any exempt invoice above the threshold is fiscally wrong.

### 3. Only `TD01` — no credit notes
`TipoDocumento` is hardcoded. Without **TD04 (nota di credito)** there is no legal
way to correct a sent invoice — a hard blocker for any real deployment. TD05
(nota di debito), TD24 (fattura differita), TD16–TD19 (reverse charge /
integrazioni, needed since the 2022 esterometro change), and TD26 are also absent.

### 3b. Targets an expired schema version
The code pins `FatturaPA_v1.2.2.xsd` — **valid only until 31 March 2025**. The
current schema is **1.2.3** (specifiche tecniche 1.9, effective 1 April 2025;
revision 1.9.1 usable from 15 May 2026), which added `TD29` and `RegimeFiscale`
`RF20`. The XSD filename/constant and any future enum sets must move to 1.2.3.

## High — blocks common Italian use cases

### 4. Cedente must be a company
The builder writes only `<Denominazione>` for the supplier; a missing
`denominazione` produces a PHP *warning* and silently builds broken XML (verified).
**Ditte individuali / freelancers / forfettari — the largest population of Italian
e-invoice issuers since the 2024 forfettari mandate — cannot be represented**
(they need `Nome`/`Cognome`, like the cessionario already supports).

### 5. No `DatiPagamento`
No IBAN, payment terms, or `ModalitaPagamento` (MP01–MP23). Most B2B customers
and virtually all PA bodies expect payment data in the invoice.

### 6. No PA-specific fields
- `EsigibilitaIVA` hardcoded to `I` — **split payment (`S`)**, standard for PA, impossible.
- No `DatiOrdineAcquisto` / `CodiceCIG` / `CodiceCUP` — PA bodies routinely refuse invoices without CIG/CUP.
So despite emitting FPA12, real PA invoicing does not work end to end.

### 7. No ritenuta d'acconto / cassa previdenziale
Professionals (accountants, engineers, lawyers…) need `DatiRitenuta` and
`DatiCassaPrevidenziale`. Combined with #4 this rules out the whole
professional/freelancer segment.

### 8. Wrong default `RiferimentoNormativo`
Every exempt riepilogo defaults to *"Esente art.10 DPR 633/72"*, which is wrong
for most Natura codes (N1, N2.2 forfettario, N3.x exports, N6.x reverse charge…).
A per-natura default table (or making it mandatory when natura is set) is needed.

## Medium — correctness and robustness

### 9. `PrezzoUnitario` truncated, totals computed from untruncated value
Verified: `prezzo_unitario = 0.333, quantita = 3` emits `PrezzoUnitario 0.33` but
`PrezzoTotale 1.00` (computed from 0.333). SdI check **00423** verifies
`PrezzoTotale = PrezzoUnitario × Quantita` — 0.33 × 3 = 0.99 vs 1.00 only survives
by rounding tolerance; more decimals or larger quantities will be **rejected by
SdI**. The XSD allows up to 8 decimals: emit the actual precision instead of
`number_format(…, 2)`.

### 10. Missing-field handling is PHP warnings, not validation
Absent `indirizzo`/`cap`/`comune`/`denominazione` produce undefined-array-key
warnings and *silently* build invalid XML (verified). The mandatory-key check only
covers top-level keys. Needs a proper field-level validation pass with readable
error messages (ideally mapping to SdI error codes).

### 11. `progressivo_invio` not validated
User-supplied values aren't checked against `[A-Za-z0-9]{1,10}`; an invalid value
fails only at XSD/SdI stage.

### 12. Numbering is MariaDB/MySQL-only
`LAST_INSERT_ID()` upsert doesn't work on PostgreSQL/SQLite. Fine for the current
deployment, a real limitation for a general-purpose package (Laravel world is
heavily Postgres). Also `date('Y')` uses server TZ (edge case around New Year).

## Missing product surface (not bugs)

- **No ciclo passivo** — receiving supplier invoices from SdI is absent entirely.
- **No notification handling** — no webhook/polling for SdI receipts (RC consegna,
  NS scarto, MC mancata consegna, decorrenza termini). `getInvoiceStatus()` exists
  on the transport but is not exposed by the microservice and there is no
  state model for the invoice lifecycle.
- **Single transport** — only Openapi.com; the `SdiTransport` interface is ready
  but no Aruba / Fatture in Cloud / A-Cube / direct-SDICoop adapters exist.
- **No digital signature (CAdES/XAdES)** — fine while the provider signs, but must
  be documented per provider; direct SdI accreditation would need it.
- **No conservazione sostitutiva** story (10-year legal archiving) — provider-dependent, undocumented.
- **No PDF rendering** (foglio di stile / human-readable copy) — commonly expected.
- **Other XML blocks not supported:** `ScontoMaggiorazione` (line and document
  level), `Causale`, `Allegati`, `DatiDDT`, `DatiContratto`, `Arrotondamento`,
  `AltriDatiGestionali`, `DatiVeicoli`, stabile organizzazione / rappresentante
  fiscale, multiple bodies per file (lotto di fatture).

## Test coverage gaps

Unit tests cover the happy path only. Missing: XSD-validation tests in CI (the
XSD is not vendored, so CI never schema-validates), builder edge cases (#4, #9,
#10 above), `NumeratoreService` tests (needs a DB fixture or SQLite-compatible
rewrite), `OpenapiClient` tests with a mocked Guzzle handler (retry/backoff,
4xx vs 5xx, token scrubbing), and microservice endpoint tests.
