# 🚀 Guía Rápida - Trabajar con Base de Datos SQL Server

## ✅ Verificación de Conexión

### 1. Verificar que los drivers PHP estén instalados

```bash
php -m | findstr sqlsrv
```

Deberías ver:
```
pdo_sqlsrv
sqlsrv
```

### 2. Probar la conexión desde la terminal

```bash
php artisan tinker
>>> DB::connection()->getPdo();
>>> exit
```

Si no hay errores, la conexión funciona ✅

### 3. Probar la conexión vía HTTP

Con el servidor corriendo:
```bash
php artisan serve
```

Visita: http://localhost:8000/api/test-db

---

## 📊 Comandos Artisan Personalizados

### Ver todas las tablas de tu base de datos

```bash
php artisan db:tables
```

Salida:
```
📊 Tablas en la Base de Datos SQL Server

🔗 Conexión: sqlsrv
💾 Base de datos: tu_base_de_datos

+---+--------------+
| # | Tabla        |
+---+--------------+
| 1 | usuarios     |
| 2 | productos    |
| 3 | ordenes      |
+---+--------------+

✅ Total de tablas: 3
```

### Ver tablas con detalles (columnas y registros)

```bash
php artisan db:tables --details
```

Salida:
```
+-------------+----------+-----------+
| Tabla       | Columnas | Registros |
+-------------+----------+-----------+
| usuarios    | 8        | 150       |
| productos   | 12       | 1,234     |
| ordenes     | 15       | 5,678     |
+-------------+----------+-----------+
```

---

## 🎯 Generar Modelos Eloquent

### Ver qué tablas puedes convertir en modelos

```bash
php artisan db:generate-models
```

Esto mostrará una lista de todas las tablas y te preguntará si quieres generar modelos para todas.

### Generar modelo para una tabla específica

```bash
php artisan db:generate-models --table=usuarios
```

Esto creará: `app/Models/Usuario.php`

### Generar modelos para varias tablas específicas

```bash
php artisan db:generate-models --table=usuarios
php artisan db:generate-models --table=productos
php artisan db:generate-models --table=ordenes
```

### Generar TODOS los modelos de una vez

```bash
php artisan db:generate-models
# Responde 'yes' cuando te pregunte si quieres generar todos
```

---

## 📝 Ejemplo de Modelo Generado

Al ejecutar `php artisan db:generate-models --table=usuarios`, se creará:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'usuarios';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = true;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'nombre',
        'email',
        'password',
        'telefono',
        'direccion'
    ];

    /**
     * Aquí puedes definir tus relaciones Eloquent
     * 
     * Ejemplo:
     * public function ordenes()
     * {
     *     return $this->hasMany(Orden::class);
     * }
     */
}
```

---

## 🔧 Personalizar los Modelos

Después de generar un modelo, puedes personalizarlo:

### 1. Agregar Relaciones

```php
class Usuario extends Model
{
    // ... código existente ...
    
    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'usuario_id');
    }
    
    public function perfil()
    {
        return $this->hasOne(Perfil::class);
    }
}
```

### 2. Agregar Casts (conversión de tipos)

```php
protected $casts = [
    'email_verified_at' => 'datetime',
    'is_active' => 'boolean',
    'metadata' => 'array',
];
```

### 3. Agregar Campos Ocultos

```php
protected $hidden = [
    'password',
    'remember_token',
];
```

### 4. Usar el Modelo en tus Controladores

```php
use App\Models\Usuario;

// Obtener todos los usuarios
$usuarios = Usuario::all();

// Obtener un usuario específico
$usuario = Usuario::find(1);

// Crear un nuevo usuario
$usuario = Usuario::create([
    'nombre' => 'Juan Pérez',
    'email' => 'juan@example.com',
    'password' => bcrypt('password123'),
]);

// Actualizar un usuario
$usuario->update(['nombre' => 'Juan Carlos Pérez']);

// Eliminar un usuario
$usuario->delete();

// Query con condiciones
$usuarios = Usuario::where('email', 'like', '%@gmail.com')->get();
```

---

## 🎨 Ejemplo Completo: CRUD de Usuarios

### Crear el Controlador

```bash
php artisan make:controller Api/UsuarioController --api
```

### Implementar en el Controlador

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function index()
    {
        return Usuario::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:usuarios',
        ]);

        $usuario = Usuario::create($request->all());
        return response()->json($usuario, 201);
    }

    public function show($id)
    {
        return Usuario::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $usuario = Usuario::findOrFail($id);
        $usuario->update($request->all());
        return response()->json($usuario);
    }

    public function destroy($id)
    {
        Usuario::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
```

### Agregar Rutas

En `routes/api.php`:

```php
use App\Http\Controllers\Api\UsuarioController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('usuarios', UsuarioController::class);
});
```

---

## ⚡ Comandos Útiles del Día a Día

```bash
# Ver todas las rutas de tu API
php artisan route:list --path=api

# Limpiar caché
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Ver información de la base de datos
php artisan db:tables --details

# Generar un nuevo modelo
php artisan db:generate-models --table=nueva_tabla

# Probar consultas en tiempo real
php artisan tinker
>>> Usuario::count()
>>> Usuario::first()
>>> exit
```

---

## 🐛 Solución de Problemas

### Error: "could not find driver"

Asegúrate de tener las extensiones habilitadas en `php.ini`:
```ini
extension=php_sqlsrv_82_ts_x64.dll
extension=php_pdo_sqlsrv_82_ts_x64.dll
```

### Error: "SQLSTATE[08001]"

Verifica las credenciales en `.env`:
```env
DB_CONNECTION=sqlsrv
DB_HOST=localhost
DB_PORT=1433
DB_DATABASE=tu_base_de_datos
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña
```

### No se generan modelos

1. Verifica que la tabla exista: `php artisan db:tables`
2. Verifica los permisos de escritura en `app/Models/`
3. Revisa que la conexión funcione: `php artisan tinker` → `DB::connection()->getPdo()`

---

## 📚 Documentación Adicional

- [Laravel Eloquent ORM](https://laravel.com/docs/eloquent)
- [Laravel Query Builder](https://laravel.com/docs/queries)
- [SQL Server PHP Drivers](https://docs.microsoft.com/en-us/sql/connect/php/)

---

**¡Listo para trabajar con tu base de datos SQL Server! 🚀**

