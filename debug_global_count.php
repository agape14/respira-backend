<?php

use App\Models\Cita;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$patientId = 227; // From user example (assuming 227 is patient ID or something, wait. 227 was under "N° Sesión" in user example? No, 227 was under N° Sesión. That's weird.)

// User example:
// N° Intervención	N° Sesión	Paciente
// 0               227         ALEXIA NICOLE CARRION

// If 227 is the session number, that's huge.
// If 227 is the ID of the session, that makes sense.

// Let's find a patient to test with.
$cita = Cita::orderBy('id', 'desc')->first();
$patientId = $cita->paciente_id;

echo "Testing for patient ID: $patientId\n";

$citas = Cita::where('paciente_id', $patientId)
    ->orderBy('fecha', 'desc')
    ->orderBy('hora_inicio', 'desc')
    ->get();

foreach ($citas as $c) {
    $global = Cita::where('paciente_id', $c->paciente_id)
        ->where(function ($q) use ($c) {
            $q->where('fecha', '<', $c->fecha)
              ->orWhere(function ($q2) use ($c) {
                  $q2->where('fecha', '=', $c->fecha)
                     ->where('hora_inicio', '<=', $c->hora_inicio);
              });
        })
        ->count();
    
    echo "Cita ID: {$c->id}, Fecha: {$c->fecha}, Global: $global, Intervencion: " . ceil($global/4) . ", Sesion: " . ((($global-1)%4)+1) . "\n";
}
