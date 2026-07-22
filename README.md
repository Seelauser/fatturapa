# seelauser/fatturapa

🇮🇹 Italiano · 🇬🇧 [English](README.en.md) · 🇩🇪 [Deutsch](README.de.md)

Una libreria PHP **senza framework** per creare l'XML della **FatturaPA** (Sistema di
Interscambio / SdI), riservare numeri di fattura progressivi e gestire l'intero ciclo
attivo e passivo — con un microservizio HTTP opzionale. Nata dall'esigenza reale di
un'associazione altoatesina e resa autonoma e riutilizzabile in qualsiasi progetto PHP.

- **Nessuna dipendenza da framework** — il nucleo (`XmlBuilder`, `NumeratoreService`)
  richiede solo `ext-dom`. Integrabile in CiviCRM, Laravel, Symfony o PHP puro.
- **Autosufficiente per scelta** — il **trasporto PEC** invia allo SdI usando solo la
  propria casella PEC (zero servizi di terze parti); il `NotificationParser` legge le
  ricevute SdI (RC/NS/MC/NE/DT/AT) offline; la validazione avviene in locale con lo
  schema XSD 1.2.3 ufficiale. Per la conservazione si può usare il servizio gratuito
  dell'Agenzia delle Entrate.
- **Conformità integrata** — TD01–TD29, sottocodici Natura granulari, imposta di bollo
  da 2 € automatica sugli importi esenti oltre 77,47 €, split payment
  (`EsigibilitaIVA=S`), CIG/CUP per la PA, `DatiPagamento`, ritenuta d'acconto, cassa
  previdenziale, sconti di linea, adatta ai forfettari (cedente persona fisica, RF19, N2.2).
- **Numerazione concorrenza-sicura** — contatore atomico per anno/sezionale su
  MariaDB/MySQL, PostgreSQL e SQLite ≥ 3.35.
- **Trasporto SdI intercambiabile** — interfaccia `SdiTransport`; adapter PEC e
  Openapi.com inclusi.
- **Ciclo passivo** — raccolta delle fatture fornitori dalla casella PEC (`.xml` e
  `.xml.p7m` firmate), estrazione dal p7m e parsing in un array pronto per la contabilità.
- **Microservizio opzionale** — una piccola app Slim espone `build` / `numero` / `send` /
  `status` / `notifica` / `inbox` / `render` via HTTP, protetta da header `X-Api-Key`.

> ⚠️ L'invio allo SdI è un'**operazione fiscale irreversibile**. Questa libreria non
> invia mai automaticamente — l'invio è sempre attivato da una persona. Verificare
> sempre il trattamento fiscale con il proprio commercialista.

## Installazione

```bash
composer require seelauser/fatturapa
```

Richiede PHP 8.2+, `ext-dom`, `ext-libxml`.

## Creare l'XML

```php
use Fatturapa\XmlBuilder;

$xml = (new XmlBuilder())->build([
    'tipo_documento' => 'TD01',
    'numero'         => '2026/00042',
    'data'           => '2026-07-01',
    'cedente' => [
        'denominazione' => 'Associazione Esempio',
        'partita_iva'   => '01234567890',
        'regime_fiscale'=> 'RF01',
        'indirizzo' => 'Via Roma 1', 'cap' => '39100', 'comune' => 'Bolzano',
        'provincia' => 'BZ', 'nazione' => 'IT',
    ],
    'cessionario' => [
        'nome' => 'Anna', 'cognome' => 'Verdi', 'codice_fiscale' => 'VRDNNA80A41A952G',
        'indirizzo' => 'Via Dante 5', 'cap' => '39100', 'comune' => 'Bolzano',
        'provincia' => 'BZ', 'nazione' => 'IT',
    ],
    'linee' => [
        ['descrizione' => 'Quota associativa 2026', 'quantita' => 1, 'prezzo_unitario' => 50.0, 'aliquota_iva' => 0.0, 'natura' => 'N4'],
    ],
]);
```

Altre opzioni di input (la forma completa è nel docblock di `XmlBuilder`):

