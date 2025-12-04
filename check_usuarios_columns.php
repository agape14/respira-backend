<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

$columns = Schema::connection('sqlsrv')->getColumnListing('usuarios');
echo "Columns in 'usuarios' table:\n";
foreach ($columns as $col) {
    echo "- " . $col . "\n";
}
