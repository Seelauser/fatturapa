# Gaps — what is missing or broken

🇮🇹 [Italiano](GAPS.en.md) · 🇬🇧 English · 🇩🇪 [Deutsch](GAPS.de.md)

> Findings from a full source review + live testing (2026-07-20). Ordered by
> severity. **Updated after the 0.2.0 release** — resolved items are marked ✅
> and kept for traceability. Companion documents: [CAPABILITIES.md](CAPABILITIES.en.md),
> [ROADMAP.md](ROADMAP.en.md).

## Critical

### ✅ 1. Microservice has no authentication — FIXED in 0.2.0 (X-Api-Key middleware, refuses to run unconfigured)
`public/index.php` exposes `POST /fattura/send` — an **irreversible fiscal
operation** — with no API key, no allowlist, nothing. Anyone who can reach the
port can transmit invoices with your Openapi token, and `/fattura/numero` lets
them burn sequence numbers (numbering gaps are a fiscal-audit problem).
**Fix:** require an `X-Api-Key` header checked against an env var; refuse to start
`/fattura/send` routing when the key is unset.

### ✅ 2. Legal compliance gap: no `DatiBollo` — FIXED in 0.2.0 (automatic €2 rule > €77.47 + override)
Exempt/fuori-campo invoices (Natura N2–N6, e.g. the library's own membership-fee
use case) over **€77.47** legally require the €2 imposta di bollo, expressed as
`<DatiBollo><BolloVirtuale>SI</BolloVirtuale></DatiBollo>`. The builder cannot emit
it at all, so any exempt invoice above the threshold is fiscally wrong.

### ✅ 3. Only `TD01` — no credit notes — FIXED in 0.2.0 (full TD01–TD29 enum)
`TipoDocumento` is hardcoded. Without **TD04 (nota di credito)** there is no legal
way to correct a sent invoice — a hard blocker for any real deployment. TD05
(nota di debito), TD24 (fattura differita), TD16–TD19 (reverse charge /
integrazioni, needed since the 2022 esterometro change), and TD26 are also absent.

### ✅ 3b. Targets an expired schema version — FIXED in 0.2.0 (XSD 1.2.3, 1.2.2 fallback)
The code pins `FatturaPA_v1.2.2.xsd` — **valid only until 31 March 2025**. The
current schema is **1.2.3** (specifiche tecniche 1.9, effective 1 April 2025;
revision 1.9.1 usable from 15 May 2026), which added `TD29` and `RegimeFiscale`
`RF20`. The XSD filename/constant and any future enum sets must move to 1.2.3.

## High — blocks common Italian use cases

### ✅ 4. Cedente must be a company — FIXED in 0.2.0 (nome/cognome supported)
The builder writes only `<Denominazione>` for the supplier; a missing
`denominazione` produces a PHP *warning* and silently builds broken XML (verified).
**Ditte individuali / freelancers / forfettari — the largest population of Italian
e-invoice issuers since the 2024 forfettari mandate — cannot be represented**
(they need `Nome`/`Cognome`, like the cessionario already supports).

### ✅ 5. No `DatiPagamento` — FIXED in 0.2.0
No IBAN, payment terms, or `ModalitaPagamento` (MP01–MP23). Most B2B customers
and virtually all PA bodies expect payment data in the invoice.

### ✅ 6. No PA-specific fields — FIXED in 0.2.0 (esigibilita_iva S, CIG/CUP via ordine_acquisto)
- `EsigibilitaIVA` hardcoded to `I` — **split payment (`S`)**, standard for PA, impossible.
- No `DatiOrdineAcquisto` / `CodiceCIG` / `CodiceCUP` — PA bodies routinely refuse invoices without CIG/CUP.
So despite emitting FPA12, real PA invoicing does not work end to end.

### ✅ 7. No ritenuta d'acconto / cassa previdenziale — FIXED in 0.3.0
Professionals (accountants, engineers, lawyers…) need `DatiRitenuta` and
`DatiCassaPrevidenziale`. Combined with #4 this rules out the whole
professional/freelancer segment. *(0.3.0: `ritenuta` + `cassa` blocks with
auto-computed amounts, riepilogo integration, 00411 coherence check.)*

### ✅ 8. Wrong default `RiferimentoNormativo` — FIXED in 0.2.0 (per-natura default table)
Every exempt riepilogo defaults to *"Esente art.10 DPR 633/72"*, which is wrong
for most Natura codes (N1, N2.2 forfettario, N3.x exports, N6.x reverse charge…).
A per-natura default table (or making it mandatory when natura is set) is needed.

