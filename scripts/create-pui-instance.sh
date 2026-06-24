#!/bin/bash
set +H

# Default values
API_USER="PUI"

# Parse arguments
while [[ "$#" -gt 0 ]]; do
    case $1 in
        --rfc) RFC="$2"; shift ;;
        --company) COMPANY="$2"; shift ;;
        --port) PORT="$2"; shift ;;
        --base-url) BASE_URL="$2"; shift ;;
        --api-password) API_PASSWORD="$2"; shift ;;
        --api-user) API_USER="$2"; shift ;;
        *) echo "Unknown parameter passed: $1"; exit 1 ;;
    esac
    shift
done

# Validate required parameters
if [ -z "$RFC" ] || [ -z "$COMPANY" ] || [ -z "$PORT" ] || [ -z "$BASE_URL" ] || [ -z "$API_PASSWORD" ]; then
    echo "Usage: $0 --rfc <RFC> --company <Company Name> --port <HTTP Port> --base-url <Base URL> --api-password <API Password> [--api-user <API User>]"
    exit 1
fi

RFC_UPPER=$(echo "$RFC" | tr '[:lower:]' '[:upper:]')
RFC_LOWER=$(echo "$RFC" | tr '[:upper:]' '[:lower:]')

echo "Creating instance for RFC: $RFC_UPPER"

# 1. Create directory
INSTANCE_DIR="/home/aplicaciones/pui/clientes/${RFC_UPPER}"
mkdir -p "$INSTANCE_DIR"

# 2. Clone repository
ORIGIN_URL=$(git config --get remote.origin.url)
if [ -z "$ORIGIN_URL" ]; then
    echo "Could not determine git origin URL. Make sure you run this script from the git repository."
    exit 1
fi

if [ ! -d "$INSTANCE_DIR/.git" ]; then
    echo "Cloning repository..."
    git clone "$ORIGIN_URL" "$INSTANCE_DIR"
else
    echo "Directory already contains a git repository. Pulling latest changes..."
    cd "$INSTANCE_DIR" && git pull origin main || git pull origin master
fi

cd "$INSTANCE_DIR"

# 3. Copy .env.example to .env
cp .env.example .env

# Cross-platform sed wrapper
if [[ "$OSTYPE" == "darwin"* ]]; then
  sedi() { sed -i '' "$@"; }
else
  sedi() { sed -i "$@"; }
fi

# 4. Configure .env
DB_PASSWORD=$(openssl rand -hex 16)
JWT_SECRET=$(openssl rand -base64 32)

env_quote() {
    local val="$1"
    echo "'${val//\'/\'\\\'\'}'"
}

set_env() {
    local key="$1"
    local val="$2"
    local qval=$(env_quote "$val")
    
    if grep -q "^${key}=" .env; then
        awk -v k="$key" -v v="$qval" -F= 'BEGIN { OFS="=" } $1==k { $0 = k "=" v } { print }' .env > .env.tmp && mv .env.tmp .env
    else
        echo "${key}=${qval}" >> .env
    fi
}

set_env "APP_URL" "${BASE_URL}/${RFC_UPPER}"
set_env "ASSET_URL" "${BASE_URL}/${RFC_UPPER}"
set_env "APP_ENV" "production"
set_env "APP_DEBUG" "false"
set_env "DB_DATABASE" "pui_${RFC_LOWER}"
set_env "DB_USERNAME" "pui_${RFC_LOWER}"
set_env "DB_PASSWORD" "${DB_PASSWORD}"

# Add custom PUI variables if they don't exist
grep -q "^PUI_INSTITUTION_NAME=" .env || echo "PUI_INSTITUTION_NAME=" >> .env
grep -q "^PUI_INSTITUTION_RFC=" .env || echo "PUI_INSTITUTION_RFC=" >> .env
grep -q "^PUI_INBOUND_USER=" .env || echo "PUI_INBOUND_USER=" >> .env
grep -q "^PUI_INBOUND_PASSWORD=" .env || echo "PUI_INBOUND_PASSWORD=" >> .env
grep -q "^PUI_INBOUND_JWT_SECRET=" .env || echo "PUI_INBOUND_JWT_SECRET=" >> .env

