# 📅 Guía de Manejo de Fechas - Zona Horaria Lima, Perú

## Problema Solucionado

Anteriormente, las fechas se guardaban con formato incorrecto (mes-día-año) en lugar del formato correcto (año-mes-día), y no usaban la zona horaria de Lima, Perú (UTC-5).

## Solución Implementada

### 1. Helper de Fechas (`app/Helpers/DateHelper.php`)

Se creó una clase helper con métodos para manejar fechas en zona horaria de Lima:

- `DateHelper::nowLima()` - Retorna Carbon con zona horaria de Lima
- `DateHelper::nowLimaFormatted()` - Retorna fecha formateada para SQL Server (Y-m-d H:i:s)
- `DateHelper::todayLima()` - Retorna solo la fecha (Y-m-d)
- `DateHelper::toLima($datetime)` - Convierte cualquier fecha a zona horaria Lima
- `DateHelper::toLimaFormatted($datetime)` - Convierte y formatea para SQL Server

### 2. Funciones Helper Globales (`app/helpers.php`)

Funciones globales disponibles en todo el proyecto:

```php
// Obtener fecha/hora actual de Lima
$ahora = now_lima();

// Obtener fecha/hora formateada
$ahoraFormateada = now_lima_formatted();

// Obtener solo la fecha
$hoy = today_lima();
```

### 3. Modelos Actualizados

Se agregaron casts de tipo `datetime` y `date` en los siguientes modelos:

#### `CitasFinalizado`
```php
protected $casts = [
    'fecha' => 'datetime',
];
```

#### `SesionUno`
```php
protected $casts = [
    'fecha_inicio' => 'datetime',
];
```

#### `Derivado`
```php
protected $casts = [
    'fecha' => 'datetime',
];
```

#### `Cita`
```php
protected $casts = [
    'fecha' => 'date',
    'created_at' => 'datetime',
    'updated_at' => 'datetime',
];
```

#### `Turno`
```php
protected $casts = [
    'fecha' => 'date',
];
```

### 4. Controladores Actualizados

Se reemplazaron todas las instancias de `now()` y `Carbon::now()` por `now_lima()` en:

- ✅ `ProtocoloAtencionController.php`
- ✅ `CitaController.php`
- ✅ `DashboardController.php`

## Uso en Código

### ❌ ANTES (Incorrecto)
```php
// No usar esto
$fecha = now();
$fecha = Carbon::now();
$fecha = date('Y-m-d H:i:s');
```

### ✅ AHORA (Correcto)
```php
// Usar estas funciones
$fecha = now_lima();
$fechaFormateada = now_lima_formatted();
$soloFecha = today_lima();

// Ejemplo en modelo
$sesion->fecha_inicio = now_lima();

// Ejemplo en crear registro
CitasFinalizado::create([
    'cita_id' => $cita->id,
    'paciente_id' => $cita->paciente_id,
    'fecha' => now_lima(), // Se guardará correctamente en SQL Server
    'user_id' => $request->user()->id
]);
```

## Configuración de Laravel

La zona horaria está configurada en `config/app.php`:

```php
'timezone' => 'America/Lima',
```

## Formato de Fechas en SQL Server

Todas las fechas se guardan en formato compatible con SQL Server:

- **datetime2**: `Y-m-d H:i:s` (Ejemplo: 2025-12-02 15:11:25)
- **date**: `Y-m-d` (Ejemplo: 2025-12-02)

## Verificación

Para verificar que todo funciona correctamente:

```bash
php artisan tinker

# En tinker:
now_lima()
now_lima_formatted()
today_lima()
```

## Notas Importantes

1. **Siempre usar `now_lima()`** en lugar de `now()` o `Carbon::now()`
2. Los casts en los modelos garantizan conversión automática
3. La zona horaria de Lima es UTC-5
4. El formato es compatible con SQL Server datetime2

## Archivos Modificados

- ✅ `backend/app/Helpers/DateHelper.php` (nuevo)
- ✅ `backend/app/helpers.php` (nuevo)
- ✅ `backend/composer.json` (agregado autoload de helpers)
- ✅ `backend/app/Models/CitasFinalizado.php`
- ✅ `backend/app/Models/SesionUno.php`
- ✅ `backend/app/Models/Derivado.php`
- ✅ `backend/app/Models/Cita.php`
- ✅ `backend/app/Models/Turno.php`
- ✅ `backend/app/Http/Controllers/Api/ProtocoloAtencionController.php`
- ✅ `backend/app/Http/Controllers/Api/CitaController.php`
- ✅ `backend/app/Http/Controllers/Api/DashboardController.php`

---

**Última actualización:** 2 de diciembre de 2025

