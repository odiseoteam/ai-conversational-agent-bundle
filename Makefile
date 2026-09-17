.PHONY: install test phpstan cs cs-fix check

install:
	composer install

test:
	vendor/bin/phpunit

phpstan:
	vendor/bin/phpstan analyse

cs:
	vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

check: cs phpstan test
