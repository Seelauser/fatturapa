# Changelog

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

Initial extraction from AlpsPlanner: `XmlBuilder` (TD01 happy path),
`NumeratoreService`, Openapi.com transport, Slim microservice.
