#!/usr/bin/env bash
set -euo pipefail

PROCESSES="${PARATEST_PROCESSES:-$(getconf _NPROCESSORS_ONLN)}"

echo "Preparing test databases for ${PROCESSES} parallel workers..."

php bin/console doctrine:database:drop --force --if-exists --env=test
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test

for i in $(seq 1 "${PROCESSES}"); do
    TEST_TOKEN="${i}" php bin/console doctrine:database:drop --force --if-exists --env=test
    TEST_TOKEN="${i}" php bin/console doctrine:database:create --env=test
    TEST_TOKEN="${i}" php bin/console doctrine:migrations:migrate --no-interaction --env=test
done

php bin/console app:test:redis-cleanup --env=test

echo "Running parallel tests with ${PROCESSES} processes (smoke/load tests excluded, run them via composer test:smoke)..."
# Память поднята: дефолтный memory_limit контейнера (128M) не выдерживает
# smoke/load-сценарии и гидратацию Doctrine на больших наборах. Значение
# переопределяется через PHP_MEMORY_LIMIT (make test PHP_MEMORY_LIMIT=2G).
exec php -d memory_limit="${PHP_MEMORY_LIMIT:-1G}" vendor/bin/paratest \
    --processes="${PROCESSES}" --exclude-group=smoke "$@"