```php
'tipo_documento' => 'TD04',                     // qualsiasi TD01…TD29 (TD04 = nota di credito)
'formato'        => 'FPA12',                    // pubblica amministrazione
'esigibilita_iva'=> 'S',                        // split payment (PA)
'ordine_acquisto'=> ['cig' => 'Z123456789', 'cup' => '...'],
'bollo'          => true,                       // omettere per la regola automatica dei 2 € (> 77,47 € esenti)
'causale'        => 'Riferimento pratica ...',
'cedente'        => ['nome' => 'Mario', 'cognome' => 'Rossi', 'regime_fiscale' => 'RF19', ...], // libero professionista/forfettario
'pagamento'      => ['condizioni' => 'TP02', 'dettagli' => [['modalita' => 'MP05', 'iban' => 'IT60...', 'scadenza' => '2026-08-01']]],
'ritenuta'       => ['tipo' => 'RT01', 'aliquota' => 20.0, 'causale' => 'A'],   // + 'ritenuta' => true sulle linee
'cassa'          => ['tipo' => 'TC22', 'aliquota' => 4.0, 'aliquota_iva' => 22.0], // cassa previdenziale (sommata ai totali)
// per linea: 'sconto_percentuale' => 10.0 oppure 'sconto_importo' => 5.0
```

La validazione è a due livelli: `build()` verifica i campi e le regole semantiche SdI
(natura ⇔ aliquota 0, sottocodici granulari, lunghezza del codice destinatario, …) e
solleva un'eccezione con **tutti** gli errori elencati. Validazione XSD opzionale
(copiare lo schema ufficiale `FatturaPA_v1.2.3.xsd` in `resources/xsd/`; la 1.2.2
viene usata come ripiego):

```php
$errors = (new XmlBuilder())->validate($xml); // [] se valido
```

## Riservare un numero di fattura

```php
use Fatturapa\NumeratoreService;

$svc = new NumeratoreService($pdo);       // MariaDB/MySQL, PostgreSQL o SQLite ≥3.35; tabella configurabile
$svc->ensureTable();                       // crea `sdi_sequence` se assente
$numero = $svc->next();                    // "2026/00001", "2026/00002", …
$numero = $svc->next(2026, 'EXT');         // "2026/00001/EXT" (sezionale separato)
```

## Inviare allo SdI (manuale)

### Tramite la propria casella PEC — nessun servizio di terze parti

```php
use Fatturapa\Transport\PecTransport;

$pec = new PecTransport(
    pecAddress:  'azienda@pec.example.it',
    cedentePiva: '01234567890',
    smtpHost:    'smtps.pec.aruba.it',   // SMTP del proprio gestore PEC
    smtpUsername:'azienda@pec.example.it',
    smtpPassword:'***',
    // Il primo invio in assoluto va a sdi01@pec.fatturapa.it; lo SdI risponde
    // assegnando l'indirizzo dedicato — da quel momento passarlo come $sdiAddress.
);
$result = $pec->sendInvoice($xml, ['progressivo' => '00042']);
// ['identificativo' => 'IT01234567890_00042.xml', ...]
```

