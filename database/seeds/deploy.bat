@echo off
REM =====================================================
REM Script de Deploy - Configuración de Menús y Permisos
REM Sistema de Gestión - Colegio Médico del Perú
REM =====================================================

echo.
echo ========================================
echo DEPLOY: Configuracion de Menus y Permisos
echo ========================================
echo.

REM Solicitar credenciales
set /p SERVER="Servidor SQL Server [172.17.16.16]: "
if "%SERVER%"=="" set SERVER=172.17.16.16

set /p DATABASE="Nombre de la Base de Datos: "
if "%DATABASE%"=="" (
    echo ERROR: Debe especificar el nombre de la base de datos
    pause
    exit /b 1
)

set /p USERNAME="Usuario SQL: "
if "%USERNAME%"=="" (
    echo ERROR: Debe especificar el usuario
    pause
    exit /b 1
)

set /p PASSWORD="Contraseña: "
if "%PASSWORD%"=="" (
    echo ERROR: Debe especificar la contraseña
    pause
    exit /b 1
)

echo.
echo Conectando a: %SERVER%
echo Base de datos: %DATABASE%
echo Usuario: %USERNAME%
echo.

REM Ejecutar el script SQL
sqlcmd -S %SERVER% -U %USERNAME% -P %PASSWORD% -d %DATABASE% -i perfiles_y_menus.sql

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================
    echo DEPLOY COMPLETADO EXITOSAMENTE
    echo ========================================
    echo.
) else (
    echo.
    echo ========================================
    echo ERROR EN EL DEPLOY
    echo ========================================
    echo Codigo de error: %ERRORLEVEL%
    echo.
    echo Revisar:
    echo - Credenciales de acceso
    echo - Conectividad al servidor
    echo - Permisos del usuario
    echo.
)

pause

