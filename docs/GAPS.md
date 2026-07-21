# Lacune — cosa manca o non funziona

🇮🇹 Italiano · 🇬🇧 [English](GAPS.en.md) · 🇩🇪 [Deutsch](GAPS.de.md)

> Risultati di una revisione completa del codice sorgente + test live (2026-07-20).
> Ordinati per gravità. **Aggiornato dopo il rilascio 0.2.0** — le voci risolte sono
> marcate ✅ e mantenute per tracciabilità. Documenti complementari:
> [CAPABILITIES.md](CAPABILITIES.md), [ROADMAP.md](ROADMAP.md).

## Critico

### ✅ 1. Il microservizio non ha autenticazione — RISOLTO in 0.2.0 (middleware X-Api-Key, rifiuta di avviarsi non configurato)
`public/index.php` espone `POST /fattura/send` — un'**operazione fiscale
irreversibile** — senza API key, senza allowlist, niente. Chiunque raggiunga la
porta può trasmettere fatture con il vostro token Openapi, e `/fattura/numero`
permette di bruciare numeri progressivi (i buchi di numerazione sono un problema
in caso di verifica fiscale).
**Correzione:** richiedere un header `X-Api-Key` verificato contro una variabile
d'ambiente; rifiutare di attivare la route `/fattura/send` quando la chiave non è impostata.

### ✅ 2. Lacuna di conformità normativa: manca `DatiBollo` — RISOLTO in 0.2.0 (regola automatica €2 > €77,47 + override)
Le fatture esenti/fuori campo (Natura N2–N6, ad es. il caso d'uso stesso della
libreria per le quote associative) sopra **€77,47** richiedono per legge l'imposta
di bollo di €2, espressa come
`<DatiBollo><BolloVirtuale>SI</BolloVirtuale></DatiBollo>`. Il builder non è in grado
di emetterla affatto, quindi qualsiasi fattura esente sopra la soglia è fiscalmente errata.

### ✅ 3. Solo `TD01` — nessuna nota di credito — RISOLTO in 0.2.0 (enum completo TD01–TD29)
`TipoDocumento` è cablato nel codice. Senza **TD04 (nota di credito)** non esiste un
modo legale di correggere una fattura inviata — un blocco assoluto per qualsiasi
deployment reale. Mancano anche TD05 (nota di debito), TD24 (fattura differita),
TD16–TD19 (reverse charge / integrazioni, necessarie dal cambio esterometro 2022)
e TD26.

### ✅ 3b. Punta a una versione di schema scaduta — RISOLTO in 0.2.0 (XSD 1.2.3, fallback 1.2.2)
Il codice fissa `FatturaPA_v1.2.2.xsd` — **valido solo fino al 31 marzo 2025**. Lo
schema corrente è **1.2.3** (specifiche tecniche 1.9, in vigore dal 1° aprile 2025;
revisione 1.9.1 utilizzabile dal 15 maggio 2026), che ha aggiunto `TD29` e il
`RegimeFiscale` `RF20`. Il nome file/la costante dello XSD e i futuri set di enum
devono passare alla 1.2.3.

## Alta — blocca casi d'uso italiani comuni

### ✅ 4. Il cedente deve essere un'azienda — RISOLTO in 0.2.0 (nome/cognome supportati)
Il builder scrive solo `<Denominazione>` per il fornitore; una `denominazione`
mancante produce un *warning* PHP e genera silenziosamente XML rotto (verificato).
**Ditte individuali / freelance / forfettari — la platea più numerosa di emittenti
di fatture elettroniche in Italia dall'obbligo forfettari del 2024 — non possono
essere rappresentati** (servono `Nome`/`Cognome`, come già supportato per il
cessionario).

### ✅ 5. Manca `DatiPagamento` — RISOLTO in 0.2.0
Nessun IBAN, condizioni di pagamento o `ModalitaPagamento` (MP01–MP23). La maggior
parte dei clienti B2B e praticamente tutti gli enti PA si aspettano i dati di
pagamento in fattura.

### ✅ 6. Nessun campo specifico PA — RISOLTO in 0.2.0 (esigibilita_iva S, CIG/CUP via ordine_acquisto)
- `EsigibilitaIVA` cablata a `I` — **split payment (`S`)**, standard per la PA, impossibile.
- Nessun `DatiOrdineAcquisto` / `CodiceCIG` / `CodiceCUP` — gli enti PA rifiutano di norma le fatture senza CIG/CUP.
Quindi, pur emettendo FPA12, la fatturazione reale verso la PA non funziona end to end.

### ✅ 7. Nessuna ritenuta d'acconto / cassa previdenziale — RISOLTO in 0.3.0
I professionisti (commercialisti, ingegneri, avvocati…) hanno bisogno di
`DatiRitenuta` e `DatiCassaPrevidenziale`. In combinazione con il #4 questo esclude
l'intero segmento professionisti/freelance. *(0.3.0: blocchi `ritenuta` + `cassa`
con importi calcolati automaticamente, integrazione nel riepilogo, controllo di
coerenza 00411.)*

### ✅ 8. Default di `RiferimentoNormativo` errato — RISOLTO in 0.2.0 (tabella di default per natura)
Ogni riepilogo esente ha come default *"Esente art.10 DPR 633/72"*, che è errato
per la maggior parte dei codici Natura (N1, N2.2 forfettario, N3.x esportazioni,
N6.x reverse charge…). Serve una tabella di default per natura (o renderlo
obbligatorio quando natura è impostata).

