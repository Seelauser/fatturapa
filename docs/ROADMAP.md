# Roadmap — market positioning and platform strategy

> Based on a deep market scan (2026-07-20) of the Italian e-invoicing ecosystem:
> SdI API providers, competing PHP libraries, compliance requirements
> (specifiche tecniche 1.9/1.9.1), and platform integration demand.
> Companion documents: [CAPABILITIES.md](CAPABILITIES.md), [GAPS.md](GAPS.md).

## Where this package can win

The PHP ecosystem splits into three mutually exclusive camps, and none covers the
full picture:

1. **Pure XML builders/validators, no transport** — `deved/fattura-elettronica`
   (84★, 137k downloads, active), `fatturaelettronicaphp/fattura-elettronica`
   (51★, 63k, slowing), `slam/php-validatore-fattura-elettronica` (validation only).
2. **Direct-SdI plumbing requiring government accreditation** — `taocomp/php-e-invoice-it`
   (dormant since 2022), `italia/fatturapa-php-sdk` (abandoned 2018).
3. **Single-vendor SaaS clients with the transport hard-wired** — Fattura24,
   Invoicetronic, fattura-elettronica-api.it SDKs.

**Nobody offers builder + validation + swappable transport.** That is exactly the
architecture this package already has (`SdiTransport` interface). Competing with
deved on XML generation alone is a losing move; the durable differentiators are:

- **Transport abstraction with multiple adapters** (first-to-market: Openapi.com
  has no official PHP SDK).
- **SdI notification lifecycle** (RC/NS/MC + PA-only NE/DT/AT) normalized across
  providers — no PHP package does this.
- **Laravel bridge** — the existing Laravel wrappers total <100 downloads and are
  dead; Italian PHP agencies are heavily Laravel. Zero competition.
- **CiviCRM extension** — no FatturaPA extension exists at all despite Italian
  nonprofits being under the mandate since 2019/2024. Greenfield, and it is this
  package's home turf (AlpsPlanner/associations).
- Being the embeddable core for the fragmented plugin market: WooCommerce Italian
  e-invoice plugins (4,000/800/400… active installs), PrestaShop modules sold at
  €120–168, Magento modules — each currently hand-rolls XML or hard-wires one SaaS.

## Transport adapters — priority order

| Priority | Provider | Why | Notes |
|---|---|---|---|
| ✅ done | **Openapi.com** | Raw XML in, pay-per-use (€0.022–0.07/invoice), sandbox, open docs | Signature (€0.09) and legal storage (€0.105) are separate endpoint variants (`/invoices_signature`, `/invoices_legal_storage`) — expose as options |
| 1 | **Invoicetronic** | Raw XML, €0.02–0.10/tx, free permanent sandbox, API-key auth, open docs | No conservazione offering — document it |
| 2 | **Aruba** | Raw XML (`/invoice/upload` auto-signs, `/uploadSigned` for p7m), conservazione included from €29.90/yr | OAuth2 password grant, 30-min tokens, ~30 uploads/min rate limit; full API automation is the ~€600/yr Premium tier |
| 3 | **A-Cube** | Developer-first, JWT (24h), webhooks with secret token, Legal Storage API | Sales-gated pricing |
| 4 | **Fatture in Cloud** | Huge install base (TeamSystem) | ⚠️ **Cannot accept external XML** — adapter must map the invoice array to their document model instead of sending built XML. Different adapter shape: `DocumentModelTransport` |
| ✅ done | **PEC channel** | **Self-sufficiency requirement: no third-party service, only your own PEC mailbox** | Shipped in 0.2.0 (`PecTransport` + own `SmtpClient`); async email, ≤5 MB/invoice; conservazione via the free AdE service; B2B needs no signature |
| next (independence track) | **Direct SDICoop** | Free per-invoice, fully independent | SOAP+MTOM, mutual-TLS certs from Sogei, accreditation ceremony, you must sign (CAdES/XAdES) and archive yourself — the end-state of the "build it ourselves" strategy |
| skip | Zucchetti Digital Hub, TS Digital/Agyo, InfoCert | Docs behind sales/NDA — impossible to maintain an open-source adapter | Their users can implement `SdiTransport` privately |

Design implications from the provider survey:

- **Credential diversity** (static token / OAuth2 / JWT login / API key / mTLS / PEC)
  → introduce a small credential-provider abstraction per adapter.
- **Capability flags** on the interface: `supportsSignature()`,
  `supportsLegalStorage()`, `acceptsRawXml()` — signature and conservazione are
  adapter capabilities, not givens.
- **Webhook quirks to normalize**: Openapi callback config *overwrites* on every
  call; Fatture in Cloud webhooks carry only the document ID (re-fetch needed);
  Aruba can push to callbacks *you* host; A-Cube authenticates with a secret token.

