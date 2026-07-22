# Roadmap — Marktpositionierung und Plattformstrategie

🇮🇹 [Italiano](ROADMAP.md) · 🇬🇧 [English](ROADMAP.en.md) · 🇩🇪 Deutsch

> Basierend auf einem tiefen Markt-Scan (2026-07-20) des italienischen
> E-Invoicing-Ökosystems: SdI-API-Anbieter, konkurrierende PHP-Bibliotheken,
> Compliance-Anforderungen (specifiche tecniche 1.9/1.9.1) und Nachfrage nach
> Plattform-Integrationen.
> Begleitdokumente: [CAPABILITIES.de.md](CAPABILITIES.de.md), [GAPS.de.md](GAPS.de.md).

## Wo dieses Paket gewinnen kann

Das PHP-Ökosystem zerfällt in drei sich gegenseitig ausschließende Lager, und keines
deckt das Gesamtbild ab:

1. **Reine XML-Builder/-Validatoren, kein Transport** — `deved/fattura-elettronica`
   (84★, 137k Downloads, aktiv), `fatturaelettronicaphp/fattura-elettronica`
   (51★, 63k, verlangsamt), `slam/php-validatore-fattura-elettronica` (nur Validierung).
2. **Direktes SdI-Plumbing, das eine staatliche Akkreditierung erfordert** — `taocomp/php-e-invoice-it`
   (seit 2022 inaktiv), `italia/fatturapa-php-sdk` (2018 aufgegeben).
3. **Single-Vendor-SaaS-Clients mit fest verdrahtetem Transport** — Fattura24-,
   Invoicetronic-, fattura-elettronica-api.it-SDKs.

**Niemand bietet Builder + Validierung + austauschbaren Transport.** Genau diese
Architektur hat dieses Paket bereits (`SdiTransport`-Interface). Allein bei der
XML-Erzeugung mit deved zu konkurrieren, ist ein verlorener Zug; die dauerhaften
Differenzierungsmerkmale sind:

- **Transport-Abstraktion mit mehreren Adaptern** (First-to-Market: Openapi.com
  hat kein offizielles PHP-SDK).
- **SdI-Benachrichtigungs-Lifecycle** (RC/NS/MC + PA-only NE/DT/AT), normalisiert über
  Anbieter hinweg — kein PHP-Paket leistet das.
- **Laravel-Bridge** — die bestehenden Laravel-Wrapper kommen zusammen auf <100 Downloads
  und sind tot; italienische PHP-Agenturen setzen stark auf Laravel. Null Konkurrenz.
- **CiviCRM-Extension** — es existiert überhaupt keine FatturaPA-Extension, obwohl
  italienische Non-Profits seit 2019/2024 unter der Pflicht stehen. Greenfield, und es
  ist das Heimspielfeld dieses Pakets (gemeinnützige Vereine).
- Der einbettbare Kern für den fragmentierten Plugin-Markt: italienische
  WooCommerce-E-Rechnungs-Plugins (4.000/800/400… aktive Installationen),
  PrestaShop-Module für 120–168 €, Magento-Module — jedes davon baut derzeit XML von
  Hand oder verdrahtet ein einzelnes SaaS fest.

## Transport-Adapter — Prioritätsreihenfolge