## Media — correttezza e robustezza

### ✅ 9. `PrezzoUnitario` troncato — RISOLTO in 0.2.0 (emessa la precisione completa, sicuro rispetto a SdI 00423)
Verificato: `prezzo_unitario = 0.333, quantita = 3` emette `PrezzoUnitario 0.33` ma
`PrezzoTotale 1.00` (calcolato da 0.333). Il controllo SdI **00423** verifica
`PrezzoTotale = PrezzoUnitario × Quantita` — 0.33 × 3 = 0.99 vs 1.00 sopravvive solo
per tolleranza di arrotondamento; più decimali o quantità maggiori saranno
**scartati dallo SdI**. Lo XSD ammette fino a 8 decimali: emettere la precisione
reale invece di `number_format(…, 2)`.

### ✅ 10. La gestione dei campi mancanti è fatta di warning PHP — RISOLTO in 0.2.0 (validazione con tutti gli errori in una volta)
`indirizzo`/`cap`/`comune`/`denominazione` assenti producono warning
undefined-array-key e generano *silenziosamente* XML non valido (verificato). Il
controllo delle chiavi obbligatorie copre solo le chiavi di primo livello. Serve un
vero passaggio di validazione a livello di campo con messaggi di errore leggibili
(idealmente mappati sui codici di errore SdI).

### ✅ 11. `progressivo_invio` non validato — RISOLTO in 0.2.0
I valori forniti dall'utente non vengono verificati contro `[A-Za-z0-9]{1,10}`; un
valore non valido fallisce solo in fase XSD/SdI.

### ✅ 12. Numerazione solo MariaDB/MySQL — RISOLTO in 0.3.0 (PostgreSQL + SQLite ≥3.35 via UPSERT…RETURNING)
L'upsert con `LAST_INSERT_ID()` non funziona su PostgreSQL/SQLite. Va bene per il
deployment attuale, ma è una limitazione reale per un pacchetto general-purpose (il
mondo Laravel è fortemente Postgres). Dettaglio residuo: `date('Y')` usa il fuso
orario del server (caso limite a cavallo di Capodanno).

## Superficie di prodotto mancante (non bug)

- **Ciclo passivo** — ✅ rilasciato in 0.4.0 per il canale PEC: le fatture in
  ingresso `.xml` e `.xml.p7m` vengono raccolte dalla casella, estratte dal p7m e
  parsate in array per la contabilità. La ricezione via canale provider (webhook)
  resta aperta.
- **Gestione delle notifiche** — ✅ parzialmente coperta in 0.2.0: `NotificationParser`
  parsa offline tutti e sei i tipi di ricevuta ed esistono `/fattura/status/{id}` +
  `/fattura/notifica`; ✅ polling IMAP della casella PEC rilasciato in
  0.3.0 (`PecInboxReader`, client IMAP proprio + parsing MIME della busta PEC); ✅ modello di stato del ciclo di vita persistito rilasciato in 0.4.0 (`InvoiceStore`); manca
  ancora: l'ingestione dei webhook dei provider.
- **Trasporti** — ✅ trasporto PEC aggiunto in 0.2.0 (canale autosufficiente);
  gli adapter Aruba / A-Cube / Invoicetronic / SDICoop diretto restano aperti.
- **Nessuna firma digitale (CAdES/XAdES)** — va bene finché firma il provider, ma
  va documentato per ciascun provider; l'accreditamento diretto allo SdI la richiederebbe.
- **Conservazione sostitutiva** — ✅ documentata in 0.2.0: il servizio gratuito
  dell'Agenzia delle Entrate ("Fatture e Corrispettivi") è il percorso raccomandato
  senza dipendenze; la conservazione lato provider resta opzionale.
- **Rendering** — ✅ HTML tramite il foglio di stile ufficiale rilasciato in 0.4.0
  (`StylesheetRenderer`); l'output PDF (wkhtmltopdf/dompdf sopra di esso) resta aperto.
- **Altri blocchi XML non supportati:** `ScontoMaggiorazione` a livello documento
  (✅ a livello riga aggiunto in 0.3.0), `Allegati`, `DatiDDT`, `DatiContratto`, `Arrotondamento`,
  `AltriDatiGestionali`, `DatiVeicoli`, stabile organizzazione / rappresentante
  fiscale, più body per file (lotto di fatture). (`Causale` ✅ aggiunta in 0.2.0.)

## Lacune nella copertura dei test

La 0.3.0 ha portato la suite a 39 test, incl. validazione XSD di ogni fattura
generata (quando lo schema è presente in locale), casi limite del builder,
`NotificationParser`, `PecTransport` (SMTP mockato), `NumeratoreService` su SQLite e
`MimeAttachmentExtractor` con un messaggio PEC annidato. La 0.4.1 ha aggiunto
test di `OpenapiClient` con handler Guzzle mockato (retry/backoff, 4xx vs 5xx,
rimozione del token) — 57 test in totale. Mancano ancora: test di protocollo per
`SmtpClient`/`ImapClient` e test degli endpoint del microservizio (middleware di
autenticazione, route).
