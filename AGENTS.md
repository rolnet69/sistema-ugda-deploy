# AGENTS.md

## Proyecto

Sistema UGDA FIA UES en Laravel 12 + Vue 3 + Vite + PrimeVue.

En esta rama el proyecto es una SPA con:

- login con 2FA por correo
- dashboard
- CRUD de unidades
- CRUD de usuarios
- listado de solicitudes
- vista para crear nueva solicitud

## Stack actual

- Backend: Laravel 12
- Frontend: Vue 3
- UI: PrimeVue, PrimeIcons, PrimeFlex
- Build: Vite
- Base de datos: PostgreSQL
- Correo local: Mailpit

## Estructura importante

- Backend routes: [routes/web.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/routes/web.php)
- Router frontend: [resources/js/router.js](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/resources/js/router.js)
- Auth 2FA: [app/Http/Controllers/AuthController.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Http/Controllers/AuthController.php)
- Usuarios: [app/Http/Controllers/UserController.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Http/Controllers/UserController.php)
- Unidades: [app/Http/Controllers/UnitController.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Http/Controllers/UnitController.php)
- Modelo de usuario: [app/Models/User.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Models/User.php)
- Seeder base: [database/seeders/DatabaseSeeder.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/seeders/DatabaseSeeder.php)
- Factory de usuarios: [database/factories/UserFactory.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/factories/UserFactory.php)

## Estándar del proyecto

### Idioma

- Negocio, etiquetas y textos visibles al usuario: español
- Código técnico: mezcla de inglés en entidades y base de datos, con comentarios y mensajes de validación en español
- Regla práctica:
  - modelos, tablas, foreign keys y nombres técnicos: inglés
  - textos de UI, labels, títulos, mensajes y flujo funcional: español

### Modelos

Patrón actual:

- Modelo en singular y PascalCase
- Ejemplos:
  - [User.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Models/User.php)
  - [Unit.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Models/Unit.php)
  - [Transfer.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Models/Transfer.php)
  - [TransferBox.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Models/TransferBox.php)

Reglas que ya sigue la rama:

- usar nombres de entidades en inglés
- declarar `fillable` explícito cuando el modelo ya tiene datos de negocio
- declarar `casts` para booleanos y fechas cuando aplica
- relaciones con nombres cortos y claros:
  - `unit()`
  - `parents()`
  - `children()`

Si se crea un modelo nuevo, seguir:

- nombre singular en PascalCase
- tabla plural automática en snake_case
- relaciones en camelCase

### Base de datos

Patrón actual:

- tablas en plural y snake_case
- foreign keys en singular con sufijo `_id`
- columnas booleanas tipo `is_active`
- fechas en snake_case tipo `request_date`

Ejemplos:

- [create_units_table.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/migrations/2026_01_30_172458_create_units_table.php)
- [create_transfers_table.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/migrations/2026_02_02_172904_create_transfers_table.php)
- [create_transfer_boxes_table.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/migrations/2026_02_02_172921_create_transfer_boxes_table.php)

Reglas prácticas:

- no usar nombres de tablas en español
- no usar claves foráneas ambiguas
- cuando una tabla dependa de otra, preferir `foreignId(...)->constrained()`
- en tablas pivote o de dependencia, usar nombres descriptivos en snake_case

### Usuarios

La entidad `users` en esta rama trabaja sin columna `name`.

Campos base actuales:

- `first_name`
- `second_name`
- `first_last_name`
- `second_last_name`
- `carnet`
- `email`
- `password`
- `role`
- `unit_id`
- `is_active`

Nunca reintroducir `name` en:

- seeders
- factories
- validaciones
- formularios
- imports de datos

### Controladores

Patrón actual:

- un controlador por módulo principal
- métodos simples tipo CRUD o acción puntual
- validación dentro del controlador
- respuestas JSON para consumo Vue

Ejemplos:

- [AuthController.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Http/Controllers/AuthController.php)
- [UnitController.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Http/Controllers/UnitController.php)
- [UserController.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/app/Http/Controllers/UserController.php)

Reglas prácticas:

- mantener nombres de controladores en singular del recurso más `Controller`
- preferir validación explícita por método
- devolver JSON para endpoints internos de la SPA
- si una acción es de flujo, usar nombres claros como `login`, `verify2FA`, `store`, `update`, `destroy`

### Rutas

Backend:

- rutas internas tipo API bajo `/api/...` pero definidas en `web.php`
- rutas de autenticación fuera del prefijo `/api`

Frontend:

- rutas SPA en español para módulos del negocio
- ejemplos:
  - `/usuarios`
  - `/unidades`
  - `/solicitudes`
  - `/solicitudes/crear`

### Frontend Vue

Patrón actual:

- componentes de vistas en PascalCase
- carpeta principal:
  - [resources/js/components/views](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/resources/js/components/views)
- nombres actuales:
  - `Usuarios.vue`
  - `Unidades.vue`
  - `Solicitudes.vue`
  - `NuevaSolicitud.vue`

Reglas prácticas:

