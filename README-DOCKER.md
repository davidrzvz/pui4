# PUI Docker Deployment

Este documento describe cómo desplegar la Plataforma Única de Identificación (PUI) en un servidor Linux usando Docker para un ambiente de producción.

## Requisitos
- Docker
- Docker Compose

## Primer Despliegue

1. **Configurar las variables de entorno:**
```bash
cp .env.example .env
```
*(Asegúrate de editar el archivo `.env` para cambiar `PUI_INBOUND_JWT_SECRET` y configurar otras variables obligatorias).*

2. **Construir las imágenes y levantar los contenedores:**
```bash
docker compose up -d --build
```

3. **Inicializar la aplicación:**
Ejecuta el script de inicialización automática dentro del contenedor:
```bash
docker compose exec app bash docker/init.sh
```

## Uso Normal

Para iniciar los contenedores en uso diario:
```bash
docker compose up -d
```

Para detener los contenedores:
```bash
docker compose down
```

## Backups Recomendados
Para evitar pérdida de datos, se recomienda hacer copias de seguridad de:
- El volumen de base de datos (`mysql volume`)
- El archivo de configuración principal (`.env`)

## Validación y Uso
Puedes comprobar que la plataforma y la configuración Nginx funcionan correctamente accediendo a las siguientes URLs:

- **Panel Administrativo:**
  http://187.141.101.36:8081/admin

- **API Pública Gobierno (Endpoint Login):**
  http://187.141.101.36:8081/api/v1/pui/login

*(Nota: Los comandos `curl` o las llamadas de Postman desde el motor de búsqueda del Gobierno deben dirigir las peticiones al puerto `8081` de la IP pública)*.
