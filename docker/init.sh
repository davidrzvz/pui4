#!/bin/bash

echo "Instalando dependencias de Composer..."
composer install --no-dev --optimize-autoloader

echo "Generando key de la aplicacion..."
php artisan key:generate --force

echo "Ejecutando migraciones y seeders..."
php artisan migrate --force --seed

echo "Generando enlace simbolico de storage..."
php artisan storage:link

echo "Optimizando Laravel..."
php artisan optimize:clear
php artisan optimize

echo "PUI listo"
