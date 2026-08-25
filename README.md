# Inventory Management API

Laravel 13 REST API for inventory management, stock adjustments, transfers, audit history, and role-based access control.

## Requirements

- PHP 8.4 with `pdo_pgsql` and `phpredis`
- Composer
- PostgreSQL 16
- Redis 7

## Local setup

Install dependencies and configure the application:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the application and test databases in postgresql:

```bash
createdb -U postgres inventory
createdb -U postgres inventory_testing
```

Update the database and Redis settings in `.env` if your local credentials differ, then run migrations and seed the database:

```bash
php artisan migrate
php artisan db:seed
```

Start the API and queue worker in separate terminals:

```bash
php artisan serve
php artisan queue:work redis --tries=3 --backoff=5 --timeout=60
```

The API is available at `http://localhost:8000`.

## Docker setup

Docker Compose starts the API, queue worker, PostgreSQL, and Redis. It also runs migrations automatically:

```bash
cp .env.example .env
php artisan key:generate --show
# Set APP_KEY in .env to the generated value.
docker compose up -d --build
```

Check the API health endpoint:

```bash
curl http://localhost:8000/api/v1/health
```

To seed the database, use the development image:

```bash
BUILD_TARGET=dev docker compose up -d --build
docker compose exec app php artisan db:seed
```

Default Docker ports are API `8000`, PostgreSQL `5434`, and Redis `6381`. Override them with `APP_PORT`, `POSTGRES_PORT`, and `REDIS_PORT_HOST` in `.env`.

## Tests

The test suite uses the separate `inventory_testing` PostgreSQL database:

```bash
./vendor/bin/pest
```

Do not use `--parallel`; the concurrency tests require real process-level database locking.

## Seeded accounts

All seeded accounts use the password `password`.

| Email | Role |
|---|---|
| `admin@example.com` | `admin` |
| `manager@example.com` | `warehouse_manager` |
| `auditor@example.com` | `auditor` |

## API reference

The complete API contract, endpoints, authentication, request validation, responses, and error formats are defined in [`openapi/openapi.yaml`](openapi/openapi.yaml).

When the application is running, the same specification is available through Swagger UI at [`http://localhost:8000/api/documentation`](http://localhost:8000/api/documentation). The root path (`/`) redirects there.