set_env "PUI_INSTITUTION_NAME" "${COMPANY}"
set_env "PUI_INSTITUTION_RFC" "${RFC_UPPER}"
set_env "PUI_INBOUND_USER" "${API_USER}"
set_env "PUI_INBOUND_PASSWORD" "${API_PASSWORD}"
set_env "PUI_INBOUND_JWT_SECRET" "${JWT_SECRET}"

# 5. Generate docker-compose.yml
sedi "s/container_name: pui-app/container_name: pui-${RFC_LOWER}-app/g" docker-compose.yml
sedi "s/container_name: pui-nginx/container_name: pui-${RFC_LOWER}-nginx/g" docker-compose.yml
sedi "s/container_name: pui-mysql/container_name: pui-${RFC_LOWER}-mysql/g" docker-compose.yml
sedi "s/container_name: pui-redis/container_name: pui-${RFC_LOWER}-redis/g" docker-compose.yml
sedi "s/container_name: pui-queue/container_name: pui-${RFC_LOWER}-queue/g" docker-compose.yml
sedi "s/container_name: pui-scheduler/container_name: pui-${RFC_LOWER}-scheduler/g" docker-compose.yml

sedi "s/image: pui-app/image: pui-${RFC_LOWER}-app/g" docker-compose.yml

sedi "s/pui_mysql_data/pui_${RFC_LOWER}_mysql_data/g" docker-compose.yml
sedi "s/pui_redis_data/pui_${RFC_LOWER}_redis_data/g" docker-compose.yml

sedi "s/pui-network/pui-${RFC_LOWER}-network/g" docker-compose.yml

# For port regex replacements:
if [[ "$OSTYPE" == "darwin"* ]]; then
  sed -i '' -E "s/\"[0-9]+:80\"/\"${PORT}:80\"/g" docker-compose.yml
  sed -i '' -E "s/- [0-9]+:80/- ${PORT}:80/g" docker-compose.yml
else
  sed -i -r "s/\"[0-9]+:80\"/\"${PORT}:80\"/g" docker-compose.yml
  sed -i -r "s/- [0-9]+:80/- ${PORT}:80/g" docker-compose.yml
fi

# 6. Execute docker commands
echo "Starting docker containers..."
docker compose up -d --build
echo "Installing dependencies..."
docker compose exec app composer install --no-dev --optimize-autoloader
echo "Generating app key..."
docker compose exec app php artisan key:generate --force
echo "Running migrations..."
docker compose exec app php artisan migrate:fresh --seed
echo "Linking storage..."
docker compose exec app php artisan storage:link
echo "Optimizing app..."
docker compose exec app php artisan optimize:clear
echo "Setting permissions..."
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache

echo "---------------------------------------------------------"
echo "Instancia creada exitosamente para \$COMPANY (\$RFC_UPPER)"
echo "---------------------------------------------------------"
echo "Configuración a agregar manualmente en Nginx Proxy Manager:"
echo ""
echo "Domain:"
echo "\$(echo \$BASE_URL | awk -F/ '{print \$3}' | cut -d: -f1)"
echo ""
echo "Custom location:"
echo "/\${RFC_UPPER}"
echo ""
echo "Forward:"
echo "http://192.168.1.10:\${PORT}"
echo ""
echo "Advanced:"
echo "location /\${RFC_UPPER}/ {"
echo "    proxy_pass http://192.168.1.10:\${PORT}/;"
echo "    proxy_set_header Host \\\$host;"
echo "    proxy_set_header X-Real-IP \\\$remote_addr;"
echo "    proxy_set_header X-Forwarded-For \\\$proxy_add_x_forwarded_for;"
echo "    proxy_set_header X-Forwarded-Proto \\\$scheme;"
echo "}"
echo ""
echo "location = /\${RFC_UPPER} {"
echo "    return 301 /\${RFC_UPPER}/;"
echo "}"
echo "---------------------------------------------------------"
