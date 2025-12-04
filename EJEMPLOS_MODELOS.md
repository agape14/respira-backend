# 📚 Ejemplos Prácticos de Modelos Eloquent

## 🎯 Después de Generar tus Modelos

Una vez que hayas ejecutado:
```bash
php artisan db:generate-models --table=tu_tabla
```

Aquí tienes ejemplos prácticos de cómo usar los modelos generados.

---

## 1️⃣ CRUD Básico

### Crear un Nuevo Registro

```php
use App\Models\Usuario;

// Opción 1: create()
$usuario = Usuario::create([
    'nombre' => 'Juan Pérez',
    'email' => 'juan@example.com',
    'telefono' => '123456789'
]);

// Opción 2: new + save()
$usuario = new Usuario();
$usuario->nombre = 'Juan Pérez';
$usuario->email = 'juan@example.com';
$usuario->save();
```

### Leer Registros

```php
// Obtener todos
$usuarios = Usuario::all();

// Obtener por ID
$usuario = Usuario::find(1);

// Obtener o fallar (lanza excepción 404)
$usuario = Usuario::findOrFail(1);

// Obtener el primero
$usuario = Usuario::first();

// Obtener con condiciones
$usuarios = Usuario::where('email', 'juan@example.com')->get();
$usuario = Usuario::where('email', 'juan@example.com')->first();

// Obtener con múltiples condiciones
$usuarios = Usuario::where('activo', true)
    ->where('edad', '>', 18)
    ->get();
```

### Actualizar Registros

```php
// Opción 1: Buscar y actualizar
$usuario = Usuario::find(1);
$usuario->nombre = 'Juan Carlos Pérez';
$usuario->save();

// Opción 2: update()
$usuario = Usuario::find(1);
$usuario->update([
    'nombre' => 'Juan Carlos Pérez',
    'telefono' => '987654321'
]);

// Opción 3: Actualización masiva
Usuario::where('ciudad', 'Lima')
    ->update(['activo' => true]);
```

### Eliminar Registros

```php
// Eliminar por ID
$usuario = Usuario::find(1);
$usuario->delete();

// Eliminar directamente
Usuario::destroy(1);

// Eliminar múltiples
Usuario::destroy([1, 2, 3]);

// Eliminar con condiciones
Usuario::where('activo', false)->delete();
```

---

## 2️⃣ Consultas Avanzadas

### Búsqueda con LIKE

```php
// Buscar usuarios cuyo nombre contenga "Juan"
$usuarios = Usuario::where('nombre', 'LIKE', '%Juan%')->get();

// Buscar emails que terminen en gmail.com
$usuarios = Usuario::where('email', 'LIKE', '%@gmail.com')->get();
```

### Ordenamiento

```php
// Orden ascendente
$usuarios = Usuario::orderBy('nombre', 'asc')->get();

// Orden descendente
$usuarios = Usuario::orderBy('created_at', 'desc')->get();

// Múltiples ordenamientos
$usuarios = Usuario::orderBy('apellido')
    ->orderBy('nombre')
    ->get();
```

### Paginación

```php
// 15 registros por página
$usuarios = Usuario::paginate(15);

// En el controlador, retorna JSON con paginación
return response()->json($usuarios);

// Paginación simple (solo next/previous)
$usuarios = Usuario::simplePaginate(15);
```

### Limitar Resultados

```php
// Obtener solo 10
$usuarios = Usuario::limit(10)->get();

// Saltar 20 y obtener 10 (útil para paginación manual)
$usuarios = Usuario::skip(20)->take(10)->get();
```

### Contar y Agregar

```php
// Contar
$total = Usuario::count();
$activos = Usuario::where('activo', true)->count();

// Suma
$totalVentas = Orden::sum('total');

// Promedio
$promedioEdad = Usuario::avg('edad');

// Máximo y Mínimo
$edadMaxima = Usuario::max('edad');
$edadMinima = Usuario::min('edad');
```

---

## 3️⃣ Relaciones Eloquent

### Definir Relaciones en el Modelo

```php
// En app/Models/Usuario.php

class Usuario extends Model
{
    // Relación uno a muchos (un usuario tiene muchas órdenes)
    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'usuario_id');
    }

    // Relación uno a uno (un usuario tiene un perfil)
    public function perfil()
    {
        return $this->hasOne(Perfil::class);
    }

    // Relación muchos a muchos (usuarios y roles)
    public function roles()
    {
        return $this->belongsToMany(Rol::class, 'usuario_rol');
    }
}

// En app/Models/Orden.php

class Orden extends Model
{
    // Relación inversa (una orden pertenece a un usuario)
    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}
```

### Usar Relaciones

```php
// Obtener las órdenes de un usuario
$usuario = Usuario::find(1);
$ordenes = $usuario->ordenes;

// Obtener el usuario de una orden
$orden = Orden::find(1);
$usuario = $orden->usuario;

// Eager Loading (evita el problema N+1)
$usuarios = Usuario::with('ordenes')->get();

// Múltiples relaciones
$usuarios = Usuario::with(['ordenes', 'perfil'])->get();

// Filtrar por relación
$usuarios = Usuario::whereHas('ordenes', function($query) {
    $query->where('total', '>', 1000);
})->get();
```