## Medium — correctness and robustness

### ✅ 9. `PrezzoUnitario` truncated — FIXED in 0.2.0 (full precision emitted, SdI 00423 safe)
Verified: `prezzo_unitario = 0.333, quantita = 3` emits `PrezzoUnitario 0.33` but
`PrezzoTotale 1.00` (computed from 0.333). SdI check **00423** verifies
`PrezzoTotale = PrezzoUnitario × Quantita` — 0.33 × 3 = 0.99 vs 1.00 only survives
by rounding tolerance; more decimals or larger quantities will be **rejected by
SdI**. The XSD allows up to 8 decimals: emit the actual precision instead of
`number_format(…, 2)`.

### ✅ 10. Missing-field handling is PHP warnings — FIXED in 0.2.0 (all-errors-at-once validation)
Absent `indirizzo`/`cap`/`comune`/`denominazione` produce undefined-array-key
warnings and *silently* build invalid XML (verified). The mandatory-key check only
covers top-level keys. Needs a proper field-level validation pass with readable
error messages (ideally mapping to SdI error codes).

### ✅ 11. `progressivo_invio` not validated — FIXED in 0.2.0
User-supplied values aren't checked against `[A-Za-z0-9]{1,10}`; an invalid value
fails only at XSD/SdI stage.

### ✅ 12. Numbering is MariaDB/MySQL-only — FIXED in 0.3.0 (PostgreSQL + SQLite ≥3.35 via UPSERT…RETURNING)
`LAST_INSERT_ID()` upsert doesn't work on PostgreSQL/SQLite. Fine for the current
deployment, a real limitation for a general-purpose package (Laravel world is
heavily Postgres). Remaining nit: `date('Y')` uses server TZ (edge case around New Year).

## Missing product surface (not bugs)

- **Ciclo passivo** — ✅ shipped in 0.4.0 for the PEC channel: incoming `.xml`
  and `.xml.p7m` invoices are collected from the inbox, p7m-unwrapped, and
  parsed into bookkeeping arrays. Provider-channel receiving (webhooks) still open.
- **Notification handling** — ✅ partially covered in 0.2.0: `NotificationParser`
  parses all six receipt types offline and `/fattura/status/{id}` +
  `/fattura/notifica` exist; ✅ IMAP polling of the PEC inbox shipped in
  0.3.0 (`PecInboxReader`, own IMAP client + PEC-envelope MIME parsing); ✅ persisted lifecycle state model shipped in 0.4.0 (`InvoiceStore`); still
  missing: provider webhook ingestion.
- **Transports** — ✅ PEC transport added in 0.2.0 (self-sufficient channel);
  Aruba / A-Cube / Invoicetronic / direct-SDICoop adapters still open.
- **No digital signature (CAdES/XAdES)** — fine while the provider signs, but must
  be documented per provider; direct SdI accreditation would need it.
- **Conservazione sostitutiva** — ✅ documented in 0.2.0: the free Agenzia delle
  Entrate service ("Fatture e Corrispettivi") is the recommended no-dependency
  path; provider-side storage remains optional.
- **Rendering** — ✅ HTML via the official foglio di stile shipped in 0.4.0
  (`StylesheetRenderer`); PDF output (wkhtmltopdf/dompdf on top of it) still open.
- **Other XML blocks not supported:** `ScontoMaggiorazione` at document level
  (✅ line level added in 0.3.0), `Allegati`, `DatiDDT`, `DatiContratto`, `Arrotondamento`,
  `AltriDatiGestionali`, `DatiVeicoli`, stabile organizzazione / rappresentante
  fiscale, multiple bodies per file (lotto di fatture). (`Causale` ✅ added in 0.2.0.)

## Test coverage gaps

0.3.0 brought the suite to 39 tests incl. XSD validation of every built invoice
(when the schema is vendored), builder edge cases, `NotificationParser`,
`PecTransport` (mocked SMTP), `NumeratoreService` on SQLite, and
`MimeAttachmentExtractor` with a nested PEC message. 0.4.1 added
`OpenapiClient` tests with a mocked Guzzle handler (retry/backoff, 4xx vs 5xx,
token scrubbing) — 57 tests total. Still missing: `SmtpClient`/`ImapClient`
protocol tests and microservice endpoint tests (auth middleware, routes).
