.PHONY: install test phpstan cs cs-fix deptrac composer-unused check

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

deptrac:
	vendor/bin/deptrac analyse --no-progress

composer-unused:
	vendor/bin/composer-dependency-analyser

check: cs phpstan deptrac composer-unused test
