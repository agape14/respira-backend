<?php

use App\Http\Controllers\Api\ProtocoloAtencionController;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new ProtocoloAtencionController();
$response = $controller->show(233); // ID mentioned by user

echo json_encode($response->getData(), JSON_PRETTY_PRINT);