| Priorität | Anbieter | Warum | Anmerkungen |
|---|---|---|---|
| ✅ erledigt | **Openapi.com** | Rohes XML rein, Pay-per-Use (0,022–0,07 €/Rechnung), Sandbox, offene Doku | Signatur (0,09 €) und gesetzliche Archivierung (0,105 €) sind separate Endpoint-Varianten (`/invoices_signature`, `/invoices_legal_storage`) — als Optionen anbieten |
| 1 | **Invoicetronic** | Rohes XML, 0,02–0,10 €/Tx, kostenlose permanente Sandbox, API-Key-Auth, offene Doku | Kein conservazione-Angebot (Langzeitarchivierung) — dokumentieren |
| 2 | **Aruba** | Rohes XML (`/invoice/upload` signiert automatisch, `/uploadSigned` für p7m), conservazione ab 29,90 €/Jahr inklusive | OAuth2 Password Grant, 30-Minuten-Tokens, Rate-Limit ~30 Uploads/min; volle API-Automatisierung ist die Premium-Stufe für ~600 €/Jahr |
| 3 | **A-Cube** | Developer-first, JWT (24 h), Webhooks mit Secret-Token, Legal-Storage-API | Preise nur über den Vertrieb |
| 4 | **Fatture in Cloud** | Riesige Installationsbasis (TeamSystem) | ⚠️ **Kann kein externes XML annehmen** — der Adapter muss das Rechnungs-Array auf deren Dokumentmodell mappen, statt gebautes XML zu senden. Andere Adapter-Form: `DocumentModelTransport` |
| ✅ erledigt | **PEC-Kanal** | **Autarkie-Anforderung: kein Drittanbieterdienst, nur das eigene PEC-Postfach (zertifizierte E-Mail)** | Ausgeliefert in 0.2.0 (`PecTransport` + eigener `SmtpClient`); asynchrone E-Mail, ≤5 MB/Rechnung; conservazione über den kostenlosen AdE-Dienst; B2B benötigt keine Signatur |
| als Nächstes (Unabhängigkeits-Track) | **Direktes SDICoop** | Kostenlos pro Rechnung, vollständig unabhängig | SOAP+MTOM, Mutual-TLS-Zertifikate von Sogei, Akkreditierungszeremonie, Signatur (CAdES/XAdES) und Archivierung in Eigenregie — der Endzustand der „Build it ourselves“-Strategie |
| überspringen | Zucchetti Digital Hub, TS Digital/Agyo, InfoCert | Doku hinter Vertrieb/NDA — ein Open-Source-Adapter ist nicht wartbar | Deren Nutzer können `SdiTransport` privat implementieren |

Design-Implikationen aus dem Anbieter-Survey:

- **Credential-Vielfalt** (statischer Token / OAuth2 / JWT-Login / API-Key / mTLS / PEC)
  → eine kleine Credential-Provider-Abstraktion je Adapter einführen.
- **Capability-Flags** am Interface: `supportsSignature()`,
  `supportsLegalStorage()`, `acceptsRawXml()` — Signatur und conservazione sind
  Adapter-Fähigkeiten, keine Selbstverständlichkeiten.
- **Zu normalisierende Webhook-Eigenheiten**: die Openapi-Callback-Konfiguration
  *überschreibt* bei jedem Aufruf; Fatture-in-Cloud-Webhooks enthalten nur die
  Dokument-ID (erneutes Abrufen nötig); Aruba kann an von *dir* gehostete Callbacks
  pushen; A-Cube authentifiziert mit einem Secret-Token.

## Compliance-Backlog (spezifikationsgetrieben)

Ziel: **XSD 1.2.3 / specifiche tecniche 1.9.1** (aktuell seit 1. April 2025 /
15. Mai 2026). Konkret:

1. **Enums als First-Class-Konstanten** mit Validierung: TipoDocumento TD01–TD09,
   TD16–TD29 (TD29 neu 2025); RegimeFiscale RF01–RF20 (RF20 neu); Natura N1,
   N2.1–N2.2, N3.1–N3.6, N4, N5, N6.1–N6.9, N7 (Sub-Codes seit 2021 verpflichtend);
   ModalitaPagamento MP01–MP23; TipoRitenuta RT01–RT06; TipoCassa TC01–TC22;
   EsigibilitaIVA I/D/S.