- nombre del archivo en PascalCase
- nombres de vistas orientados al negocio, normalmente en español
- router centralizado en [router.js](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/resources/js/router.js)
- formularios y pantallas administrativas deben mantener la línea gráfica ya existente

### Validaciones y mensajes

Patrón actual:

- reglas técnicas en backend
- mensajes al usuario en español
- regex y límites definidos directamente en el controlador cuando el módulo es pequeño

### Qué mantener si se amplía el proyecto

- inglés para entidades técnicas
- español para experiencia de usuario
- snake_case en base de datos
- PascalCase en modelos y componentes Vue
- camelCase en métodos y relaciones
- JSON como formato de respuesta para la SPA

## Hechos clave de esta rama

- El README actual es el default de Laravel y no describe el proyecto real.
- El proyecto ahora sí incluye [docker-compose.yml](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/docker-compose.yml).
- La configuración Docker usa:
  - app Laravel
  - Vite
  - PostgreSQL 16
  - Mailpit
- La app responde en:
  - `http://127.0.0.1:8000`
  - health check: `http://127.0.0.1:8000/up`
- Vite queda disponible en:
  - `http://127.0.0.1:5173`
- Mailpit queda disponible en:
  - `http://127.0.0.1:8025`

## Cómo levantar esta rama

Configuración inicial incluida:

- Compose: [docker-compose.yml](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/docker-compose.yml)
- Arranque app: [docker/start-app.sh](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/docker/start-app.sh)
- Arranque Vite: [docker/start-vite.sh](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/docker/start-vite.sh)

Paso a paso recomendado:

1. Si ya tienes contenedores viejos ocupando los puertos `8000`, `5173`, `5432`, `1025` o `8025`, apágalos primero.
2. Desde la raíz del proyecto ejecuta:
   - `docker compose up -d`
3. Espera a que el contenedor `app` instale dependencias, corra migraciones y ejecute seeders.
4. Abre:
   - `http://127.0.0.1:8000`
5. Para revisar el correo 2FA:
   - `http://127.0.0.1:8025`

Notas:

- `docker/start-app.sh` hace `composer install`, `php artisan migrate --seed --force` y `php artisan storage:link`.
- `docker/start-vite.sh` hace `npm install` y levanta Vite en `5173`.
- Si el agente necesita reiniciar los servicios sin eliminar datos:
  - `docker compose restart`
- Para aplicar migraciones o seeders puntuales, revisar primero su impacto y ejecutar solo el comando especifico necesario.

## Datos de acceso sembrados

- Usuario: `admin-ugda@yopmail.com`
- Password: `password`

## Reglas importantes para no romper la rama

### Proteccion de la base de datos

- No eliminar la base de datos bajo ninguna circunstancia sin autorizacion explicita y previa del usuario.
- Nunca eliminar, resetear, recrear, vaciar ni sobrescribir la base de datos existente.
- No ejecutar `migrate:fresh`, `db:wipe`, `docker compose down -v`, `DROP DATABASE`, `TRUNCATE` ni comandos equivalentes durante las tareas del proyecto.
- Conservar siempre los datos existentes y preferir migraciones incrementales o cambios puntuales.
- Cualquier accion destructiva sobre la base de datos requiere autorizacion explicita del usuario antes de ejecutarse.

- La tabla `users` ya no tiene columna `name`.
- Usar estos campos en usuarios:
  - `first_name`
  - `second_name`
  - `first_last_name`
  - `second_last_name`
  - `carnet`
  - `email`
  - `role`
  - `unit_id`
  - `is_active`
- No volver a usar factories o seeders que intenten insertar `name`.
- Si algo falla en seeds o tests de usuarios, revisar primero:
  - [database/seeders/DatabaseSeeder.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/seeders/DatabaseSeeder.php)
  - [database/factories/UserFactory.php](/C:/Users/aguil/OneDrive/Documents/proyectos/sistema-ugda-fia-ues/database/factories/UserFactory.php)

## Flujo de autenticación

- `POST /login-auth`
  - valida correo y password
  - genera código 2FA
  - lo envía por correo
- `POST /login-verify`
  - valida código
  - inicia sesión
  - responde con redirect a `/dashboard`

## APIs actuales

- `GET /api/user`
- `GET|POST|PUT|DELETE /api/units`
- `GET|POST|PUT /api/users`

## Rutas frontend actuales

- `/login`
- `/dashboard`
- `/unidades`
- `/usuarios`
- `/solicitudes`
- `/solicitudes/crear`

## Nota para futuros agentes

Antes de implementar cambios grandes:

- revisar esta rama actual, no asumir que tiene el mismo módulo documental avanzado de otras ramas
- confirmar si el frontend de solicitudes usa `Solicitudes.vue` y `NuevaSolicitud.vue`
- confirmar si el contenedor `sistema-ugda-app` sigue disponible antes de correr migraciones o artisan

## Preferencia operativa persistente

- No ejecutar migraciones de base de datos durante las tareas de este proyecto, salvo que el usuario lo solicite expresamente.
- Comando de referencia para la última migración aplicada: `docker compose exec app php artisan migrate --force`.
