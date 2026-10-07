<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Notifier;

$db = Database::open();
$db->init();
$result = (new Notifier($db))->reminders();
if ($result['skipped']) {
    echo "Przypomnienia na dziś są już sprawdzone.\n";
    exit(0);
}
echo "Zapisano przypomnienia: {$result['queued']}\n";
