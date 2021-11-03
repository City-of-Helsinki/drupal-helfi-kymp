STONEHENGE_PATH ?= ${HOME}/stonehenge
PROJECT_DIR ?= ${GITHUB_WORKSPACE}
DOCKER_COMPOSE_FILES = -f docker-compose.ci.yml -f docker-compose.yml

$(STONEHENGE_PATH)/.git:
	git clone -b 3.x https://github.com/druidfi/stonehenge.git $(STONEHENGE_PATH)

PHONY += start-stonehenge
start-stonehenge:
	cd $(STONEHENGE_PATH) && make up

$(PROJECT_DIR)/vendor:
	$(call docker_run_ci, 'composer install')

PHONY += install-drupal
install-drupal:
	$(call docker_run_ci, 'drush si --existing-config -y')

PHONY += start-project
start-project:
	docker compose $(DOCKER_COMPOSE_FILES) up -d

PHONY += set-permissions
set-permissions:
	chmod 777 -R $(PROJECT_DIR)

define docker_run_ci
	docker compose $(DOCKER_COMPOSE_FILES) exec app bash -c "$(1)"
endef

PHONY += setup-ci
setup-ci: set-permissions $(STONEHENGE_PATH)/.git start-stonehenge $(PROJECT_DIR)/vendor start-project install-drupal
