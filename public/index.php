<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app = AppFactory::create();
$app->addErrorMiddleware(false, true, true);

// Health check
$app->get('/health', function (Request $req, Response $res): Response {
    $res->getBody()->write(json_encode(['status' => 'ok']));
    return $res->withHeader('Content-Type', 'application/json');
});

// Build FatturaPA XML
// POST /fattura/build  { invoice: {...} }
$app->post('/fattura/build', function (Request $req, Response $res): Response {
    $body = json_decode((string) $req->getBody(), true);
    // TODO: wire to XmlBuilder after namespace migration
    $res->getBody()->write(json_encode(['xml' => null, 'error' => 'not_implemented']));
    return $res->withHeader('Content-Type', 'application/json')->withStatus(501);
});

// Send to SdI
// POST /fattura/send  { invoice_id: N }
$app->post('/fattura/send', function (Request $req, Response $res): Response {
    // TODO: wire to OpenapiClient after namespace migration
    $res->getBody()->write(json_encode(['error' => 'not_implemented']));
    return $res->withHeader('Content-Type', 'application/json')->withStatus(501);
});

$app->run();
