<?php

use App\Models\Cita;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Reproduce the INDEX query issue (Ambiguous column)
try {
    $cita = Cita::find(233);
    echo "Cita 233 (Patient {$cita->paciente_id}) - Date: {$cita->fecha} {$cita->hora_inicio}\n";

    // This mimics the subquery in index
    // Note: We can't easily reproduce the outer query context in raw PHP without running the full Eloquent builder
    // But we can simulate the "Ambiguous" query by joining or selecting
    
    // Let's try to run the generated SQL for the subquery to see what it does
    
    $subquery = Cita::selectRaw('count(*)')
        ->whereColumn('paciente_id', 'citas.paciente_id') // This is the problematic line if 'citas' refers to the subquery table itself
        ->toSql();
        
    echo "Subquery SQL: $subquery\n";
    
    // If we run this as a standalone query, 'citas.paciente_id' is undefined.
    // But inside a subquery, it resolves.
    
    // Let's try to construct a query that counts EVERYTHING before this date
    $countAll = Cita::where('fecha', '<', $cita->fecha)
        ->orWhere(function ($q) use ($cita) {
            $q->where('fecha', '=', $cita->fecha)
               ->where('hora_inicio', '<=', $cita->hora_inicio);
        })
        ->count();
        
    echo "Count ALL appointments before this one (ignoring patient): $countAll\n";
    
    // Count for THIS patient
    $countPatient = Cita::where('paciente_id', $cita->paciente_id)
        ->where(function ($q) use ($cita) {
            $q->where('fecha', '<', $cita->fecha)
              ->orWhere(function ($q2) use ($cita) {
                  $q2->where('fecha', '=', $cita->fecha)
                     ->where('hora_inicio', '<=', $cita->hora_inicio);
              });
        })
        ->count();
        
    echo "Count for THIS patient: $countPatient\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
