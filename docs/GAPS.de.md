# Lücken — was fehlt oder defekt ist

🇮🇹 [Italiano](GAPS.md) · 🇬🇧 [English](GAPS.en.md) · 🇩🇪 Deutsch

> Erkenntnisse aus einem vollständigen Quellcode-Review + Live-Tests (2026-07-20).
> Nach Schweregrad geordnet. **Aktualisiert nach dem 0.2.0-Release** — behobene Punkte
> sind mit ✅ markiert und zur Nachvollziehbarkeit beibehalten. Begleitdokumente:
> [CAPABILITIES.de.md](CAPABILITIES.de.md), [ROADMAP.de.md](ROADMAP.de.md).

## Kritisch

### ✅ 1. Microservice ohne Authentifizierung — BEHOBEN in 0.2.0 (X-Api-Key-Middleware, verweigert unkonfigurierten Start)
`public/index.php` exponiert `POST /fattura/send` — eine **unumkehrbare steuerliche
Operation** — ohne API-Key, ohne Allowlist, ohne alles. Jeder, der den Port erreichen
kann, kann mit deinem Openapi-Token Rechnungen übermitteln, und `/fattura/numero`
erlaubt das Verbrennen von Sequenznummern (Nummerierungslücken sind ein Problem bei
Steuerprüfungen).
**Fix:** einen `X-Api-Key`-Header verlangen, der gegen eine Umgebungsvariable geprüft
wird; das Routing von `/fattura/send` verweigern, wenn der Key nicht gesetzt ist.

### ✅ 2. Rechtliche Compliance-Lücke: kein `DatiBollo` — BEHOBEN in 0.2.0 (automatische 2-€-Regel > 77,47 € + Override)
Steuerbefreite/fuori-campo-Rechnungen (nicht steuerbare Umsätze; Natura N2–N6, z. B. der
Mitgliedsbeitrags-Use-Case der Bibliothek selbst) über **77,47 €** erfordern gesetzlich
die imposta di bollo (Stempelsteuer) von 2 €, ausgedrückt als
`<DatiBollo><BolloVirtuale>SI</BolloVirtuale></DatiBollo>`. Der Builder kann sie
überhaupt nicht ausgeben, sodass jede steuerbefreite Rechnung über der Schwelle
steuerlich falsch ist.

### ✅ 3. Nur `TD01` — keine Gutschriften — BEHOBEN in 0.2.0 (vollständiges TD01–TD29-Enum)
`TipoDocumento` ist hartkodiert. Ohne **TD04 (nota di credito, Gutschrift)** gibt es
keinen legalen Weg, eine gesendete Rechnung zu korrigieren — ein harter Blocker für
jeden realen Einsatz. TD05 (nota di debito, Lastschrift/Belastungsanzeige), TD24
(fattura differita, Sammelrechnung), TD16–TD19 (Reverse Charge / integrazioni, seit der
Esterometro-Änderung 2022 erforderlich) und TD26 fehlen ebenfalls.

### ✅ 3b. Zielt auf eine abgelaufene Schema-Version — BEHOBEN in 0.2.0 (XSD 1.2.3, 1.2.2-Fallback)
Der Code pinnt `FatturaPA_v1.2.2.xsd` — **nur bis 31. März 2025 gültig**. Das aktuelle
Schema ist **1.2.3** (specifiche tecniche 1.9, wirksam ab 1. April 2025; Revision 1.9.1
nutzbar ab 15. Mai 2026), das `TD29` und `RegimeFiscale` `RF20` hinzugefügt hat.
Der XSD-Dateiname/die Konstante und alle künftigen Enum-Sets müssen auf 1.2.3 wechseln.

## Hoch — blockiert gängige italienische Use Cases

### ✅ 4. Cedente muss ein Unternehmen sein — BEHOBEN in 0.2.0 (nome/cognome unterstützt)
Der Builder schreibt für den Lieferanten nur `<Denominazione>`; eine fehlende
`denominazione` erzeugt eine PHP-*Warning* und baut stillschweigend kaputtes XML
(verifiziert). **Ditte individuali (Einzelunternehmen) / Freiberufler / forfettari
(Pauschalbesteuerte) — seit der Forfettari-Pflicht 2024 die größte Gruppe italienischer
E-Rechnungs-Aussteller — können nicht abgebildet werden** (sie benötigen
`Nome`/`Cognome`, wie es der cessionario bereits unterstützt).

