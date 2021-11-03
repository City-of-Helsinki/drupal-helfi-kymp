STONEHENGE_PATH ?= ${HOME}/stonehenge
PROJECT_DIR ?= ${GITHUB_WORKSPACE}
DOCKER_COMPOSE_FILES = -f docker-compose.ci.yml -f docker-compose.yml
DOCKER_PROXY_PATH ?= ${HOME}/proxy

$(DOCKER_PROXY_PATH)/.git:
	git clone https://github.com/City-of-Helsinki/drupal-helfi-local-proxy.git $(DOCKER_PROXY_PATH)

PHONY += start-proxy
start-proxy: $(DOCKER_PROXY_PATH)
	./start.sh ${PROXY_PROJECT_NAME} &

$(STONEHENGE_PATH)/.git:
	git clone -b 3.x https://github.com/druidfi/stonehenge.git $(STONEHENGE_PATH)

PHONY += start-stonehenge
start-stonehenge:
	cd $(STONEHENGE_PATH) && make up

$(PROJECT_DIR)/vendor:
	$(call docker_run_ci, composer install)

PHONY += install-drupal
install-drupal:
	$(call docker_run_ci, drush si --existing-config -y)

PHONY += start-project
start-project: $(STONEHENGE_PATH)/.git
	docker compose $(DOCKER_COMPOSE_FILES) up -d

PHONY += set-permissions
set-permissions:
	chmod 777 -R $(PROJECT_DIR)

define docker_run_ci
	docker compose $(DOCKER_COMPOSE_FILES) exec app bash -c "$(1)"
endef

PHONY += setup-ci
setup-ci: set-permissions start-stonehenge start-project $(PROJECT_DIR)/vendor install-drupal start-proxy