---

## 4️⃣ Mutadores y Accesorios

### Mutadores (Modificar al guardar)

```php
// En el modelo Usuario

// Hashear password automáticamente
public function setPasswordAttribute($value)
{
    $this->attributes['password'] = bcrypt($value);
}

// Convertir nombre a mayúsculas
public function setNombreAttribute($value)
{
    $this->attributes['nombre'] = strtoupper($value);
}

// Uso
$usuario = new Usuario();
$usuario->password = 'mi_password'; // Se hasheará automáticamente
$usuario->nombre = 'juan pérez'; // Se guardará como 'JUAN PÉREZ'
```

### Accesorios (Modificar al obtener)

```php
// En el modelo Usuario

// Obtener nombre completo
public function getNombreCompletoAttribute()
{
    return "{$this->nombre} {$this->apellido}";
}

// Formatear fecha
public function getFechaFormateadaAttribute()
{
    return $this->created_at->format('d/m/Y');
}

// Uso
$usuario = Usuario::find(1);
echo $usuario->nombre_completo; // Juan Pérez
echo $usuario->fecha_formateada; // 13/11/2025
```

---

## 5️⃣ Scopes (Consultas Reutilizables)

### Definir Scopes

```php
// En el modelo Usuario

// Scope global
public function scopeActivos($query)
{
    return $query->where('activo', true);
}

// Scope con parámetros
public function scopeDeEdad($query, $edad)
{
    return $query->where('edad', '>=', $edad);
}

// Scope complejo
public function scopeConOrdenes($query, $minimo = 1)
{
    return $query->has('ordenes', '>=', $minimo);
}
```

### Usar Scopes

```php
// Obtener usuarios activos
$usuarios = Usuario::activos()->get();

// Combinar scopes
$usuarios = Usuario::activos()
    ->deEdad(18)
    ->get();

// Con otros métodos
$usuarios = Usuario::activos()
    ->where('ciudad', 'Lima')
    ->orderBy('nombre')
    ->get();
```

---

## 6️⃣ Ejemplo Completo: Sistema de Evaluaciones

```php
// Modelo: Serumista
class Serumista extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'serumistas';
    
    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class);
    }
    
    public function citas()
    {
        return $this->hasMany(Cita::class);
    }
    
    // Scope para serumistas con riesgo
    public function scopeConRiesgo($query, $nivel = 'moderado')
    {
        return $query->whereHas('evaluaciones', function($q) use ($nivel) {
            $q->where('nivel_riesgo', $nivel);
        });
    }
}

// Uso en Controlador
class SerumistaController extends Controller
{
    public function index()
    {
        return Serumista::with(['evaluaciones', 'citas'])
            ->paginate(20);
    }
    
    public function conRiesgoModerado()
    {
        return Serumista::conRiesgo('moderado')
            ->with('evaluaciones')
            ->get();
    }
    
    public function estadisticas()
    {
        return response()->json([
            'total' => Serumista::count(),
            'con_evaluaciones' => Serumista::has('evaluaciones')->count(),
            'con_citas_pendientes' => Serumista::whereHas('citas', function($q) {
                $q->where('estado', 'pendiente');
            })->count(),
        ]);
    }
}
```

---

## 7️⃣ Tips y Mejores Prácticas

### 1. Siempre usar Eager Loading

```php
// ❌ Malo (Problema N+1)
$usuarios = Usuario::all();
foreach ($usuarios as $usuario) {
    echo $usuario->ordenes->count(); // Query por cada usuario
}

// ✅ Bueno
$usuarios = Usuario::with('ordenes')->get();
foreach ($usuarios as $usuario) {
    echo $usuario->ordenes->count(); // Una sola query
}
```

### 2. Usar fillable o guarded

```php
// En el modelo
protected $fillable = ['nombre', 'email', 'telefono'];

// O usar guarded para proteger campos específicos
protected $guarded = ['id', 'created_at'];
```

### 3. Usar Transacciones para Operaciones Complejas

```php
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

try {
    $usuario = Usuario::create([...]);
    $perfil = Perfil::create([...]);
    $orden = Orden::create([...]);
    
    DB::commit();
} catch (\Exception $e) {
    DB::rollback();
    return response()->json(['error' => $e->getMessage()], 500);
}
```

### 4. Validar Antes de Crear

```php
$request->validate([
    'nombre' => 'required|string|max:255',
    'email' => 'required|email|unique:usuarios',
    'telefono' => 'nullable|string|max:20',
]);

$usuario = Usuario::create($request->all());
```

---

## 📖 Recursos Adicionales

- [Eloquent ORM - Documentación Oficial](https://laravel.com/docs/eloquent)
- [Query Builder](https://laravel.com/docs/queries)
- [Relaciones Eloquent](https://laravel.com/docs/eloquent-relationships)
- [Colecciones](https://laravel.com/docs/collections)

---

**¡Ahora estás listo para trabajar con tus modelos! 🚀**

