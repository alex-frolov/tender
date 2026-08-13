# Tender Platform — Makefile
# Команды выполняются на ХОСТЕ (вне docker-контейнеров): docker compose / docker compose exec.
# Все PHP/Composer/консольные команды идут внутрь контейнера app.

COMPOSE      := docker compose
EXEC         := $(COMPOSE) exec -T
APP_SERVICE  := app

.DEFAULT_GOAL := help
.PHONY: help up down restart ps logs logs-app shell console composer \
        migrate migrate-test migrate-all test-prepare \
        lint cs-fixer phpstan arkitect test test-unit test-parallel test-smoke test-coverage quality \
        load load-prepare load-bids load-catalog load-sse load-webhooks \
        prod-up prod-down prod-migrate prod-smoke deploy \
        cache-clear

help: ## Показать список доступных команд
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---------- Окружение ----------

up: ## Поднять весь dev-стек (app, web:8080, db:54329, redis:56379, rabbitmq, mercure, mailpit, worker, webhooks, scheduler)
	$(COMPOSE) up -d

down: ## Остановить и удалить контейнеры
	$(COMPOSE) down

restart: ## Перезапустить контейнеры
	$(COMPOSE) restart

ps: ## Статус контейнеров
	$(COMPOSE) ps

logs: ## Логи всех сервисов (--follow)
	$(COMPOSE) logs -f

logs-app: ## Логи только приложения app
	$(COMPOSE) logs -f $(APP_SERVICE)

shell: ## Интерактивный shell внутри контейнера app
	$(COMPOSE) exec $(APP_SERVICE) bash

# ---------- Консоль и Composer ----------

console: ## Выполнить bin/console: make console CMD="cache:clear"
	$(EXEC) $(APP_SERVICE) php bin/console $(CMD)

composer: ## Выполнить composer: make composer ARGS="require ..."
	$(EXEC) $(APP_SERVICE) composer $(ARGS)

# ---------- Миграции ----------

migrate: ## Применить миграции в dev-БД (tender)
	$(EXEC) $(APP_SERVICE) php bin/console doctrine:migrations:migrate --no-interaction

migrate-test: ## Применить миграции в test-БД (tender_test)
	$(EXEC) $(APP_SERVICE) php bin/console doctrine:migrations:migrate --no-interaction --env=test

migrate-all: migrate migrate-test ## Миграции в обеих БД (dev + test)

# ---------- Качество ----------

test-prepare: ## Сбросить и подготовить test-БД (drop+create+migrate, чистка Redis)
	$(EXEC) $(APP_SERVICE) composer test:prepare

lint: ## php -l по src и tests
	$(EXEC) $(APP_SERVICE) composer lint

cs-fixer: ## PHP CS Fixer: применить правки (fix + diff)
	$(EXEC) $(APP_SERVICE) composer cs-fixer

cs-fixer-check: ## PHP CS Fixer: проверка без изменений (dry-run)
	$(EXEC) $(APP_SERVICE) composer cs-fixer:check

phpstan: ## PHPStan level max (поднятый memory-limit)
	$(EXEC) $(APP_SERVICE) php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

arkitect: ## PHPArkitect: слои + границы модулей
	$(EXEC) $(APP_SERVICE) composer arkitect

test: ## PHPUnit, весь набор, последовательно (сам готовит test-БД)
	$(EXEC) $(APP_SERVICE) composer test

test-unit: ## Только Unit-тесты
	$(EXEC) $(APP_SERVICE) composer test:unit

test-parallel: ## PHPUnit через ParaTest (параллельно; smoke/load тесты исключены)
	$(EXEC) $(APP_SERVICE) composer test:parallel

test-smoke: ## Smoke/load тесты, последовательно, один за другим (после test-parallel)
	$(EXEC) $(APP_SERVICE) composer test:smoke

test-coverage: ## PHPUnit с покрытием + проверка порога ≥80%
	$(EXEC) $(APP_SERVICE) composer test:coverage

quality: ## Весь конвейер: lint + phpstan + arkitect + test
	$(EXEC) $(APP_SERVICE) composer quality

# ---------- Нагрузочные тесты ----------

LOAD_COMPOSE := $(COMPOSE) -f docker-compose.yml -f docker-compose.load.yml

load: ## Нагрузочные тесты: все сценарии (ставки/каталог/SSE/webhooks)
	bash load/run.sh

load-prepare: ## Подготовить нагрузочный стейт + поднять load-стек
	$(LOAD_COMPOSE) up -d app worker webhooks loadreceiver
	$(LOAD_COMPOSE) exec -T app php bin/console app:load:prepare --suppliers=20 --catalog=5000

load-bids: ## Сценарий «ставки»
	bash load/run.sh bids

load-catalog: ## Сценарий «каталог»
	bash load/run.sh catalog

load-sse: ## Сценарий «SSE»
	bash load/run.sh sse

load-webhooks: ## Сценарий «webhooks»
	bash load/run.sh webhooks

# ---------- Прод-развёртывание ----------

PROD_COMPOSE := docker compose --env-file .env.prod -f docker-compose.prod.yml

prod-up: ## Поднять прод-стек (docker-compose.prod.yml; требует .env.prod)
	$(PROD_COMPOSE) up -d

prod-down: ## Остановить прод-стек
	$(PROD_COMPOSE) down

prod-migrate: ## Миграции прод-БД (одноразовый сервис migrate, profile deploy)
	$(PROD_COMPOSE) --profile deploy run --rm migrate

prod-smoke: ## Deploy-smoke против прод-стека (health/live, health/ready, API-контракт)
	bash scripts/deploy-smoke.sh http://localhost:$(or $(HTTP_PORT),8080)

deploy: ## Полный деплой: build → migrate → up → healthchecks → smoke
	bash scripts/deploy.sh

# ---------- Прочее ----------

cache-clear: ## Очистить кэш dev
	$(EXEC) $(APP_SERVICE) php bin/console cache:clear
