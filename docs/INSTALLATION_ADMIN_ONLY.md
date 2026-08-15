# Instalacion paso a paso - Sistema UGDA FIA UES con un solo administrador

Esta guia instala el sistema sin ejecutar pruebas y sin cargar datos demo de solicitudes. El objetivo es dejar una instancia funcional con un unico usuario administrador general.

## 1. Requisitos

- Git.
- Docker Desktop o Docker Engine con Docker Compose.
- Puertos disponibles:
  - `8000` para Laravel.
  - `5173` para Vite.
  - `5433` para PostgreSQL publicado al host segun el compose actual.
  - `1025` y `8025` para Mailpit.

Nota: el `docker-compose.yml` actual referencia un `Dockerfile` en la raiz del proyecto. Antes de instalar desde cero confirme que exista. Si no existe, debe restaurarse el `Dockerfile` del proyecto o ajustarse el servicio `app` para usar una imagen compatible con PHP 8.2, extensiones PostgreSQL y Composer.

## 2. Clonar el proyecto

```bash
git clone <URL_DEL_REPOSITORIO> sistema-ugda-fia-ues
cd sistema-ugda-fia-ues
```

Si ya tiene el proyecto:

```bash
cd sistema-ugda-fia-ues
git pull
```

## 3. Crear archivo de entorno

```bash
cp .env.example .env
```

En Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Configure como minimo:

```env
APP_NAME="Sistema UGDA"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=sistema_ugda
DB_USERNAME=root
DB_PASSWORD=root

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@ugda.local
MAIL_FROM_NAME="Sistema UGDA"
```

## 4. Levantar servicios

```bash
docker compose up -d
```

Verifique:

```bash
docker compose ps
```

Servicios esperados:

- `app`
- `vite`
- `postgres`
- `mailpit`

## 5. Instalar dependencias

Si los contenedores no lo hacen automaticamente, ejecute:

```bash
docker compose exec -T app composer install --no-interaction --prefer-dist
docker compose exec -T vite npm install
```

## 6. Generar APP_KEY

```bash
docker compose exec -T app php artisan key:generate
```

## 7. Preparar almacenamiento

```bash
docker compose exec -T app mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
docker compose exec -T app php artisan storage:link
docker compose exec -T app php artisan optimize:clear
```

## 8. Crear base de datos limpia

No use `php artisan migrate --seed` si desea una instalacion sin datos demo. Ese comando ejecuta `DatabaseSeeder`, que crea usuarios y solicitudes de demostracion.

Ejecute solo migraciones:

```bash
docker compose exec -T app php artisan migrate:fresh --force
```

## 9. Sembrar catalogos base y administrador unico

Ejecute un solo seeder de instalacion limpia. Este seeder crea:

- roles base
- perfiles
- permisos por perfil
- catalogos documentales
- estados de solicitudes
- unidad UGDA
- usuario administrador general

```bash
docker compose exec -T app php artisan db:seed --class=AdminOnlySeeder --force
```

No ejecutar:

```bash
php artisan db:seed
php artisan db:seed --class=DatabaseSeeder
php artisan db:seed --class=RequestWorkflowDemoSeeder
php artisan db:seed --class=SystemNotificationDemoSeeder
```

## 10. Credenciales iniciales

El seeder anterior crea este usuario:

- Usuario: `admin-ugda@yopmail.com`
- Password: `password`

Recomendacion operativa: cambiar esta clave despues del primer ingreso.

## 11. Iniciar frontend

Si el contenedor Vite no esta corriendo:

```bash
docker compose up -d vite
```

Para produccion o validacion de assets:

```bash
docker compose exec -T vite npm run build
```

## 12. Acceder al sistema

Abra:

```text
http://127.0.0.1:8000
```

Health check:

```text
http://127.0.0.1:8000/up
```

Correo 2FA en Mailpit:

```text
http://127.0.0.1:8025
```

Flujo de ingreso:

1. Entrar a `http://127.0.0.1:8000/login`.
2. Usar `admin-ugda@yopmail.com` y `password`.
3. Abrir Mailpit en `http://127.0.0.1:8025`.
4. Copiar el codigo 2FA.
5. Verificar el codigo en la pantalla de login.

## 13. Comandos utiles de operacion

Ver contenedores:

```bash
docker compose ps
```

Ver logs:

```bash
docker compose logs -f app
docker compose logs -f vite
```

Limpiar cache Laravel:

```bash
docker compose exec -T app php artisan optimize:clear
```

Reiniciar servicios:

```bash
docker compose restart app vite
```

Apagar servicios sin borrar datos:

```bash
docker compose down
```

Reinstalacion total con borrado de base de datos:

```bash
docker compose down -v
docker compose up -d
docker compose exec -T app php artisan migrate:fresh --force
```

Despues de `down -v`, repita el seeder unico:

```bash
docker compose exec -T app php artisan db:seed --class=AdminOnlySeeder --force
```

## 14. Validacion final sin ejecutar tests

Esta guia no ejecuta pruebas. Para validar instalacion funcional sin test suite:

```bash
docker compose ps
curl http://127.0.0.1:8000/up
```

En navegador:

- `/login` carga correctamente.
- Mailpit recibe codigo 2FA.
- El admin entra al dashboard.
- El menu administrativo muestra unidades, usuarios, series, solicitudes y demas modulos permitidos al perfil `Administrador`.