Le ricevute SdI arrivano nella casella PEC. Interrogarla automaticamente (client
IMAP proprio, gestisce l'imbustamento PEC `postacert.eml`):

```php
use Fatturapa\Notifications\PecInboxReader;

foreach (PecInboxReader::createFromEnv()->fetchNotifications() as $f) {
    // $f['filename'], $f['notification'] (SdiNotification)
}
```

`fetchAll()` restituisce in più le **fatture passive** in arrivo (ciclo passivo,
`.xml` e `.xml.p7m` firmate) già trasformate in un array pronto per la contabilità —
vedi `Passive\ReceivedInvoiceParser` e `Passive\P7mExtractor`. Lo stato delle fatture
emesse si traccia con `Lifecycle\InvoiceStore` (creata → inviata → consegnata/
scartata/…, `applyNotification()` chiude il cerchio automaticamente).

…oppure interpretare una ricevuta XML già scaricata:

```php
use Fatturapa\Notifications\NotificationParser;

$n = (new NotificationParser())->parse($attachmentXml);
$n->tipo;          // 'RC' | 'NS' | 'MC' | 'NE' | 'DT' | 'AT'
$n->isRejection(); // true in caso di scarto → correggere e rinviare entro 5 giorni (stesso numero ammesso)
$n->errori;        // [['codice' => '00404', 'descrizione' => ...], ...]
```

Note sul canale PEC: messaggio ≤ 30 MB, fattura ≤ 5 MB, asincrono (nessun esito
istantaneo). La firma **non** è richiesta per B2B/B2C; le fatture FPA12 (PA) devono
essere firmate — serve un certificato qualificato, indipendentemente dal canale.
Per la conservazione decennale gratuita attivare il servizio dell'Agenzia delle
Entrate in "Fatture e Corrispettivi".

### Tramite Openapi.com (intermediario opzionale)

```php
use Fatturapa\Transport\OpenapiClient;

$client = OpenapiClient::createFromEnv(testMode: true); // legge OPENAPI_TOKEN
$result = $client->sendInvoice($xml, ['numero' => '2026/00042']);
// ['identificativo' => '<uuid>', 'raw' => [...]]
```

Per altri provider implementare `Fatturapa\Contracts\SdiTransport`.

## Resa leggibile (foglio di stile ufficiale)

```php
$html = (new Fatturapa\Render\StylesheetRenderer())->renderHtml($xml);
```

Richiede `php-xsl` e il foglio di stile ufficiale AdE in `resources/xsl/` (non
incluso per motivi di licenza, come lo XSD).

## Microservizio HTTP (opzionale)

Installare con le dipendenze extra (Slim, Guzzle) e servire `public/`:

```bash
composer install
php -S 0.0.0.0:8080 -t public
```

Tutte le rotte tranne `/health` richiedono l'header `X-Api-Key` corrispondente alla
variabile `API_KEY`; senza `API_KEY` configurata il servizio rifiuta le richieste.

| Metodo e percorso    | Body                          | Risposta |
|----------------------|-------------------------------|---------|
| `GET  /health`       | —                             | `{status:"ok"}` |
| `POST /fattura/build`| `{ invoice: {…} }`            | `{ xml, valid, errors }` |
| `POST /fattura/numero`| `{ year?, sezionale? }`      | `{ numero }` (richiede env DB) |
| `POST /fattura/send` | `{ xml, meta? }`              | `{ identificativo, raw }` |
| `GET  /fattura/status/{id}` | —                      | `{ status, raw }` |
| `POST /fattura/notifica` | `{ xml }`                 | ricevuta SdI interpretata |
| `GET  /fattura/inbox` | —                            | nuove ricevute SdI **e fatture passive** dalla casella PEC (IMAP) |
| `POST /fattura/render` | `{ xml }`                   | HTML leggibile (foglio di stile ufficiale; richiede php-xsl + XSL in `resources/xsl/`) |

Variabili d'ambiente: `API_KEY` (obbligatoria), `DB_HOST`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, `DB_PORT`, `SDI_SEQUENCE_TABLE`; selezione del
trasporto `SDI_TRANSPORT` (`pec` | `openapi`, default `openapi`). Per `openapi`:
`OPENAPI_TOKEN`, `SDI_MODE` (`production` disattiva la sandbox). Per `pec`:
`PEC_ADDRESS`, `PEC_SMTP_HOST`, `PEC_SMTP_USERNAME`, `PEC_SMTP_PASSWORD`,
`CEDENTE_PIVA`, opzionali `PEC_SDI_ADDRESS` (dopo l'assegnazione da parte dello SdI)
e `PEC_SMTP_PORT`. Lettura casella: `PEC_IMAP_HOST`, opzionali `PEC_IMAP_PORT` (993),
`PEC_IMAP_USERNAME`/`PEC_IMAP_PASSWORD` (default: credenziali SMTP).

## Documentazione

- [docs/CAPABILITIES.md](docs/CAPABILITIES.md) — cosa funziona oggi, superficie plug-and-play verificata
- [docs/GAPS.md](docs/GAPS.md) — lacune note e loro stato (in ordine di gravità)
- [docs/ROADMAP.md](docs/ROADMAP.md) — posizionamento di mercato, priorità degli adapter, piano di rilascio

Tutta la documentazione in docs/ è disponibile in italiano (principale), inglese e tedesco; il CHANGELOG è mantenuto in inglese.

## Test

```bash
composer install && composer test
```

## Licenza

MIT — vedi [LICENSE](LICENSE).
