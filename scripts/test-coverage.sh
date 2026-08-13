#!/usr/bin/env bash
# Coverage gate (задача 7.8): полный набор (paratest, без smoke/load) + clover + проверка порога.
#
# Использование:
#   bash scripts/test-coverage.sh [threshold]      # threshold в %, по умолчанию 80
#
# Порог также можно задать через COVERAGE_THRESHOLD.
# Выход: 0 — покрытие >= порога; 1 — ниже порога / ошибка.

set -euo pipefail

THRESHOLD="${1:-${COVERAGE_THRESHOLD:-80}}"
COVERAGE_DIR="var/coverage"
CLOVER="${COVERAGE_DIR}/clover.xml"
PROCESSES="${PARATEST_PROCESSES:-$(getconf _NPROCESSORS_ONLN)}"

echo "== Coverage gate (задача 7.8) =="
echo "Threshold: ${THRESHOLD}% lines, processes: ${PROCESSES}"

mkdir -p "${COVERAGE_DIR}"
rm -f "${CLOVER}" "${COVERAGE_DIR}"/coverage-*.txt

# Подготовка тестовых БД для ${PROCESSES} воркеров (как test:parallel)
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

# Покрытие собирается pcov'ом (docker-php-ext-enable pcov, задача 7.8). PHPUnit
# 13 запускает сборку при наличии драйвера; pcov включается автоматически, когда
# передан --coverage-*. Память поднята (сборка покрытия требует > 128M).
php -d memory_limit=1G vendor/bin/paratest \
    --processes="${PROCESSES}" \
    --exclude-group=smoke \
    --coverage-clover="${CLOVER}" \
    --coverage-text="${COVERAGE_DIR}/coverage.txt" \
    --only-summary-for-coverage-text

php scripts/check-coverage.php "${CLOVER}" "${THRESHOLD}"
