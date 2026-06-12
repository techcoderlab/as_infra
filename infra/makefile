# Variables
COMPOSE_FILE := docker-compose.yml
PROJECT_NAME := microservices-stack
AS_LARAVEL_CONTAINER := business-agency-saas-api

# Default command
.DEFAULT_GOAL := help

.PHONY: help build build-fresh up down stop restart logs ps prune shell \
        migrate seed rollback optimize tinker list-routes \
        show-logs clear-logs test-app \
        composer-i npm-i ide-helper format fresh-db

# --- Single Service Helper ---
# If 's' is provided (e.g., s=gateway), S_FLAG becomes 'gateway'
S_FLAG := $(if $(s), $(s),)

help: ## Show this help message
	@echo "Usage: make [command] [s=service_name]"
	@echo ""
	@echo "Docker Commands:"
	@echo "  build         Build images (all or s=name)"
	@echo "  build-fresh   Build without cache (all or s=name)"
	@echo "  up            Start containers (all or s=name)"
	@echo "  down          Stop and REMOVE EVERYTHING"
	@echo "  stop          Stop and remove SINGLE service (requires s=name)"
	@echo "  restart       Restart containers (all or s=name)"
	@echo "  logs          Follow logs (all or s=name)"
	@echo "  ps            Check status of all containers"
	@echo "  shell         Jump into container (requires s=name)"
	@echo ""
	@echo "Agency SAAS (Laravel) Dev Commands:"
	@echo "  migrate | fresh-db | seed | tinker | optimize | format | ide-helper"
	@echo "  composer-i [p=pkg] | npm-i [p=pkg] | show-logs | list-routes"

# --- Docker Management ---

build: ## Build images
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) build $(S_FLAG)

build-fresh: ## Build images without cache
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) build --no-cache $(S_FLAG)

up: ## Start stack or service
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) up -d $(S_FLAG)

down: ## Tear down the WHOLE stack (networks, containers, everything)
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) down

stop: ## Stop and remove a single container surgically (e.g., make stop s=gateway)
	@if [ -z "$(s)" ]; then echo "Error: s=service_name is required for 'make stop'"; exit 1; fi
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) stop $(s)
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) rm -f $(s)

restart: ## Restart (all or s=name)
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) restart $(S_FLAG)

logs: ## Tail logs (all or s=name)
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) logs -f $(S_FLAG)

stats: ## Show stats (all or s=name)
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) stats $(S_FLAG)

ps: ## Check status of all containers
	docker compose -f $(COMPOSE_FILE) -p $(PROJECT_NAME) ps $(S_FLAG)

shell: ## Shell into container (e.g., make shell s=redis)
	@if [ -z "$(s)" ]; then echo "Error: s=service_name is required for 'make shell'"; exit 1; fi
	docker exec -it $(s) sh

# --- Laravel Industry Standards ---

composer-i: ## Install dependencies (use p=name for specific pkg)
	docker exec -it $(AS_LARAVEL_CONTAINER) composer $(if $(p),require $(p),install)

npm-i: ## Install dependencies (use p=name for specific pkg)
	docker exec -it $(AS_LARAVEL_CONTAINER) npm install $(p)

fresh-db: ## Wipe and re-migrate
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan migrate:fresh --seed

format: ## Run Laravel Pint
	docker exec -it $(AS_LARAVEL_CONTAINER) ./vendor/bin/pint

ide-helper: ## Generate IDE helpers
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan ide-helper:generate
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan ide-helper:models -N
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan ide-helper:meta

migrate: ## Run migrations
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan migrate
rollback: ## Run rollback
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan migrate:rollback

seed: ## Run seeders
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan db:seed

optimize: ## Clear cache/optimize
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan optimize:clear

dump-autoload: ## Dump autoload
	docker exec -it $(AS_LARAVEL_CONTAINER) composer dump-autoload

tinker: ## Run Tinker
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan tinker

show-logs: ## Tail Laravel logs
	docker exec -it $(AS_LARAVEL_CONTAINER) tail -f storage/logs/laravel.log

list-routes: ## List routes
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan route:list

queue-clear: ## Clear queue
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan queue:clear redis --queue=$(or $(q),default)

queue-check: ## Check active jobs in the queue
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan queue:monitor redis:$(or $(q),default)

queue-restart: ## Clear queue
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan queue:restart

prune: ## Prune docker system
	docker system prune -f

stress-ai:
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan test:stress lead-qualifier-agent --count=$(or $(c),5)

stress-ai-poison:
	docker exec -it $(AS_LARAVEL_CONTAINER) php artisan test:stress lead-qualifier-agent --count=$(or $(c),5) --poison