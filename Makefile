.PHONY: up down reset setup e2e logs php-lint

up:
	docker compose up -d

down:
	docker compose down

reset:
	docker compose down -v

setup:
	./bin/setup-local.sh

e2e:
	./bin/e2e.sh

logs:
	docker compose logs -f wordpress mock-lnurl

php-lint:
	find woocommerce-lightning-lud21 -name '*.php' -print0 | xargs -0 -n1 php -l
