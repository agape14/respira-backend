# Configuración de SQL Server para Respira-CMP

## ⚠️ IMPORTANTE - Regla de Oro

Este proyecto usa una **base de datos SQL Server EXISTENTE**. NO utilizaremos migraciones de Laravel para crear tablas. En su lugar, generaremos los modelos Eloquent automáticamente desde la base de datos.

## 📝 Pasos de Configuración

### 1. Configurar el archivo `.env`

Abre el archivo `.env` en la raíz del proyecto backend y actualiza estas líneas:

```env
DB_CONNECTION=sqlsrv
DB_HOST=localhost
DB_PORT=1433
DB_DATABASE=nombre_de_tu_base_de_datos
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña

# Opcional: Si tienes problemas de conexión
# DB_ENCRYPT=yes
# DB_TRUST_SERVER_CERTIFICATE=false

# SANCTUM - Dominios permitidos
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000

# SESSION
SESSION_DRIVER=cookie
SESSION_DOMAIN=localhost
```

### 2. Configurar PHP en Laragon para SQL Server

#### 2.1 Seleccionar la Versión Correcta de PHP

En Laragon, debes usar: **PHP 8.2.22 Thread Safe (TS) x64**
- ✅ `php-8.2.22-Win32-vs16-x64` (Thread Safe)
- ❌ NO usar `php-8.2.22-nts-Win32-vs16-x64` (Non-Thread Safe es para IIS)

**¿Por qué Thread Safe?** Laragon usa Apache, que requiere la versión TS de PHP.

#### 2.2 Instalar Drivers de SQL Server

1. Descarga los drivers SQLSRV para PHP 8.2 desde:
   https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server

2. Extrae y copia **SOLO estos 2 archivos** a la carpeta `ext` de PHP en Laragon:
   - `php_sqlsrv_82_ts_x64.dll`
   - `php_pdo_sqlsrv_82_ts_x64.dll`

   **Nota:** Solo necesitas los archivos `_ts_x64` (Thread Safe 64 bits). Los archivos `_nts_` (Non-Thread Safe) y `_x86` (32 bits) NO son necesarios.

#### 2.3 Configurar php.ini

1. En Laragon, ve a: **Menú → PHP → php.ini**

2. Busca la sección de extensiones y agrega estas líneas (sin punto y coma al inicio):

```ini
extension=php_sqlsrv_82_ts_x64.dll
extension=php_pdo_sqlsrv_82_ts_x64.dll
```

3. Guarda el archivo y reinicia Laragon

#### 2.4 Verificar Instalación

Ejecuta en terminal:

```bash
php -m | findstr sqlsrv
```

Deberías ver:
```
pdo_sqlsrv
sqlsrv
```

#### 2.5 Solucionar Error de php_curl.dll (si aparece)

Si ves un error relacionado con `nghttp2_option_set_rfc9113_leading_and_trailing_ws_validation`, sigue estos pasos:

**Causa:** Las librerías de soporte de PHP (nghttp2, libcurl) están desactualizadas.

**Solución:**

1. Descarga las dependencias actualizadas de PHP 8.2 VS16 x64:
   - Opción A: https://windows.php.net/downloads/releases/
   - Opción B: https://github.com/curl/curl-for-win

2. Extrae el archivo ZIP de PHP 8.2.22 VS16 x64 Thread Safe

3. Copia **SOLO estos archivos DLL** desde la carpeta raíz del ZIP a `C:\laragon\bin\php\php-8.2.22-Win32-vs16-x64\`:
   - `libcrypto-3-x64.dll`
   - `libnghttp2.dll`
   - `libssh2.dll`
   - `libssl-3-x64.dll`
   - `libcurl.dll`

4. **IMPORTANTE:** Sobrescribe los archivos existentes cuando te lo pregunte

5. Reinicia Laragon completamente

6. Verifica que todo funcione:
```bash
php -v
php -m | Select-String "curl"
php -m | Select-String "sqlsrv"
```

### 3. Trabajar con la Base de Datos SQL Server Existente

Dado que estamos trabajando con una base de datos SQL Server **ya existente**, tenemos dos enfoques:

#### Opción A: Generar Migraciones desde la BD (Recomendado para documentación)

Usaremos `kitloong/laravel-migrations-generator` para generar migraciones que documenten tu estructura actual:

```bash
# Generar migraciones de todas las tablas
php artisan migrate:generate --connection=sqlsrv

# O solo de tablas específicas
php artisan migrate:generate --connection=sqlsrv --tables="usuarios,productos,ordenes"
```

**Ventajas:**
- ✅ Documenta tu estructura de base de datos
- ✅ Útil para replicar la BD en otros ambientes
- ✅ Soporta SQL Server completamente

**Nota:** Estas migraciones son para **documentación** únicamente. **NO las ejecutes** con `php artisan migrate` ya que las tablas ya existen.

#### Opción B: Crear Modelos Eloquent Manualmente (Recomendado)

Para trabajar con SQL Server, es mejor crear los modelos manualmente. Ejemplo:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    protected $connection = 'sqlsrv'; // Conexión SQL Server
    protected $table = 'usuarios'; // Nombre de la tabla
    protected $primaryKey = 'id'; // Llave primaria
    public $timestamps = true; // Si tiene created_at y updated_at
    
    protected $fillable = [
        'nombre',
        'email',
        'telefono',
        // ... otros campos
    ];
    
    // Relaciones
    public function ordenes()
    {
        return $this->hasMany(Orden::class);
    }
}
```

#### 3.3 Comando para Listar Tablas de tu Base de Datos

Para ver qué tablas tienes disponibles:

```bash
php artisan tinker
>>> DB::connection('sqlsrv')->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
```

## 🔧 Datos de Conexión Requeridos

Por favor, proporciona los siguientes datos de tu SQL Server:

1. **Host** (ej: `localhost` o IP del servidor)
2. **Puerto** (usualmente `1433`)
3. **Nombre de la base de datos**
4. **Usuario**
5. **Contraseña**

## ✅ Verificar Conexión

Para verificar que la conexión funciona, usa **tinker** (nota los dos puntos dobles `::`)

```bash
php artisan tinker
>>> DB::connection()->getPdo();
```

Si no hay errores, la conexión está lista.

**Tip:** También puedes probar la ruta de prueba que creamos:

```bash
php artisan serve
```

Luego visita: http://localhost:8000/api/test-db

