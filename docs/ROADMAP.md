# Roadmap — posizionamento di mercato e strategia di piattaforma

🇮🇹 Italiano · 🇬🇧 [English](ROADMAP.en.md) · 🇩🇪 [Deutsch](ROADMAP.de.md)

> Basata su un'analisi approfondita di mercato (2026-07-20) dell'ecosistema italiano
> della fatturazione elettronica: provider API SdI, librerie PHP concorrenti,
> requisiti di conformità (specifiche tecniche 1.9/1.9.1) e domanda di integrazione
> nelle piattaforme. Documenti complementari: [CAPABILITIES.md](CAPABILITIES.md), [GAPS.md](GAPS.md).

## Dove questo pacchetto può vincere

L'ecosistema PHP si divide in tre campi mutuamente esclusivi, e nessuno copre il
quadro completo:

1. **Puri builder/validatori XML, senza trasporto** — `deved/fattura-elettronica`
   (84★, 137k download, attivo), `fatturaelettronicaphp/fattura-elettronica`
   (51★, 63k, in rallentamento), `slam/php-validatore-fattura-elettronica` (solo validazione).
2. **Plumbing SdI diretto che richiede l'accreditamento governativo** — `taocomp/php-e-invoice-it`
   (dormiente dal 2022), `italia/fatturapa-php-sdk` (abbandonato nel 2018).
3. **Client SaaS mono-vendor con il trasporto cablato** — gli SDK di Fattura24,
   Invoicetronic, fattura-elettronica-api.it.

**Nessuno offre builder + validazione + trasporto intercambiabile.** È esattamente
l'architettura che questo pacchetto ha già (interfaccia `SdiTransport`). Competere
con deved sulla sola generazione XML è una mossa perdente; i differenziatori
durevoli sono:

- **Astrazione del trasporto con più adapter** (first-to-market: Openapi.com
  non ha un SDK PHP ufficiale).
- **Ciclo di vita delle notifiche SdI** (RC/NS/MC + NE/DT/AT solo PA) normalizzato
  tra i provider — nessun pacchetto PHP lo fa.
- **Bridge Laravel** — i wrapper Laravel esistenti totalizzano <100 download e sono
  morti; le agenzie PHP italiane sono fortemente su Laravel. Concorrenza zero.
- **Estensione CiviCRM** — non esiste alcuna estensione FatturaPA nonostante il
  non profit italiano sia sotto obbligo dal 2019/2024. Terreno vergine, ed è il
  terreno di elezione di questo pacchetto (associazioni no-profit).
- Essere il core integrabile per il frammentato mercato dei plugin: plugin
  WooCommerce per la fattura elettronica italiana (4.000/800/400… installazioni
  attive), moduli PrestaShop venduti a €120–168, moduli Magento — ognuno oggi
  genera XML a mano o cabla un singolo SaaS.

## Adapter di trasporto — ordine di priorità

| Priorità | Provider | Perché | Note |
|---|---|---|---|
| ✅ fatto | **Openapi.com** | XML grezzo in ingresso, pay-per-use (€0,022–0,07/fattura), sandbox, documentazione aperta | Firma (€0,09) e conservazione a norma (€0,105) sono varianti di endpoint separate (`/invoices_signature`, `/invoices_legal_storage`) — esporle come opzioni |
| 1 | **Invoicetronic** | XML grezzo, €0,02–0,10/tx, sandbox permanente gratuita, autenticazione API key, documentazione aperta | Nessuna offerta di conservazione — documentarlo |
| 2 | **Aruba** | XML grezzo (`/invoice/upload` firma automaticamente, `/uploadSigned` per p7m), conservazione inclusa da €29,90/anno | OAuth2 password grant, token da 30 min, rate limit ~30 upload/min; l'automazione API completa è il tier Premium da ~€600/anno |
| 3 | **A-Cube** | Developer-first, JWT (24h), webhook con secret token, Legal Storage API | Prezzi solo tramite reparto vendite |
| 4 | **Fatture in Cloud** | Enorme base installata (TeamSystem) | ⚠️ **Non può accettare XML esterno** — l'adapter deve mappare l'array della fattura sul loro modello documentale invece di inviare l'XML generato. Forma di adapter diversa: `DocumentModelTransport` |
| ✅ fatto | **Canale PEC** | **Requisito di autosufficienza: nessun servizio di terze parti, solo la propria casella PEC** | Rilasciato in 0.2.0 (`PecTransport` + `SmtpClient` proprio); email asincrona, ≤5 MB/fattura; conservazione tramite il servizio gratuito AdE; il B2B non richiede firma |
| prossimo (traccia indipendenza) | **SDICoop diretto** | Gratis per fattura, totalmente indipendente | SOAP+MTOM, certificati mutual-TLS da Sogei, cerimonia di accreditamento, firma (CAdES/XAdES) e archiviazione in proprio — lo stato finale della strategia "costruiamocelo da soli" |
| saltare | Zucchetti Digital Hub, TS Digital/Agyo, InfoCert | Documentazione dietro vendite/NDA — impossibile mantenere un adapter open source | I loro utenti possono implementare `SdiTransport` privatamente |

Implicazioni progettuali emerse dal censimento dei provider:

- **Diversità di credenziali** (token statico / OAuth2 / login JWT / API key / mTLS / PEC)
  → introdurre una piccola astrazione credential-provider per adapter.
- **Capability flag sull'interfaccia**: `supportsSignature()`,
  `supportsLegalStorage()`, `acceptsRawXml()` — firma e conservazione sono
  capacità dell'adapter, non scontate.