2. **Rule-Engine für semantische SdI-Prüfungen** (die 004xx-Familie) — mindestens:
   Natura verpflichtend genau dann, wenn aliquota = 0 (und umgekehrt); `PrezzoTotale = PrezzoUnitario × Quantita`
   (Prüfung 00423 — den Trunkierungsbug fixen, GAPS #9); Ritenuta-Flag auf Positionen ⇒
   `DatiRitenuta` vorhanden (00411); eindeutige numero je Jahr/tipo (00404).
3. **DatiBollo-Automatik**: wenn die Nicht-USt-Summe (N1/N2.x/N3.x-befreit/N4)
   77,47 € überschreitet, automatisch `BolloVirtuale=SI` (+ `ImportoBollo 2.00`)
   ausgeben, mit Override.
4. **Forfettario-Preset** (Pauschalbesteuerungs-Profil): RF19 + N2.2 + keine ritenuta
   (Quellensteuer) + Bollo-Regel — als Einzeiler-Profil, da forfettari (verpflichtet seit
   1. Januar 2024) die größte unterversorgte Ausstellergruppe sind. Erfordert
   `Nome`/`Cognome`-Unterstützung für den cedente (GAPS #4).
5. **PA-Preset**: FPA12 + `EsigibilitaIVA=S` (Split Payment) + CIG/CUP in
   `DatiOrdineAcquisto`/`DatiContratto` (die PA darf ohne verpflichtenden CIG rechtlich
   nicht zahlen).
6. **Freiberufler-Preset**: `DatiRitenuta` (RT01/RT02, CausalePagamento) +
   `DatiCassaPrevidenziale` (TC01–TC22, inkl. der INPS-4-%-rivalsa-Randfälle).
7. **DatiPagamento** (TP01–TP03, MP-Codes, IBAN, scadenze/Fälligkeiten).

## Backlog Benachrichtigungen / ciclo passivo

Reines Senden deckt nur die halbe gesetzliche Pflicht ab — jedes italienische
USt-Subjekt *empfängt* auch Eingangsrechnungen über das SdI (ciclo passivo,
Eingangsrechnungszyklus), und alle untersuchten Anbieter verkaufen
Senden+Empfangen als ein Produkt.

1. **Benachrichtigungs-Taxonomie** als Value Object: RC (consegna, Zustellung), NS
   (scarto, Ablehnung; 5-Tage-Fenster, Neuversand mit derselben numero innerhalb von
   5 Tagen), MC (mancata consegna, fehlgeschlagene Zustellung) für den B2B-Flow;
   NE/EC01/EC02, DT, AT zusätzlich für den PA-Flow. Verzweigung anhand von
   `FormatoTrasmissione`.
2. **`handleWebhook(payload)` + `pollNotifications()`** am Transport-Contract
   (beides ist nötig: Webhooks sind der dominante Mechanismus, Polling der Fallback).
3. **Abruf eingehender Rechnungen + p7m-Unwrap** (ciclo passivo) als
   Phase-2-Transportfähigkeit.
4. Persistierte **Rechnungs-Lifecycle-State-Machine** (gebaut → gesendet → zugestellt/
   scartata/mancata-consegna → …), in die sich Apps einklinken können.

## Vorgeschlagener Release-Plan

> Richtung festgelegt am 2026-07-20: **Unabhängigkeit zuerst** — selbstgebaute Kanäle
> bevorzugen (PEC jetzt, direktes SDICoop später) statt Anbieter-Adapter;
> Anbieter-Adapter bleiben optionale Bequemlichkeiten.

- ✅ **v0.2 — vertrauenswürdiger Kern** (ausgeliefert): Microservice-Auth, XSD 1.2.3,
  bollo, TD01–TD29, cedente als Person, Validierung auf Feldebene,
  Dezimalpräzisions-Fix, DatiPagamento, Split Payment + CIG/CUP,
  **PEC-Transport + Notification-Parser**.
- ✅ **v0.3 — Freiberufler + geschlossener PEC-Loop** (ausgeliefert): ritenuta d'acconto
  (Quellensteuer), cassa previdenziale (berufsständische Vorsorgekasse),
  Positionsrabatte, PEC-Inbox-Polling (eigener IMAP-Client + Parsing der
  PEC-Envelope-MIME), portable Nummerierung (PostgreSQL/SQLite).
- ✅ **v0.4 — Lifecycle + ciclo passivo + Rendering** (ausgeliefert): `InvoiceStore`
  State-Machine, Einsammeln eingehender Rechnungen aus dem PEC-Postfach (p7m-Unwrap +
  Parser), HTML-Rendering über das offizielle foglio di stile (Stylesheet).
- **v0.5 — Distribution**: Laravel-Bridge (Facade, Config, Queue-freundliche Webhooks),
  Packagist-Veröffentlichung, CiviCRM-Extension-Skeleton.
- **v1.0 — ciclo passivo** + PDF-Rendering über das foglio di stile der AdE.

## Horizont: ViDA / EN 16931

FatturaPA/SdI bleibt für die italienische Inlandsfakturierung mindestens bis 2030
maßgeblich; Italien muss bis 2035 auf den EU-Standard konvergieren (EN 16931 / Peppol,
ViDA-Paket verabschiedet am 11. März 2025), mit innergemeinschaftlichem digitalem
Reporting ab 1. Juli 2030. Jetzt ist kein Handeln nötig, aber das Eingabe-Array sollte
ein *semantisches Modell* bleiben (kein XML-Spiegel), damit später ein
EN-16931-/Peppol-BIS-Serializer neben `XmlBuilder` stehen kann.
