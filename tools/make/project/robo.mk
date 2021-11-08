STONEHENGE_PATH ?= ${HOME}/stonehenge
PROJECT_DIR ?= ${GITHUB_WORKSPACE}
DOCKER_COMPOSE_FILES = -f docker-compose.ci.yml -f docker-compose.yml

ifeq ($(CI),true)
	SETUP_ROBO_TARGETS := set-permissions install-stonehenge start-stonehenge start-project $(PROJECT_DIR)/vendor install-drupal
else
	SETUP_ROBO_TARGETS := start-project $(PROJECT_DIR)/vendor install-drupal
endif

install-stonehenge: $(STONEHENGE_PATH)/.git

$(STONEHENGE_PATH)/.git:
	git clone -b 3.x https://github.com/druidfi/stonehenge.git $(STONEHENGE_PATH)

PHONY += start-stonehenge
start-stonehenge:
	cd $(STONEHENGE_PATH) && make up

$(PROJECT_DIR)/vendor:
	$(call docker_run_ci,app,composer install)

PHONY += install-drupal
install-drupal:
	$(call docker_run_ci,app,drush si -y)
	$(call docker_run_ci,app,drush cr)
	$(call docker_run_ci,app,drush si --existing-config -y)
	$(call docker_run_ci,app,drush cim -y)

PHONY += start-project
start-project:
	docker compose $(DOCKER_COMPOSE_FILES) up -d

PHONY += set-permissions
set-permissions:
	chmod 777 -R $(PROJECT_DIR)

define docker_run_ci
	docker compose $(DOCKER_COMPOSE_FILES) exec $(1) bash -c "$(2)"
endef

PHONY += setup-robo
setup-robo: $(SETUP_ROBO_TARGETS)

PHONY += run-tests
run-tests:
	$(call docker_run_ci,robo,curl curl http://${COMPOSE_PROJECT_NAME}-varnish:6081)