- **Stranezze dei webhook da normalizzare**: la configurazione callback di Openapi
  *sovrascrive* a ogni chiamata; i webhook di Fatture in Cloud portano solo l'ID del
  documento (serve un re-fetch); Aruba può fare push verso callback ospitati da *voi*;
  A-Cube autentica con un secret token.

## Backlog di conformità (guidato dalle specifiche)

Target: **XSD 1.2.3 / specifiche tecniche 1.9.1** (in vigore dal 1° aprile 2025 /
15 maggio 2026). In concreto:

1. **Enum come costanti di prima classe** con validazione: TipoDocumento TD01–TD09,
   TD16–TD29 (TD29 nuovo 2025); RegimeFiscale RF01–RF20 (RF20 nuovo); Natura N1,
   N2.1–N2.2, N3.1–N3.6, N4, N5, N6.1–N6.9, N7 (sotto-codici obbligatori dal 2021);
   ModalitaPagamento MP01–MP23; TipoRitenuta RT01–RT06; TipoCassa TC01–TC22;
   EsigibilitaIVA I/D/S.
2. **Motore di regole per i controlli semantici SdI** (la famiglia 004xx) — come minimo:
   Natura obbligatoria se e solo se aliquota = 0 (e viceversa); `PrezzoTotale = PrezzoUnitario × Quantita`
   (controllo 00423 — correggere il bug di troncamento, GAPS #9); flag ritenuta sulle righe ⇒
   `DatiRitenuta` presente (00411); numero univoco per anno/tipo (00404).
3. Automazione di **DatiBollo**: quando il totale non soggetto a IVA (N1/N2.x/N3.x
   esenti/N4) supera €77,47, emettere automaticamente `BolloVirtuale=SI`
   (+ `ImportoBollo 2.00`), con override.
4. **Preset forfettario**: RF19 + N2.2 + nessuna ritenuta + regola del bollo — come
   profilo one-liner, dato che i forfettari (obbligati dal 1° gennaio 2024) sono il
   gruppo di emittenti più numeroso e meno servito. Richiede il supporto di
   `Nome`/`Cognome` per il cedente (GAPS #4).
5. **Preset PA**: FPA12 + `EsigibilitaIVA=S` (split payment) + CIG/CUP in
   `DatiOrdineAcquisto`/`DatiContratto` (la PA non può legalmente pagare senza il CIG
   quando richiesto).
6. **Preset professionisti**: `DatiRitenuta` (RT01/RT02, CausalePagamento) +
   `DatiCassaPrevidenziale` (TC01–TC22, incl. i casi limite della rivalsa INPS 4%).
7. **DatiPagamento** (TP01–TP03, codici MP, IBAN, scadenze).

## Backlog notifiche / ciclo passivo

Il solo invio copre metà dell'obbligo di legge — ogni soggetto IVA italiano
*riceve* anche le fatture di acquisto tramite SdI, e tutti i provider censiti
vendono invio+ricezione come un unico prodotto.

1. **Tassonomia delle notifiche** come value object: RC (consegna), NS (scarto,
   finestra di 5 giorni, reinvio con lo stesso numero entro 5 giorni), MC (mancata
   consegna) per il flusso B2B; NE/EC01/EC02, DT, AT in aggiunta per il flusso PA.
   Diramazione su `FormatoTrasmissione`.
2. **`handleWebhook(payload)` + `pollNotifications()`** sul contratto di trasporto
   (servono entrambi: i webhook sono il meccanismo dominante, il polling è il fallback).
3. **Recupero delle fatture in ingresso + estrazione p7m** (ciclo passivo) come
   capacità di trasporto di fase 2.
4. **Macchina a stati del ciclo di vita della fattura** persistita (generata → inviata →
   consegnata/scartata/mancata-consegna → …) a cui le applicazioni possano agganciarsi.

## Piano di rilascio suggerito

> Direzione fissata il 2026-07-20: **prima l'indipendenza** — preferire canali
> costruiti in proprio (PEC ora, SDICoop diretto poi) rispetto agli adapter dei
> provider; gli adapter dei provider restano comodità opzionali.

- ✅ **v0.2 — core affidabile** (rilasciata): autenticazione del microservizio, XSD 1.2.3,
  bollo, TD01–TD29, cedente come persona fisica, validazione a livello di campo,
  correzione della precisione decimale, DatiPagamento, split payment + CIG/CUP,
  **trasporto PEC + parser delle notifiche**.
- ✅ **v0.3 — professionisti + loop PEC chiuso** (rilasciata): ritenuta d'acconto,
  cassa previdenziale, sconti di riga, polling della casella PEC (client IMAP
  proprio + parsing MIME della busta PEC), numerazione portabile (PostgreSQL/SQLite).
- ✅ **v0.4 — ciclo di vita + ciclo passivo + rendering** (rilasciata): macchina a
  stati `InvoiceStore`, raccolta delle fatture in ingresso dalla casella PEC
  (estrazione p7m + parser), rendering HTML tramite il foglio di stile ufficiale.
- **v0.5 — distribuzione**: bridge Laravel (facade, config, webhook queue-friendly),
  pubblicazione su Packagist, scheletro dell'estensione CiviCRM.
- **v1.0 — ciclo passivo** + rendering PDF tramite il foglio di stile AdE.

## Orizzonte: ViDA / EN 16931

FatturaPA/SdI resta il riferimento per la fatturazione domestica italiana almeno
fino al 2030; l'Italia dovrà convergere sullo standard UE (EN 16931 / Peppol,
pacchetto ViDA adottato l'11 marzo 2025) entro il 2035, con il digital reporting
intra-UE dal 1° luglio 2030. Nessuna azione necessaria ora, ma mantenere l'array di
input come *modello semantico* (non uno specchio dell'XML) così che un serializzatore
EN 16931/Peppol BIS possa affiancare `XmlBuilder` in futuro.
