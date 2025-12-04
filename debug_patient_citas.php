<?php

use App\Models\Cita;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$patientId = 553;
$citas = Cita::where('paciente_id', $patientId)
    ->orderBy('fecha', 'asc')
    ->orderBy('hora_inicio', 'asc')
    ->get();

echo "Citas for Patient $patientId:\n";
foreach ($citas as $c) {
    echo "ID: {$c->id}, Fecha: {$c->fecha}, Hora: {$c->hora_inicio}\n";
}
