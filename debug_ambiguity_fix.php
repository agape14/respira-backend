<?php

use App\Models\Cita;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Verify the fix for the INDEX query issue
try {
    $cita = Cita::find(233);
    echo "Cita 233 (Patient {$cita->paciente_id}) - Date: {$cita->fecha} {$cita->hora_inicio}\n";

    // This mimics the FIXED subquery in index
    // We use 'from' alias
    
    $countPatient = Cita::from('citas as c2')
        ->whereColumn('c2.paciente_id', DB::raw("'{$cita->paciente_id}'")) // Simulate outer query binding
        ->where(function ($q) use ($cita) {
            $q->where('c2.fecha', '<', $cita->fecha)
              ->orWhere(function ($q2) use ($cita) {
                  $q2->where('c2.fecha', '=', $cita->fecha)
                     ->where('c2.hora_inicio', '<=', $cita->hora_inicio);
              });
        })
        ->count();
        
    echo "Count for THIS patient (Fixed Query): $countPatient\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
