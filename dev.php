<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\ImportControlPlans;
use App\ImportFmea;

$socket = @fsockopen('127.0.0.1', 5050, $errno, $errstr, 0.4);
if (is_resource($socket)) {
    fclose($socket);
    fwrite(STDERR, "Aplikacja już działa na http://127.0.0.1:5050 — zamknij poprzednie okno serwera.\n");
    exit(1);
}

$db = Database::open();
$db->init();
$fmea = (int) $db->one('SELECT COUNT(*) AS n FROM fmea')['n'];
$plans = (int) $db->one('SELECT COUNT(*) AS n FROM control_plans')['n'];
if ($fmea === 0 && is_file(ImportFmea::defaultPath())) {
    $result = (new ImportFmea())->import(ImportFmea::defaultPath());
    echo "Zaimportowano FMEA: {$result['fmea']}\n";
}
if ($plans === 0 && is_file(ImportControlPlans::defaultPath())) {
    try {
        $result = (new ImportControlPlans())->import(ImportControlPlans::defaultPath());
        echo "Zaimportowano plany kontroli: {$result['plans']}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
    }
}

$php = PHP_BINARY;
$ini = __DIR__ . DIRECTORY_SEPARATOR . 'php.ini';
$router = __DIR__ . DIRECTORY_SEPARATOR . 'router.php';
$command = escapeshellarg($php) . ' -c ' . escapeshellarg($ini) . ' -S 127.0.0.1:5050 ' . escapeshellarg($router);
echo "Rejestr FMEA: http://127.0.0.1:5050\n";
passthru($command, $code);
exit($code);
