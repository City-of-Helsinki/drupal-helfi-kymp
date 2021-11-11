STONEHENGE_PATH ?= ${HOME}/stonehenge
PROJECT_DIR ?= ${GITHUB_WORKSPACE}
DOCKER_COMPOSE_FILES = -f docker-compose.ci.yml -f docker-compose.yml

SETUP_ROBO_TARGETS :=

ifeq ($(CI),true)
	SETUP_ROBO_TARGETS += install-stonehenge start-stonehenge set-permissions
endif

SETUP_ROBO_TARGETS += start-project robo-composer-install update-automation

ifeq ($(DRUPAL_BUILD_FROM_SCRATCH),true)
	SETUP_ROBO_TARGETS += install-drupal
else
	SETUP_ROBO_TARGETS += install-drupal-from-dump
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

PHONY += start-project
start-project:
	docker compose $(DOCKER_COMPOSE_FILES) up -d

PHONY += install-drupal
install-drupal:
	$(call docker_run_ci,app,drush si -y)
	$(call docker_run_ci,app,drush cr)
	$(call docker_run_ci,app,drush si --existing-config -y)
	$(call docker_run_ci,app,drush cim -y)
	$(call docker_run_ci,app,drush upwd helfi-admin Test_Automation)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_unit)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_service)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_errand_service)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_service_channel)
	$(call docker_run_ci,app,drush en helfi_tpr_config helfi_announcements helfi_example_content -y)

PHONY += install-drupal-from-dump
install-drupal-from-dump:
	$(call docker_run_ci,app,drush sql-drop -y)
	$(call docker_run_ci,app,mysql --user=drupal --password=drupal --database=drupal --host=db --port=3306 -A < latest.sql)
	$(call docker_run_ci,app,drush cim -y)

PHONY += save-dump
save-dump:
	$(call docker_run_ci,app,drush sql-dump --result-file=/app/latest.sql)

PHONY += robo-stop
robo-stop:
	@docker compose $(DOCKER_COMPOSE_FILES) stop

PHONY += robo-down
robo-down:
	@docker compose $(DOCKER_COMPOSE_FILES) down

PHONY += robo-shell
robo-shell:
	@docker compose $(DOCKER_COMPOSE_FILES) exec robo bash

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
	$(call docker_run_ci,robo,robot -i DEMO -A /app/helfi-test-automation-python/environments/local.args -d /app/helfi-test-automation-python/robotframework-reports /app/helfi-test-automation-python)
