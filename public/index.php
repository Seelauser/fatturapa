<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AlpsFatturapa\Contracts\SdiTransport;
use AlpsFatturapa\Notifications\NotificationParser;
use AlpsFatturapa\NumeratoreService;
use AlpsFatturapa\Transport\OpenapiClient;
use AlpsFatturapa\Transport\PecTransport;
use AlpsFatturapa\XmlBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware((getenv('APP_ENV') !== 'production'), true, true);

$json = static function (Response $res, array $data, int $status = 200): Response {
    $res->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
};

// API-key auth on everything except /health. Refuses to serve when API_KEY is
// unset: sending is an irreversible fiscal operation, never expose it open.
$app->add(function (Request $req, $handler) use ($json) {
    if ($req->getUri()->getPath() === '/health') {
        return $handler->handle($req);
    }
    $expected = getenv('API_KEY') ?: '';
    $res = new \Slim\Psr7\Response();
    if ($expected === '') {
        return $json($res, ['error' => 'service not configured: set API_KEY'], 503);
    }
    $given = $req->getHeaderLine('X-Api-Key');
    if (!hash_equals($expected, $given)) {
        return $json($res, ['error' => 'invalid or missing X-Api-Key'], 401);
    }
    return $handler->handle($req);
});

// Health check (unauthenticated)
$app->get('/health', fn (Request $req, Response $res) => $json($res, ['status' => 'ok']));

// POST /fattura/build  { invoice: {...} }  → build (and validate) FatturaPA XML
$app->post('/fattura/build', function (Request $req, Response $res) use ($json): Response {
    $body = (array) $req->getParsedBody();
    $invoice = $body['invoice'] ?? $body;
    try {
        $builder = new XmlBuilder();
        $xml = $builder->build($invoice);
        $errors = $builder->validate($xml);
        return $json($res, ['xml' => $xml, 'valid' => $errors === [], 'errors' => $errors], 201);
    } catch (\InvalidArgumentException $e) {
        return $json($res, ['error' => $e->getMessage()], 422);
    }
});

// POST /fattura/numero  { year?, sezionale? }  → reserve the next invoice number
$app->post('/fattura/numero', function (Request $req, Response $res) use ($json): Response {
    $pdo = pdoFromEnv();
    if (!$pdo) {
        return $json($res, ['error' => 'numbering DB not configured (set DB_HOST/DB_DATABASE/...)'], 503);
    }
    $body = (array) $req->getParsedBody();
    $service = new NumeratoreService($pdo, getenv('SDI_SEQUENCE_TABLE') ?: 'sdi_sequence');
    $service->ensureTable();
    $numero = $service->next(
        isset($body['year']) ? (int) $body['year'] : null,
        (string) ($body['sezionale'] ?? '')
    );
    return $json($res, ['numero' => $numero], 201);
});

// POST /fattura/send  { xml, meta? }  → transmit to SdI (manual, irreversible)
$app->post('/fattura/send', function (Request $req, Response $res) use ($json): Response {
    $body = (array) $req->getParsedBody();
    if (empty($body['xml'])) {
        return $json($res, ['error' => 'missing xml'], 422);
    }
    try {
        $result = transportFromEnv()->sendInvoice((string) $body['xml'], (array) ($body['meta'] ?? []));
        return $json($res, $result, 201);
    } catch (\AlpsFatturapa\Exception\TransportException $e) {
        return $json($res, ['error' => $e->getMessage()], 502);
    }
});

// GET /fattura/status/{id}  → provider-side status of a sent invoice
$app->get('/fattura/status/{id}', function (Request $req, Response $res, array $args) use ($json): Response {
    try {
        return $json($res, transportFromEnv()->getInvoiceStatus((string) $args['id']));
    } catch (\AlpsFatturapa\Exception\TransportException $e) {
        return $json($res, ['error' => $e->getMessage()], 502);
    }
});

// POST /fattura/notifica  { xml }  → parse an SdI notification file (RC/NS/MC/NE/DT/AT)
$app->post('/fattura/notifica', function (Request $req, Response $res) use ($json): Response {
    $body = (array) $req->getParsedBody();
    if (empty($body['xml'])) {
        return $json($res, ['error' => 'missing xml'], 422);
    }
    try {
        $n = (new NotificationParser())->parse((string) $body['xml']);
        return $json($res, [
            'tipo' => $n->tipo,
            'identificativo_sdi' => $n->identificativoSdi,
            'nome_file' => $n->nomeFile,
            'data_ora_ricezione' => $n->dataOraRicezione,
            'errori' => $n->errori,
            'esito_committente' => $n->esitoCommittente,
            'positive' => $n->isPositive(),
            'rejection' => $n->isRejection(),
        ]);
    } catch (\InvalidArgumentException $e) {
        return $json($res, ['error' => $e->getMessage()], 422);
    }
});

$app->run();

/** Select the SdI transport from SDI_TRANSPORT (pec | openapi; default openapi). */
function transportFromEnv(): SdiTransport
{
    return match (getenv('SDI_TRANSPORT') ?: 'openapi') {
        'pec' => PecTransport::createFromEnv(),
        default => OpenapiClient::createFromEnv(getenv('SDI_MODE') !== 'production'),
    };
}

/** Build a PDO from env, or null when DB env is absent. */
function pdoFromEnv(): ?PDO
{
    $host = getenv('DB_HOST');
    $db = getenv('DB_DATABASE');
    if (!$host || !$db) {
        return null;
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db);
    if ($port = getenv('DB_PORT')) {
        $dsn .= ';port=' . $port;
    }
    return new PDO($dsn, getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