### ✅ 5. Kein `DatiPagamento` — BEHOBEN in 0.2.0
Kein IBAN, keine Zahlungsbedingungen, kein `ModalitaPagamento` (MP01–MP23). Die meisten
B2B-Kunden und praktisch alle PA-Einrichtungen erwarten Zahlungsdaten in der Rechnung.

### ✅ 6. Keine PA-spezifischen Felder — BEHOBEN in 0.2.0 (esigibilita_iva S, CIG/CUP via ordine_acquisto)
- `EsigibilitaIVA` hartkodiert auf `I` — **Split Payment (`S`)**, der Standard für die PA, unmöglich.
- Kein `DatiOrdineAcquisto` / `CodiceCIG` / `CodiceCUP` — PA-Einrichtungen weisen Rechnungen ohne CIG/CUP routinemäßig zurück.
Trotz FPA12-Ausgabe funktioniert echte PA-Fakturierung also nicht Ende-zu-Ende.

### ✅ 7. Keine ritenuta d'acconto / cassa previdenziale — BEHOBEN in 0.3.0
Freiberufler (Steuerberater, Ingenieure, Anwälte …) benötigen `DatiRitenuta`
(ritenuta d'acconto = Quellensteuer) und `DatiCassaPrevidenziale`
(cassa previdenziale = berufsständische Vorsorgekasse). In Kombination mit #4 schließt
das das gesamte Freiberufler-Segment aus. *(0.3.0: `ritenuta`- + `cassa`-Blöcke mit
automatisch berechneten Beträgen, Riepilogo-Integration, 00411-Kohärenzprüfung.)*

### ✅ 8. Falscher Default für `RiferimentoNormativo` — BEHOBEN in 0.2.0 (Default-Tabelle je Natura)
Jeder steuerbefreite riepilogo fällt auf *"Esente art.10 DPR 633/72"* zurück, was für
die meisten Natura-Codes falsch ist (N1, N2.2 forfettario, N3.x Exporte, N6.x Reverse
Charge …). Nötig ist eine Default-Tabelle je Natura (oder Pflichtangabe, sobald natura
gesetzt ist).

## Mittel — Korrektheit und Robustheit

### ✅ 9. `PrezzoUnitario` abgeschnitten — BEHOBEN in 0.2.0 (volle Präzision ausgegeben, SdI-00423-sicher)
Verifiziert: `prezzo_unitario = 0.333, quantita = 3` gibt `PrezzoUnitario 0.33`, aber
`PrezzoTotale 1.00` aus (berechnet aus 0.333). Die SdI-Prüfung **00423** verifiziert
`PrezzoTotale = PrezzoUnitario × Quantita` — 0.33 × 3 = 0.99 vs. 1.00 überlebt nur dank
Rundungstoleranz; mehr Dezimalstellen oder größere Mengen werden **vom SdI abgelehnt**.
Das XSD erlaubt bis zu 8 Dezimalstellen: die tatsächliche Präzision ausgeben statt
`number_format(…, 2)`.

### ✅ 10. Behandlung fehlender Felder sind PHP-Warnings — BEHOBEN in 0.2.0 (Validierung mit allen Fehlern auf einmal)
Fehlende `indirizzo`/`cap`/`comune`/`denominazione` erzeugen Undefined-array-key-Warnings
und bauen *stillschweigend* invalides XML (verifiziert). Die Pflichtfeld-Prüfung deckt
nur Top-Level-Keys ab. Nötig ist ein sauberer Validierungslauf auf Feldebene mit
lesbaren Fehlermeldungen (idealerweise mit Mapping auf SdI-Fehlercodes).

### ✅ 11. `progressivo_invio` nicht validiert — BEHOBEN in 0.2.0
Vom Nutzer gelieferte Werte werden nicht gegen `[A-Za-z0-9]{1,10}` geprüft; ein
ungültiger Wert scheitert erst auf XSD-/SdI-Ebene.

### ✅ 12. Nummerierung nur MariaDB/MySQL — BEHOBEN in 0.3.0 (PostgreSQL + SQLite ≥3.35 via UPSERT…RETURNING)
Der `LAST_INSERT_ID()`-Upsert funktioniert nicht auf PostgreSQL/SQLite. Für das aktuelle
Deployment in Ordnung, für ein General-Purpose-Paket eine echte Einschränkung (die
Laravel-Welt ist stark Postgres-lastig). Verbleibende Kleinigkeit: `date('Y')` nutzt die
Server-Zeitzone (Randfall rund um den Jahreswechsel).

## Fehlende Produktoberfläche (keine Bugs)

- **Ciclo passivo (Eingangsrechnungszyklus)** — ✅ in 0.4.0 für den PEC-Kanal
  ausgeliefert: eingehende `.xml`- und `.xml.p7m`-Rechnungen werden aus dem Postfach
  eingesammelt, aus dem p7m-Container ausgepackt und in Buchhaltungs-Arrays geparst.
  Empfang über Anbieter-Kanäle (Webhooks) noch offen.
- **Benachrichtigungs-Handling** — ✅ teilweise abgedeckt in 0.2.0: `NotificationParser`
  parst alle sechs Quittungstypen offline, und `/fattura/status/{id}` +
  `/fattura/notifica` existieren; ✅ IMAP-Polling des PEC-Postfachs in 0.3.0
  ausgeliefert (`PecInboxReader`, eigener IMAP-Client + Parsing der PEC-Envelope-MIME);
  ✅ persistiertes Lifecycle-State-Modell in 0.4.0 ausgeliefert (`InvoiceStore`);
  noch fehlend: Webhook-Ingestion der Anbieter.
- **Transporte** — ✅ PEC-Transport in 0.2.0 hinzugefügt (autarker Kanal);
  Aruba- / A-Cube- / Invoicetronic- / direkte SDICoop-Adapter noch offen.
- **Keine digitale Signatur (CAdES/XAdES)** — in Ordnung, solange der Anbieter signiert,
  muss aber je Anbieter dokumentiert werden; eine direkte SdI-Akkreditierung würde sie
  benötigen.
- **Conservazione sostitutiva (gesetzeskonforme Langzeitarchivierung)** — ✅ dokumentiert
  in 0.2.0: der kostenlose Dienst der Agenzia delle Entrate („Fatture e Corrispettivi“)
  ist der empfohlene abhängigkeitsfreie Weg; anbieterseitige Archivierung bleibt optional.
- **Rendering** — ✅ HTML über das offizielle foglio di stile (Stylesheet) in 0.4.0
  ausgeliefert (`StylesheetRenderer`); PDF-Ausgabe (wkhtmltopdf/dompdf darauf aufsetzend)
  noch offen.
- **Weitere nicht unterstützte XML-Blöcke:** `ScontoMaggiorazione` auf Dokumentebene
  (✅ Positionsebene in 0.3.0 hinzugefügt), `Allegati`, `DatiDDT`, `DatiContratto`, `Arrotondamento`,
  `AltriDatiGestionali`, `DatiVeicoli`, stabile organizzazione (Betriebsstätte) /
  rappresentante fiscale (Fiskalvertreter), mehrere Bodies je Datei (lotto di fatture,
  Rechnungslos). (`Causale` ✅ in 0.2.0 hinzugefügt.)

## Lücken in der Testabdeckung

0.3.0 brachte die Suite auf 39 Tests inkl. XSD-Validierung jeder gebauten Rechnung
(sofern das Schema lokal vorliegt), Builder-Randfälle, `NotificationParser`,
`PecTransport` (gemocktes SMTP), `NumeratoreService` auf SQLite und
`MimeAttachmentExtractor` mit einer verschachtelten PEC-Nachricht. 0.4.1 ergänzte
`OpenapiClient`-Tests mit gemocktem Guzzle-Handler (Retry/Backoff, 4xx vs. 5xx,
Token-Scrubbing) — insgesamt 57 Tests. Noch fehlend: `SmtpClient`-/`ImapClient`-
Protokolltests und Endpoint-Tests des Microservice (Auth-Middleware, Routen).
