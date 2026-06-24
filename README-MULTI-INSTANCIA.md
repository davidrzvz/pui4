# PUI Multi-Instancia por RFC

Esta guía explica cómo gestionar múltiples instancias independientes de PUI aisladas por RFC, desplegando cada una con sus propios contenedores, base de datos y configuración mediante Docker Compose y Nginx Proxy Manager.

## Cómo crear una instancia

Para crear una nueva instancia, ejecuta el script proporcionado indicando los datos del cliente:

```bash
bash scripts/create-pui-instance.sh \
  --rfc RFC123456 \
  --company "Mi Empresa SA de CV" \
  --port 8082 \
  --base-url http://192.168.1.10 \
  --api-password 'TestPui2026!*@82' \
  --api-user "PUI"
```

> **Nota Importante:** Cuando se pasen contraseñas con caracteres especiales (`! * @ $ & # %` etc.) desde la terminal, es fundamental encerrar el valor entre comillas simples (`'`) en lugar de dobles (`"`) para evitar que la terminal expanda los caracteres antes de enviarlos al script.

El script se encargará de:
1. Crear el directorio `/home/aplicaciones/pui/clientes/{RFC}`.
2. Clonar el repositorio.
3. Generar un archivo `.env` configurado.
4. Modificar el `docker-compose.yml` para evitar conflictos de nombres, puertos y redes.
5. Iniciar los contenedores Docker e instalar dependencias de la aplicación.

## Cómo agregarla en Nginx Proxy Manager

Al finalizar la creación de la instancia, el script te proporcionará la configuración que debes agregar en Nginx Proxy Manager (NPM).

En la interfaz de NPM:
1. Agrega un nuevo "Proxy Host".
2. **Domain Names**: Agrega el dominio o IP base (ej. `192.168.1.10`).
3. Ve a la pestaña **Custom locations** y agrega:
   - **Location**: `/{RFC}` (ej. `/RFC123456`)
   - **Scheme**: `http`
   - **Forward Hostname / IP**: `192.168.1.10`
   - **Forward Port**: El puerto configurado (ej. `8082`)
4. Ve a la pestaña **Advanced** y agrega:
```nginx
location /{RFC}/ {
    proxy_pass http://192.168.1.10:{PORT}/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location = /{RFC} {
    return 301 /{RFC}/;
}
```
*Asegúrate de reemplazar `{RFC}` y `{PORT}` por los valores reales.*

## Cómo validar login admin

1. Ingresa a la URL de la instancia: `http://192.168.1.10/{RFC}`
2. Deberías ver la pantalla de inicio de sesión de PUI.
3. Usa las credenciales por defecto (si fueron sembradas por los seeders):
   - **Usuario:** `admin@example.com` (o el configurado en tu seeder)
   - **Contraseña:** `password` (o la configurada en tu seeder)

## Cómo validar API PUI

Puedes validar que la API está funcionando correctamente utilizando cURL o Postman:

```bash
curl -X POST http://192.168.1.10/{RFC}/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "username": "PUI",
    "password": "TuPasswordSeguro123!"
  }'
```
Deberías recibir un Token JWT como respuesta si las credenciales coinciden con las indicadas en la creación.

## Cómo actualizar una instancia desde GitHub

Si hay cambios nuevos en el repositorio base y deseas aplicarlos a una instancia específica:

```bash
# 1. Ve al directorio de la instancia
cd /home/aplicaciones/pui/clientes/{RFC}

# 2. Descarga los últimos cambios de la rama principal (main o master)
git pull origin main

# 3. Actualiza dependencias de composer
docker compose exec app composer install --no-dev --optimize-autoloader

# 4. Corre migraciones nuevas si las hay
docker compose exec app php artisan migrate --force

# 5. Limpia cachés
docker compose exec app php artisan optimize:clear
```

## Cómo detener, reiniciar y respaldar una instancia

**Detener:**
```bash
cd /home/aplicaciones/pui/clientes/{RFC}
docker compose down
```

**Reiniciar:**
```bash
cd /home/aplicaciones/pui/clientes/{RFC}
docker compose restart
```

**Respaldar Base de Datos:**
```bash
cd /home/aplicaciones/pui/clientes/{RFC}
docker compose exec mysql sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > respaldo_pui_{RFC}.sql
```

**Restaurar Base de Datos:**
```bash
cd /home/aplicaciones/pui/clientes/{RFC}
docker compose exec -T mysql sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < respaldo_pui_{RFC}.sql
```
