@echo off
echo.
echo ========================================
echo   VERIFICACION DE BASE DE DATOS
echo   Respira-CMP - SQL Server
echo ========================================
echo.

echo [1/4] Verificando extensiones PHP...
php -m | findstr sqlsrv
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] No se encontraron las extensiones de SQL Server
    echo Instala php_sqlsrv y php_pdo_sqlsrv
    pause
    exit /b 1
)
echo [OK] Extensiones encontradas
echo.

echo [2/4] Verificando version de PHP...
php -v
echo.

echo [3/4] Probando conexion a la base de datos...
php artisan tinker --execute="dump(DB::connection()->getPdo() ? 'Conexion OK' : 'Error');"
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] No se pudo conectar a la base de datos
    echo Verifica tu archivo .env
    pause
    exit /b 1
)
echo.

echo [4/4] Listando tablas disponibles...
php artisan db:tables
echo.

echo ========================================
echo   VERIFICACION COMPLETADA
echo ========================================
echo.
echo Comandos disponibles:
echo   php artisan db:tables --details
echo   php artisan db:generate-models
echo.
pause