## Compliance backlog (spec-driven)

Target: **XSD 1.2.3 / specifiche tecniche 1.9.1** (current since 1 April 2025 /
15 May 2026). Concretely:

1. **Enums as first-class constants** with validation: TipoDocumento TD01–TD09,
   TD16–TD29 (TD29 new 2025); RegimeFiscale RF01–RF20 (RF20 new); Natura N1,
   N2.1–N2.2, N3.1–N3.6, N4, N5, N6.1–N6.9, N7 (sub-codes mandatory since 2021);
   ModalitaPagamento MP01–MP23; TipoRitenuta RT01–RT06; TipoCassa TC01–TC22;
   EsigibilitaIVA I/D/S.
2. **Rule engine for SdI semantic checks** (the 004xx family) — at minimum:
   Natura required iff aliquota = 0 (and vice versa); `PrezzoTotale = PrezzoUnitario × Quantita`
   (check 00423 — fix the truncation bug, GAPS #9); ritenuta flag on lines ⇒
   `DatiRitenuta` present (00411); unique numero per year/tipo (00404).
3. **DatiBollo** automation: when the non-VAT total (N1/N2.x/N3.x-exempt/N4)
   exceeds €77.47, emit `BolloVirtuale=SI` (+ `ImportoBollo 2.00`) automatically,
   with an override.
4. **Forfettario preset**: RF19 + N2.2 + no ritenuta + bollo rule — as a one-liner
   profile, since forfettari (mandated since 1 Jan 2024) are the biggest underserved
   issuer group. Requires supporting `Nome`/`Cognome` for the cedente (GAPS #4).
5. **PA preset**: FPA12 + `EsigibilitaIVA=S` (split payment) + CIG/CUP in
   `DatiOrdineAcquisto`/`DatiContratto` (PA cannot legally pay without a required CIG).
6. **Professionals preset**: `DatiRitenuta` (RT01/RT02, CausalePagamento) +
   `DatiCassaPrevidenziale` (TC01–TC22, incl. INPS 4% rivalsa edge cases).
7. **DatiPagamento** (TP01–TP03, MP codes, IBAN, scadenze).

## Notification / ciclo passivo backlog

Send-only covers half the legal mandate — every Italian VAT subject also
*receives* purchase invoices through SdI, and all surveyed providers sell
send+receive as one product.

1. **Notification taxonomy** as a value object: RC (consegna), NS (scarto, 5-day
   window, resend with same numero within 5 days), MC (mancata consegna) for the
   B2B flow; NE/EC01/EC02, DT, AT additionally for the PA flow. Branch on
   `FormatoTrasmissione`.
2. **`handleWebhook(payload)` + `pollNotifications()`** on the transport contract
   (both are needed: webhooks are the dominant mechanism, polling is the fallback).
3. **Inbound invoice retrieval + p7m unwrap** (ciclo passivo) as a phase-2
   transport capability.
4. Persisted **invoice lifecycle state machine** (built → sent → delivered/
   scartata/mancata-consegna → …) that apps can hook into.

## Suggested release plan

> Direction set 2026-07-20: **independence first** — prefer self-built channels
> (PEC now, direct SDICoop later) over provider adapters; provider adapters stay
> optional conveniences.

- ✅ **v0.2 — trustworthy core** (shipped): microservice auth, XSD 1.2.3, bollo,
  TD01–TD29, cedente as person, field-level validation, decimal-precision fix,
  DatiPagamento, split payment + CIG/CUP, **PEC transport + notification parser**.
- ✅ **v0.3 — professionals + closed PEC loop** (shipped): ritenuta d'acconto,
  cassa previdenziale, line discounts, PEC inbox polling (own IMAP client +
  PEC-envelope MIME parsing), portable numbering (PostgreSQL/SQLite).
- ✅ **v0.4 — lifecycle + ciclo passivo + rendering** (shipped): `InvoiceStore`
  state machine, incoming-invoice collection from the PEC inbox (p7m unwrap +
  parser), HTML rendering via the official foglio di stile.
- **v0.5 — distribution**: Laravel bridge (facade, config, queue-friendly
  webhooks), Packagist publication, CiviCRM extension skeleton.
- **v1.0 — ciclo passivo** + PDF rendering via the AdE foglio di stile.

## Horizon: ViDA / EN 16931

FatturaPA/SdI stays authoritative for Italian domestic invoicing through at least
2030; Italy must converge on the EU standard (EN 16931 / Peppol, ViDA package
adopted 11 March 2025) by 2035, with intra-EU digital reporting from 1 July 2030.
No action needed now, but keep the input array a *semantic model* (not an XML
mirror) so an EN 16931/Peppol BIS serializer can sit beside `XmlBuilder` later.
