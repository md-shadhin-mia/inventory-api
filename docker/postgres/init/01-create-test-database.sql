-- Consumed by postgres:16's docker-entrypoint-initdb.d on FIRST BOOT ONLY
-- (i.e. only while the pgdata volume is empty).
--
-- phpunit.xml pins DB_DATABASE=inventory_testing, so the suite needs this
-- database to exist before `./vendor/bin/pest` can run inside the container.
--
-- On an existing volume this file is skipped; create it by hand instead:
--   docker compose exec postgres createdb -U postgres inventory_testing
CREATE DATABASE inventory_testing;
