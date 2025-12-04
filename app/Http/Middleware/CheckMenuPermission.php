<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\PermisosPerfilMenu;

class CheckMenuPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $menuSlug
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $menuSlug = null)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // El administrador (perfil_id = 1) tiene acceso a todo
        if ($user->perfil_id == 1) {
            return $next($request);
        }

        // Si no se especifica un menú, solo verificamos que esté autenticado
        if (!$menuSlug) {
            return $next($request);
        }

        // Verificar permisos del menú
        $menu = \App\Models\Menu::where('url', $menuSlug)->first();

        if (!$menu) {
            return $next($request);
        }

        $permiso = PermisosPerfilMenu::where('perfil_id', $user->perfil_id)
            ->where('menu_id', $menu->id)
            ->first();

        if (!$permiso || !$permiso->permiso_ver) {
            return response()->json(['message' => 'No tienes permisos para acceder a este recurso'], 403);
        }

        // Agregar información de permisos a la request
        $request->merge([
            'can_edit' => $permiso->permiso_editar ?? false,
            'can_delete' => $permiso->permiso_eliminar ?? false,
        ]);

        return $next($request);
    }
}

