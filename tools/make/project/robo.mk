STONEHENGE_PATH ?= ${HOME}/stonehenge
PROJECT_DIR ?= ${GITHUB_WORKSPACE}
#DOCKER_COMPOSE_FILES = -f docker-compose.ci.yml -f docker-compose.yml

ifeq ($(CI),true)
	SETUP_ROBO_TARGETS := set-permissions install-stonehenge start-stonehenge robo-composer-install update-automation install-drupal
else
	SETUP_ROBO_TARGETS := robo-composer-install update-automation install-drupal
endif

install-stonehenge: $(STONEHENGE_PATH)/.git

$(STONEHENGE_PATH)/.git:
	git clone -b 3.x https://github.com/druidfi/stonehenge.git $(STONEHENGE_PATH)

PHONY += start-stonehenge
start-stonehenge:
	cd $(STONEHENGE_PATH) && make up

robo-composer-install:
	$(call docker_run_ci,app,composer install)

$(PROJECT_DIR)/helfi-test-automation-python/.git:
	git clone https://github.com/City-of-Helsinki/helfi-test-automation-python.git $(PROJECT_DIR)/helfi-test-automation-python

PHONY += update-automation
update-automation: $(PROJECT_DIR)/helfi-test-automation-python/.git
	git pull

PHONY += install-drupal
install-drupal:
	$(call docker_run_ci,app,drush si -y)
	$(call docker_run_ci,app,drush cr)
	$(call docker_run_ci,app,drush si --existing-config -y)
	$(call docker_run_ci,app,drush cim -y)
	$(call docker_run_ci,app,drush upwd helfi-admin Test_Automation)

PHONY += set-permissions
set-permissions:
	chmod 777 -R $(PROJECT_DIR)

define docker_run_ci
	docker compose $(DOCKER_COMPOSE_FILES) exec $(1) bash -c "$(2)"
endef

PHONY += setup-robo
setup-robo: $(SETUP_ROBO_TARGETS)

PHONY += run-robo-tests
run-robo-tests:
	robot -i DEMO -A ${GITHUB_WORKSPACE}/helfi-test-automation-python/environments/local.args -d ${GITHUB_WORKSPACE}/helfi-test-automation-python/robotframework-reports ${GITHUB_WORKSPACE}/helfi-test-automation-python)
