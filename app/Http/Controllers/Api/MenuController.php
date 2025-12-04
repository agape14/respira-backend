<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Menu;
use App\Models\PermisosPerfilMenu;

class MenuController extends Controller
{
    /**
     * Obtener menús filtrados por perfil del usuario autenticado
     */
    public function getMenusByProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Si es administrador, devolver todos los menús
        if ($user->perfil_id == 1) {
            $menus = Menu::where('estado', 1)
                ->orderBy('orden', 'asc')
                ->get()
                ->map(function($menu) {
                    return [
                        'id' => $menu->id,
                        'nombre_menu' => $menu->nombre_menu,
                        'url' => $menu->url,
                        'icono' => $menu->icono,
                        'orden' => $menu->orden,
                        'permiso_ver' => true,
                        'permiso_editar' => true,
                        'permiso_eliminar' => true,
                    ];
                });

            return response()->json($menus);
        }

        // Para otros perfiles, obtener menús con permisos
        $menus = Menu::select('menus.*', 'permisos_perfil_menu.permiso_ver', 'permisos_perfil_menu.permiso_editar', 'permisos_perfil_menu.permiso_eliminar')
            ->join('permisos_perfil_menu', 'menus.id', '=', 'permisos_perfil_menu.menu_id')
            ->where('permisos_perfil_menu.perfil_id', $user->perfil_id)
            ->where('menus.estado', 1)
            ->where('permisos_perfil_menu.permiso_ver', 1)
            ->orderBy('menus.orden', 'asc')
            ->get()
            ->map(function($menu) {
                return [
                    'id' => $menu->id,
                    'nombre_menu' => $menu->nombre_menu,
                    'url' => $menu->url,
                    'icono' => $menu->icono,
                    'orden' => $menu->orden,
                    'permiso_ver' => (bool)$menu->permiso_ver,
                    'permiso_editar' => (bool)$menu->permiso_editar,
                    'permiso_eliminar' => (bool)$menu->permiso_eliminar,
                ];
            });

        return response()->json($menus);
    }

    /**
     * Obtener todos los menús (solo admin)
     */
    public function index(Request $request)
    {
        $menus = Menu::where('estado', 1)
            ->orderBy('orden', 'asc')
            ->get();

        return response()->json($menus);
    }
}

