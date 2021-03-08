SF_FRESH_TARGETS := up build sf-cw sf-about sf-open
FIX_TARGETS += fix-symfony
LINT_PHP_TARGETS += lint-symfony

PHONY += sf-about
sf-about: ## Displays information about the current project
	$(call sf_console_on_${RUN_ON},about)

PHONY += sf-cc
sf-cc: ## Clear Symfony caches
	$(call step,Clear Symfony caches...)
	$(call sf_console_on_${RUN_ON},cache:clear)

PHONY += sf-cw
sf-cw: ## Warm Symfony caches
	$(call step,Warm Symfony caches...)
	$(call sf_console_on_${RUN_ON},cache:warmup)

PHONY += sf-open
sf-open: ## Warm Symfony caches
	$(call step,See your Symfony application with: https://$(APP_HOST))

PHONY += sf-update
sf-update: ## Update Symfony packages with Composer
	$(call step,Update Symfony packages with Composer...)
	@composer update -W "doctrine/*" "symfony/*" "twig/*" --no-scripts

PHONY += fresh
fresh: ## Build fresh development environment
	@$(MAKE) $(SF_FRESH_TARGETS)

PHONY += fix-symfony
fix-symfony: IMG := druidfi/qa:php-7.4
fix-symfony: ## Fix Symfony code style
	$(call step,Fix Symfony code style in ./src ...)
	@docker run --rm -it -v $(CURDIR)/src:/app/src:rw,consistent $(IMG) bash -c "php-cs-fixer -vvvv fix src"

PHONY += lint-symfony
lint-symfony: IMG := druidfi/qa:php-7.4
lint-symfony: VOLUMES := $(CURDIR)/src:/app/src:rw,consistent
lint-symfony: ## Lint Symfony code style
	$(call step,Lint Symfony code style...)
	@docker run --rm -it -v $(VOLUMES) $(IMG) bash -c "phpcs ."

define sf_console_on_docker
	$(call docker_run_cmd,bin/console --ansi $(1))
endef

define sf_console_on_host
	@bin/console --ansi $(1)
endef
