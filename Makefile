UID := $(shell id -u)
GID := $(shell id -g)
export UID
export GID

DC := docker compose
PHP := $(DC) exec php

.PHONY: build up down sh logs install migrate test test-http cs xdebug

build: ## Build the PHP image
	$(DC) build

up: ## Start the stack (app on http://localhost:8080)
	$(DC) up -d

down: ## Stop and remove the stack
	$(DC) down

sh: ## Shell into the PHP container
	$(PHP) bash

logs:
	$(DC) logs -f

install: ## composer install
	$(PHP) composer install

migrate: ## Run migrations for dev + test databases
	$(PHP) php bin/console doctrine:migrations:migrate -n
	$(PHP) php bin/console doctrine:migrations:migrate -n --env=test

test: ## Run the full test suite
	$(PHP) php bin/phpunit

test-xdebug: ## Run the suite with Xdebug on (step debugging)
	$(DC) exec -e XDEBUG_MODE=debug -e XDEBUG_SESSION=1 php php bin/phpunit
