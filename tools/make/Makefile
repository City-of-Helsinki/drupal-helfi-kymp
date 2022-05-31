.DEFAULT_GOAL := help

BUILD_TARGETS :=
CLEAN_EXCLUDE := .idea dump.sql .env.local
COMPOSER_JSON_PATH ?= .
DRUIDFI_TOOLS_MAKE_DIR := $(dir $(lastword $(MAKEFILE_LIST)))
ENV := dev
PACKAGE_JSON_PATH ?= .
RUN_ON := host
UNAME_S := $(shell uname -s)
# Shorten with https://git.io/ : https://raw.githubusercontent.com/druidfi/tools/main/update.sh
UPDATE_SCRIPT_URL := https://git.io/JP10q
WEBROOT ?= public

# Detect app
IS_DRUPAL ?= $(shell test -f $(WEBROOT)/sites/default/settings.php && echo yes || echo no)
IS_SYMFONY ?= $(shell test -f config/bundles.php && echo yes || echo no)
IS_WP ?= $(shell (test -f $(WEBROOT)/wp-settings.php || test -f conf/wp-config.php) && echo yes || echo no)

# Detect additional tooling
LAGOON := $(shell test -f .lagoon.yml && echo yes || echo no)
WODBY := $(shell test -f wodby.yml && echo yes || echo no)

ifeq ($(LAGOON),yes)
	SYSTEM := LAGOON
else ifeq ($(WODBY),yes)
	SYSTEM := WODBY
else
	SYSTEM := WHOKNOWS
endif

include $(DRUIDFI_TOOLS_MAKE_DIR)utils.mk

# Include druidfi/tools make files
include $(DRUIDFI_TOOLS_MAKE_DIR)include.mk

PHONY += debug
debug: ## Show debug information
	@printf "\n${YELLOW}Debug starts:${NO_COLOR}\n\n"
	$(call dbg,DOCKER,$(call has,docker))
	$(call dbg,DOCKER_PROJECT_ROOT,${DOCKER_PROJECT_ROOT})
	$(call dbg,DOCKER_COMPOSE_YML_EXISTS,${DOCKER_COMPOSE_YML_EXISTS})
	$(call dbg,DOCKER_COMPOSE,${DOCKER_COMPOSE})
	$(call dbg,CLI_SERVICE,${CLI_SERVICE})
	$(call dbg,CLI_USER,${CLI_USER})
	$(call dbg,CLI_SHELL,${CLI_SHELL})
	$(call dbg,ENV,${ENV})
	$(call dbg,PHP on host,$(call has,php))
	$(call dbg,PHP_BIN,$(shell command -v php || echo no))
	$(call dbg,RUN_ON,${RUN_ON})
	$(call dbg,Composer on host,$(call has,composer))
	$(call dbg,COMPOSER_BIN,$(shell command -v composer || echo no))
	$(call dbg,COMPOSER_JSON_EXISTS,${COMPOSER_JSON_EXISTS})
	$(call dbg,IS_DRUPAL,${IS_DRUPAL})
	$(call dbg,IS_SYMFONY,${IS_SYMFONY})
	$(call dbg,IS_WP,${IS_WP})
	$(call dbg,LAGOON,${LAGOON})
	$(call dbg,WODBY,${WODBY})
	$(call dbg,SYSTEM,${SYSTEM})
	$(call dbg,WEBROOT,${WEBROOT})
	$(call dbg,UNAME_S,${UNAME_S})
ifeq ($(RUN_ON),docker)
	@printf "\n${YELLOW}Assumption: We should run on Docker and not on host${NO_COLOR}\n"
else
	@printf "\n${YELLOW}Assumption: We should run on host and not on Docker${NO_COLOR}\n"
endif
	@printf "\n${GREEN}End of debug.${NO_COLOR}\n"
