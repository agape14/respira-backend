<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Perfil;
use App\Models\Menu;
use App\Models\PermisosPerfilMenu;
use Illuminate\Support\Facades\Validator;

class PerfilController extends Controller
{
    /**
     * Listar todos los perfiles
     */
    public function index()
    {
        $perfiles = Perfil::all();
        return response()->json($perfiles);
    }

    /**
     * Crear un nuevo perfil
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre_perfil' => 'required|string|max:100|unique:perfiles,nombre_perfil',
            'descripcion' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $perfil = Perfil::create([
            'nombre_perfil' => $request->nombre_perfil,
            'descripcion' => $request->descripcion,
            'permiso_ver' => 1,
            'permiso_editar' => 0,
            'permiso_eliminar' => 0,
            'estado' => 1,
        ]);

        return response()->json([
            'message' => 'Perfil creado exitosamente',
            'perfil' => $perfil
        ], 201);
    }

    /**
     * Obtener un perfil específico
     */
    public function show($id)
    {
        $perfil = Perfil::find($id);

        if (!$perfil) {
            return response()->json(['message' => 'Perfil no encontrado'], 404);
        }

        return response()->json($perfil);
    }

    /**
     * Actualizar un perfil
     */
    public function update(Request $request, $id)
    {
        $perfil = Perfil::find($id);

        if (!$perfil) {
            return response()->json(['message' => 'Perfil no encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre_perfil' => 'required|string|max:100|unique:perfiles,nombre_perfil,' . $id,
            'descripcion' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $perfil->update($request->only(['nombre_perfil', 'descripcion']));

        return response()->json([
            'message' => 'Perfil actualizado exitosamente',
            'perfil' => $perfil
        ]);
    }

    /**
     * Eliminar un perfil
     */
    public function destroy($id)
    {
        $perfil = Perfil::find($id);

        if (!$perfil) {
            return response()->json(['message' => 'Perfil no encontrado'], 404);
        }

        // No permitir eliminar el perfil de administrador
        if ($id == 1) {
            return response()->json([
                'message' => 'No se puede eliminar el perfil de administrador'
            ], 403);
        }

        // Verificar si hay usuarios asignados a este perfil
        $usuariosAsignados = \App\Models\User::where('perfil_id', $id)->count();
        
        if ($usuariosAsignados > 0) {
            return response()->json([
                'message' => "No se puede eliminar el perfil porque tiene {$usuariosAsignados} usuario(s) asignado(s). Primero reasigna los usuarios a otro perfil."
            ], 422);
        }

        // En lugar de eliminar, desactivar el perfil
        $perfil->estado = 0;
        $perfil->save();

        // También eliminar los permisos asociados
        \App\Models\PermisosPerfilMenu::where('perfil_id', $id)->delete();

        return response()->json([
            'message' => 'Perfil desactivado exitosamente'
        ]);
    }

    /**
     * Obtener permisos de menús para un perfil
     */
    public function getPermisos($perfilId)
    {
        $menus = Menu::where('estado', 1)
            ->orderBy('orden', 'asc')
            ->get();

        $permisos = PermisosPerfilMenu::where('perfil_id', $perfilId)->get()->keyBy('menu_id');

        $resultado = $menus->map(function($menu) use ($permisos) {
            $permiso = $permisos->get($menu->id);
            return [
                'menu_id' => $menu->id,
                'nombre_menu' => $menu->nombre_menu,
                'permiso_ver' => $permiso ? (bool)$permiso->permiso_ver : false,
                'permiso_editar' => $permiso ? (bool)$permiso->permiso_editar : false,
                'permiso_eliminar' => $permiso ? (bool)$permiso->permiso_eliminar : false,
            ];
        });

        return response()->json($resultado);
    }

    /**
     * Actualizar permisos de menús para un perfil
     */
    public function updatePermisos(Request $request, $perfilId)
    {
        $validator = Validator::make($request->all(), [
            'permisos' => 'required|array',
            'permisos.*.menu_id' => 'required|exists:menus,id',
            'permisos.*.permiso_ver' => 'boolean',
            'permisos.*.permiso_editar' => 'boolean',
            'permisos.*.permiso_eliminar' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Eliminar permisos existentes
        PermisosPerfilMenu::where('perfil_id', $perfilId)->delete();

        // Insertar nuevos permisos
        foreach ($request->permisos as $permiso) {
            // Solo insertar si al menos tiene permiso de ver
            if ($permiso['permiso_ver'] ?? false) {
                PermisosPerfilMenu::create([
                    'perfil_id' => $perfilId,
                    'menu_id' => $permiso['menu_id'],
                    'permiso_ver' => $permiso['permiso_ver'] ?? false,
                    'permiso_editar' => $permiso['permiso_editar'] ?? false,
                    'permiso_eliminar' => $permiso['permiso_eliminar'] ?? false,
                ]);
            }
        }

        return response()->json(['message' => 'Permisos actualizados exitosamente']);
    }
}
