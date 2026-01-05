<?php

use Illuminate\Support\Facades\Route;

// Evitar error "Route [login] not defined" cuando una ruta protegida se abre
// desde navegador (no espera JSON) y el middleware de auth intenta redirigir.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

Route::get('/', function () {
    return view('welcome');
});
