# Changelog

## 0.5.0 — 2026-07-20

Independence from the originating project: no more correlation between this
public package and the private commercial product it was extracted from.

### Changed
- **PHP namespace**: `AlpsFatturapa\` → `Fatturapa\` across all source, tests,
  and the microservice. Breaking change for existing consumers.
- **Composer package**: renamed `alpsplanner/fatturapa` → `seelauser/fatturapa`;
  author/copyright holder updated accordingly (`LICENSE`, `composer.json`).
- **Docs and README** (all three languages): removed the "extracted from
  AlpsPlanner" attribution and the alpsplanner.com link; replaced with a
  generic mention of the real-world nonprofit use case that shaped the
  requirements, with no identifying reference to the source product.
- `phpunit.xml` test suite name updated to match the new package name.

### Why
Publicly naming the specific commercial product this was extracted from
exposed unnecessary reconnaissance value (implementation details, endpoint
shapes, env var names) about a live production system with no benefit to
this package's users. The package stands on its own merits; no reader needs
to know what product it originated from.

## 0.4.2 — 2026-07-20

### Changed
- **Full trilingual documentation**: docs/CAPABILITIES, docs/GAPS and
  docs/ROADMAP now exist in Italian (primary, unsuffixed), English (.en.md)
  and German (.de.md), each with a language switcher; READMEs link to their
  own language. CHANGELOG remains English.

## 0.4.1 — 2026-07-20

Review pass + trilingual documentation.

### Fixed
- **Automatic bollo rule refined**: only nature actually subject to the €2
  stamp count toward the €77.47 threshold (N1, N2.x, N3.5, N3.6, N4, N5) —
  exports/intra-EU (N3.1–N3.4) and reverse-charge (N6.x, N7) lines no longer
  trigger a wrong `DatiBollo`.
- **`ImportoPagamento` default is net of ritenuta**: when a `pagamento`
  dettaglio has no explicit `importo`, the emitted amount is now the sum the
  customer actually pays (total − withholding) instead of the gross total.
- Dockerfile now installs `ext-xsl` (stylesheet rendering inside the container).

### Added
- `OpenapiClient` test coverage with a mocked Guzzle handler: bearer header,
  5xx retry, immediate 4xx failure, missing-uuid error, token never logged.
- **Trilingual documentation**: `README.md` is now Italian (primary),
  with full `README.en.md` and `README.de.md` translations. Working documents
  (docs/, CHANGELOG) remain in English.

## 0.4.0 — 2026-07-20

Lifecycle, ciclo passivo, and rendering — the full send-and-receive cycle now
runs end to end with only a PEC mailbox.

### Added
- **Invoice lifecycle store** (`Lifecycle\InvoiceStore`, portable PDO —
  MySQL/PostgreSQL/SQLite): built → sent → delivered / rejected / undelivered
  (+ PA: accepted / refused / expired). `applyNotification()` matches SdI
  receipts to stored invoices by transmission filename and records scarto
  errors; `listByStatus()` for dashboards/retry queues.
- **Ciclo passivo**: `Passive\P7mExtractor` unwraps CAdES `.xml.p7m` files
  (raw DER and base64; signature already verified by SdI) and
  `Passive\ReceivedInvoiceParser` turns any received FatturaPA XML into a
  bookkeeping-ready array (fornitore, totals, lines, riepiloghi, payment
  deadlines/IBAN). `PecInboxReader::fetchAll()` now classifies inbox content
  into notifications **and** incoming invoices; `GET /fattura/inbox` returns both.
- **Human-readable rendering** (`Render\StylesheetRenderer`): FatturaPA →
  HTML via the official AdE *foglio di stile* XSL (place it in
  `resources/xsl/`, not vendored; requires php-xsl). New route
  `POST /fattura/render`.

## 0.3.0 — 2026-07-20

Professionals, portable numbering, and the closed PEC loop — still zero
third-party dependencies.

### Added
- **Ritenuta d'acconto** (`ritenuta` block RT01–RT06 + per-line `ritenuta`
  flag; auto-computed importo; SdI 00411 coherence enforced) and
  **cassa previdenziale** (`cassa` block TC01–TC22, contribution auto-added to
  the correct VAT riepilogo and document total, INPS-rivalsa ritenuta flag).
- **Line discounts**: `sconto_percentuale` / `sconto_importo` →
  `ScontoMaggiorazione` with consistent `PrezzoTotale` (SdI 00423 safe).
- **PEC inbox polling**: dependency-free `ImapClient` (ext-imap is deprecated),
  `MimeAttachmentExtractor` (recurses into the PEC `postacert.eml` envelope),
  `PecInboxReader::fetchNotifications()`, and microservice route
  `GET /fattura/inbox`.
- **Portable numbering**: `NumeratoreService` now also supports PostgreSQL and
  SQLite ≥3.35 via a single atomic `UPSERT … RETURNING`; MariaDB/MySQL path
  unchanged (concurrency re-verified: 20 processes × 10 → exactly 200).

### Fixed
- Sezionale counters are now case-insensitive (`'ext'` and `'EXT'` used to get
  independent counters while printing the same invoice number).

## 0.2.0 — 2026-07-20

Self-sufficiency and compliance release: everything that can run without a
third-party service now does.

### Added
- **PEC transport** (`Transport\PecTransport` + minimal `Transport\SmtpClient`,
  dependency-free): send invoices to SdI using only your own PEC mailbox —
  SdI-pattern filenames (`IT<piva>_<progressivo>.xml`), 5 MB guard, first-contact
  vs dedicated-address handling. Selectable in the microservice via
  `SDI_TRANSPORT=pec`.
- **SdI notification parsing** (`Notifications\NotificationParser`,
  `Notifications\SdiNotification`): offline parsing of RC / NS (with error list) /
  MC / NE (EC01/EC02) / DT / AT receipt files, with `isPositive()` /
  `isRejection()` semantics.
- **Microservice API-key auth**: all routes except `/health` require
  `X-Api-Key` matching `API_KEY`; the service refuses to operate unconfigured.
- New microservice routes: `GET /fattura/status/{id}`, `POST /fattura/notifica`.
- **XmlBuilder compliance surface**:
  - any `TipoDocumento` TD01–TD29 (credit notes TD04, deferred TD24, reverse
    charge TD16–TD19, …); legacy `fattura_b2b|b2c|pa` aliases still work,
    explicit `formato` (FPR12/FPA12) supported;
  - automatic **imposta di bollo** (`DatiBollo`, €2) when exempt totals exceed
    €77.47, with explicit `bollo` override;
  - **cedente as physical person** (`nome`/`cognome`) — freelancers/forfettari;
  - **split payment** via `esigibilita_iva` (I/D/S);
  - **CIG/CUP** via `ordine_acquisto` (`DatiOrdineAcquisto`);
  - **`DatiPagamento`** (condizioni TP, dettagli MP, IBAN, scadenza);
  - `causale` (auto-split over 200-char elements);
  - per-natura default `RiferimentoNormativo` table (correct N2.2 forfettario
    wording instead of the old blanket art.10 text);
  - field-level validation collecting **all** errors in one exception (no more
    PHP warnings + silently broken XML), including natura ⇔ aliquota-0 coherence
    (SdI 00400/00401), granular Natura sub-codes, codice destinatario length per
    formato, progressivo_invio pattern, IT CAP/partita IVA formats.
- Targets **XSD 1.2.3** (specifiche tecniche 1.9.x); `validate()` picks the
  newest vendored schema, 1.2.2 as fallback.

### Fixed
- `PrezzoUnitario` no longer truncated to 2 decimals: full input precision
  (up to 8 decimals) is emitted so `PrezzoTotale = PrezzoUnitario × Quantita`
  holds exactly (SdI check 00423).
- `EsigibilitaIVA` is no longer emitted on exempt (natura) riepiloghi.

## 0.1.0 — 2026-07-19

Initial extraction from a production nonprofit-invoicing system: `XmlBuilder`
(TD01 happy path), `NumeratoreService`, Openapi.com transport, Slim microservice.
