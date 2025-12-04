<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserController extends Controller
{
    /**
     * Listar usuarios
     */
    public function index()
    {
        $users = User::with('perfil')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'nombre_completo' => $user->nombre_completo,
                    'email' => $user->nombre_usuario, // Usamos nombre_usuario como email
                    'rol' => $user->perfil ? $user->perfil->nombre_perfil : 'Sin Rol',
                    'perfil_id' => $user->perfil_id,
                    'estado' => $user->estado ? 'Activo' : 'Inactivo',
                    'fecha_creacion' => $user->fecha_creacion ? $user->fecha_creacion->format('Y-m-d') : null,
                ];
            });

        return response()->json($users);
    }

    /**
     * Crear usuario
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre_completo' => 'required|string',
            'email' => 'required|email|unique:usuarios,nombre_usuario',
            'perfil_id' => 'required|integer',
            'password' => 'required|string|min:6',
        ]);

        try {
            $id = DB::table('usuarios')->insertGetId([
                'nombre_completo' => $request->nombre_completo,
                'nombre_usuario' => $request->email,
                'perfil_id' => $request->perfil_id,
                'contrasena' => Hash::make($request->password),
                'estado' => 1,
                'fecha_creacion' => DB::raw('GETDATE()'),
                // Campos requeridos por la base de datos (legacy/moodle)
                'cmp' => '0', 
                'usuariomoodle' => $request->email, 
                'idmoodle' => '0'
            ]);

            $user = User::with('perfil')->find($id);

            return response()->json(['message' => 'Usuario creado correctamente', 'user' => $user], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear usuario: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar usuario
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        $request->validate([
            'nombre_completo' => 'required|string',
            'email' => 'required|email|unique:usuarios,nombre_usuario,' . $id,
            'perfil_id' => 'required|integer',
        ]);

        try {
            $user->nombre_completo = $request->nombre_completo;
            $user->nombre_usuario = $request->email;
            $user->perfil_id = $request->perfil_id;
            
            if ($request->filled('password')) {
                $user->contrasena = Hash::make($request->password);
            }

            if ($request->has('estado')) {
                $user->estado = $request->estado;
            }

            $user->save();

            return response()->json(['message' => 'Usuario actualizado correctamente', 'user' => $user]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar usuario: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar usuario
     */
    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        try {
            // Soft delete logic or hard delete depending on requirements. 
            // Given "estado" field exists, we might just want to deactivate, but CRUD usually implies delete.
            // Let's try hard delete first, or set state to 0 if preferred.
            // User request says "DELETE /users/{id} (eliminar)".
            $user->delete();
            return response()->json(['message' => 'Usuario eliminado correctamente']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar usuario: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Obtener roles disponibles
     */
    public function getRoles()
    {
        // Assuming 'perfiles' table exists based on User model relationship
        $roles = DB::table('perfiles')->select('id', 'nombre_perfil')->get();
        return response()->json($roles);
    }
}
