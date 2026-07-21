# Funzionalità — cosa funziona oggi (plug and play)

🇮🇹 Italiano · 🇬🇧 [English](CAPABILITIES.en.md) · 🇩🇪 [Deutsch](CAPABILITIES.de.md)

> Audit dello stato di `alpsplanner/fatturapa`, verificato eseguendo il codice su PHP 8.3
> (test, validazione XSD contro lo schema ufficiale e un test di concorrenza
> live su MariaDB). Aggiornato alla **0.2.0** (vedi [CHANGELOG](../CHANGELOG.md)):
> XSD 1.2.3, TD01–TD29, bollo, split payment, CIG/CUP, DatiPagamento, cedente
> persona fisica, validazione a livello di campo, **trasporto PEC** (autosufficiente,
> nessun servizio di terze parti), parser delle notifiche SdI, microservizio protetto
> da API key. Documenti complementari: [GAPS.md](GAPS.md) per ciò che manca,
> [ROADMAP.md](ROADMAP.md) per la direzione da dare al pacchetto.

## Funzionamento verificato

### 1. Generazione XML FatturaPA (`XmlBuilder`)

`build()` produce XML che **valida contro lo schema ufficiale
`Schema_del_file_xml_FatturaPA_v1.2.2.xsd`** di fatturapa.gov.it per tutti e tre
gli archetipi supportati:

| Caso | Formato | Verificato |
|---|---|---|
| B2C, persona fisica con codice fiscale, riga esente (Natura N4) | FPR12 | ✅ valido XSD |
| B2B, azienda con partita IVA + codice destinatario, IVA 22% | FPR12 | ✅ valido XSD |
| PA, ente pubblico con codice destinatario di 6 caratteri | FPA12 | ✅ valido XSD |

Verificato inoltre:

- **Escaping** — `&`, `<`, `>` in descrizioni/nomi sopravvivono al round-trip (`htmlspecialchars(ENT_XML1)` prima di `createElement`).
- **Calcolo IVA** — aggregazione `DatiRiepilogo` per (aliquota, natura), `Imposta` e `ImportoTotaleDocumento` calcolati correttamente (coperto da unit test, 7/7 verdi).
- **Default di CodiceDestinatario** — `0000000` per FPR12, `999999` per FPA12; `PECDestinatario` emesso solo nel caso previsto dalla norma (FPR12 + `0000000` + pec presente).
- **Righe negative** (sconto/rimborso come prezzo negativo) superano la validazione XSD.
- L'input è un semplice array PHP — nessun tipo di framework, serve solo `ext-dom`. Realmente drop-in per CiviCRM, Laravel, Symfony o PHP puro.

Superficie di input supportata (vedi docblock della classe): `tipo_documento` (`fattura_b2b` | `fattura_b2c` | `fattura_pa`), `numero`, `data`, `progressivo_invio` (auto-random se assente), cedente (solo azienda), cessionario (azienda o persona fisica con nome/cognome), righe con `descrizione`/`quantita`/`prezzo_unitario`/`aliquota_iva`/`natura`, fallback per riga di `riferimento_normativo` sui riepiloghi esenti.

### 2. Validazione XSD (`XmlBuilder::validate()`)

Funziona una volta collocato lo XSD ufficiale in `resources/xsd/FatturaPA_v1.2.2.xsd`
(deliberatamente non incluso nel pacchetto — vedi `resources/xsd/README.md`). Restituisce un
`string[]` pulito di errori libxml; array vuoto = valido. Senza il file degrada a un
singolo messaggio informativo invece di fallire.

### 3. Numerazione progressiva (`NumeratoreService`)

- Formato `YYYY/00042` e `YYYY/00042/SEZ` per anno + sezionale.
- **Concorrenza verificata live**: 20 processi paralleli × 10 incrementi contro
  MariaDB hanno prodotto esattamente 200 senza buchi né duplicati (l'upsert
  `INSERT IGNORE` seed + `UPDATE … LAST_INSERT_ID()` è privo di race condition).
- `ensureTable()` inizializza la tabella; il nome tabella è protetto contro injection.
- Funziona con qualsiasi PDO iniettato — **solo MariaDB/MySQL** (usa `LAST_INSERT_ID()`).

### 4. Trasporto SdI (`OpenapiClient` + contratto `SdiTransport`)

- Interfaccia `SdiTransport` pulita (`sendInvoice`, `getInvoiceStatus`) che permette
  di collegare altri provider senza toccare il core.
- Adapter Openapi.com: autenticazione bearer, base URL sandbox/produzione, backoff
  esponenziale a 3 tentativi su errori 5xx/di rete, fallimento immediato su 4xx, client
  Guzzle + sleeper iniettabili per i test, e un logger che rimuove il bearer token da
  messaggi e body.
- L'invio non è **mai automatico** — una salvaguardia progettuale deliberata per
  un'operazione fiscale irreversibile.

### 5. Microservizio HTTP (`public/index.php`)

App Slim 4 che espone `GET /health`, `POST /fattura/build`, `POST /fattura/numero`,
`POST /fattura/send`; configurazione DB + token via variabili d'ambiente; il Dockerfile
produce un'immagine Alpine non-root. Adatto come sidecar interno per stack non-PHP.

> ⚠️ Il microservizio attualmente **non ha autenticazione** — vedi GAPS.md #1. Non
> esporlo oltre localhost/una rete privata.

### 6. Packaging

`composer.json` è pronto per la pubblicazione su Packagist: PSR-4, PHP ^8.2, dipendenze
core limitate a `ext-dom`/`ext-libxml`, extra HTTP/trasporto in `require-dev` + `suggest`,
licenza MIT, test collegati a `composer test`.

## Ricette "plug and play" pratiche che funzionano già ora

```php
// 1. Build + validate + number, no service, any PHP app:
$numero = (new NumeratoreService($pdo))->next();          // "2026/00001"
$xml    = (new XmlBuilder())->build([...]);               // FatturaPA XML
$errors = (new XmlBuilder())->validate($xml);             // [] = XSD-valid

// 2. Send via Openapi.com sandbox (token in env):
$res = OpenapiClient::createFromEnv(testMode: true)->sendInvoice($xml);

// 3. Non-PHP stack: run the Docker image, POST JSON to /fattura/build.
```

## Cosa "funziona" NON significa ancora

Validità XSD ≠ accettazione da parte dello SdI. Lo SdI applica circa 100 controlli
semantici aggiuntivi (codici 00400–00476: coerenza dei totali, natura vs aliquota,
checksum del codice fiscale, numeri duplicati, …) e la correttezza fiscale di bollo,
split payment, ritenute ecc. resta responsabilità del chiamante. Il builder attuale copre
**solo l'happy path della fattura immediata TD01** — tutto il resto è catalogato in
[GAPS.md](GAPS.md).
