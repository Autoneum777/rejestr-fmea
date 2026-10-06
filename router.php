<?php

declare(strict_types=1);

use App\Database;
use App\Http;
use App\ImportControlPlans;
use App\ImportFmea;
use App\Register;

require __DIR__ . '/vendor/autoload.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($uri));
if ($uri !== '/' && is_file($file)) {
    return false;
}

$database = Database::open();
$database->init();
$register = new Register($database);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = Http::path();

try {
    if ($method === 'GET' && $path === '/') {
        Http::file(__DIR__ . '/templates/index.html', 'text/html; charset=utf-8');
    }
    route($method, $path, $register);
    Http::json(['error' => 'Nie znaleziono adresu'], 404);
} catch (Throwable $error) {
    Http::json(['error' => $error->getMessage()], 500);
}

function route(string $method, string $path, Register $register): void
{
    $q = mb_strtolower(Http::query('q'));
    $status = Http::query('status');
    $aktywne = Http::query('aktywne', '1');

    if ($method === 'GET' && $path === '/api/fmea') {
        Http::json($register->listFmea($q, $status, $aktywne));
    }
    if ($method === 'POST' && $path === '/api/fmea') {
        Http::json($register->createFmea(Http::jsonBody()), 201);
    }
    if ($method === 'GET' && preg_match('#^/api/fmea/(\d+)$#', $path, $match)) {
        Http::json($register->getFmea((int) $match[1]));
    }
    if ($method === 'PUT' && preg_match('#^/api/fmea/(\d+)$#', $path, $match)) {
        Http::json($register->updateFmea((int) $match[1], Http::jsonBody()));
    }
    if ($method === 'PUT' && preg_match('#^/api/fmea/(\d+)/aktywne$#', $path, $match)) {
        Http::json($register->toggleFmea((int) $match[1], Http::jsonBody()));
    }
    if ($method === 'DELETE' && preg_match('#^/api/fmea/(\d+)$#', $path, $match)) {
        Http::json($register->deleteFmea((int) $match[1], Http::jsonBody()));
    }
    if ($method === 'POST' && preg_match('#^/api/fmea/(\d+)/reviews$#', $path, $match)) {
        Http::json($register->addFmeaReview((int) $match[1], Http::jsonBody()), 201);
    }
    if ($method === 'GET' && $path === '/api/export') {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        Http::download($register->exportFmea($q, $status, $aktywne), "Rejestr_FMEA_{$today}.xlsx");
    }
    if ($method === 'GET' && $path === '/api/settings') {
        Http::json(Database::open()->settings());
    }
    if ($method === 'PUT' && $path === '/api/settings') {
        Http::json(Database::open()->saveSettings(Http::jsonBody()));
    }
    if ($method === 'POST' && $path === '/api/import') {
        $target = Database::dataDir() . DIRECTORY_SEPARATOR . 'import_fmea.xlsx';
        if (Http::upload($target) === null) {
            Http::json(['error' => 'Wybierz plik Excel z dysku'], 400);
        }
        try {
            Http::json((new ImportFmea())->import($target));
        } catch (RuntimeException $error) {
            Http::json(['error' => $error->getMessage()], 404);
        }
    }
    if ($method === 'GET' && $path === '/api/cp') {
        Http::json($register->listCp($q, $status, $aktywne));
    }
    if ($method === 'POST' && $path === '/api/cp') {
        Http::json($register->createCp(Http::jsonBody()), 201);
    }
    if ($method === 'POST' && $path === '/api/cp/import') {
        $target = Database::dataDir() . DIRECTORY_SEPARATOR . 'import_cp.xlsx';
        if (Http::upload($target) === null) {
            Http::json(['error' => 'Wybierz plik Excel z dysku'], 400);
        }
        try {
            Http::json((new ImportControlPlans())->import($target));
        } catch (RuntimeException $error) {
            Http::json(['error' => $error->getMessage()], 404);
        }
    }
    if ($method === 'GET' && $path === '/api/cp/export') {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        Http::download($register->exportCp($q, $status, $aktywne), "Rejestr_planow_kontroli_{$today}.xlsx");
    }
    if ($method === 'GET' && preg_match('#^/api/cp/(\d+)$#', $path, $match)) {
        Http::json($register->getCp((int) $match[1]));
    }
    if ($method === 'PUT' && preg_match('#^/api/cp/(\d+)$#', $path, $match)) {
        Http::json($register->updateCp((int) $match[1], Http::jsonBody()));
    }
    if ($method === 'POST' && preg_match('#^/api/cp/(\d+)/reviews$#', $path, $match)) {
        Http::json($register->addCpReview((int) $match[1], Http::jsonBody()));
    }
}
