<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proceso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ProcesoController extends Controller
{
    public function index()
    {
        return response()->json(
            Proceso::orderBy('id_proceso')->get()
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'anio' => 'required|integer|min:2000|max:2100',
            'corte' => 'required|in:I,II',
            'activo' => 'nullable|boolean',
        ], [
            'anio.required' => 'El año es requerido',
            'corte.required' => 'El corte es requerido',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validación fallida',
                'errors' => $validator->errors(),
            ], 422);
        }

        $anio = (int)$request->anio;
        $corte = $request->corte;

        $etiqueta = $anio . '-' . $corte;
        [$inicio, $fin] = $this->getRangoPorCorte($anio, $corte);

        // Evitar duplicados (anio+corte o etiqueta)
        if (Proceso::where('etiqueta', $etiqueta)->exists()) {
            return response()->json([
                'error' => 'El proceso ya existe',
                'message' => 'Ya existe el corte ' . $etiqueta,
            ], 409);
        }

        $proceso = Proceso::create([
            'anio' => $anio,
            'corte' => $corte,
            'etiqueta' => $etiqueta,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'activo' => $request->boolean('activo', true),
        ]);

        Cache::forget('dashboard:procesos:activos');

        return response()->json($proceso, 201);
    }

    public function update(Request $request, $idProceso)
    {
        $proceso = Proceso::findOrFail($idProceso);

        $validator = Validator::make($request->all(), [
            'anio' => 'required|integer|min:2000|max:2100',
            'corte' => 'required|in:I,II',
            'activo' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validación fallida',
                'errors' => $validator->errors(),
            ], 422);
        }

        $anio = (int)$request->anio;
        $corte = $request->corte;
        $etiqueta = $anio . '-' . $corte;
        [$inicio, $fin] = $this->getRangoPorCorte($anio, $corte);

        $dup = Proceso::where('etiqueta', $etiqueta)
            ->where('id_proceso', '!=', $proceso->id_proceso)
            ->exists();
        if ($dup) {
            return response()->json([
                'error' => 'Conflicto',
                'message' => 'Ya existe el corte ' . $etiqueta,
            ], 409);
        }

        $proceso->update([
            'anio' => $anio,
            'corte' => $corte,
            'etiqueta' => $etiqueta,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'activo' => $request->boolean('activo', (bool)$proceso->activo),
        ]);

        Cache::forget('dashboard:procesos:activos');

        return response()->json($proceso);
    }

    public function destroy($idProceso)
    {
        $proceso = Proceso::findOrFail($idProceso);
        $proceso->delete();
        Cache::forget('dashboard:procesos:activos');
        return response()->json(['message' => 'Proceso eliminado']);
    }

    private function getRangoPorCorte(int $anio, string $corte): array
    {
        if ($corte === 'I') {
            return ["{$anio}-01-01", "{$anio}-06-30"];
        }
        // II
        return ["{$anio}-07-01", "{$anio}-12-31"];
    }
}


