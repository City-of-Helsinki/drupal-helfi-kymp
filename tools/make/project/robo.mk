STONEHENGE_PATH ?= ${HOME}/stonehenge
PROJECT_DIR ?= ${GITHUB_WORKSPACE}
ROBOT_EXTRA_ARGS ?= --exitonfailure
SITE_PREFIX ?= /

SETUP_ROBO_TARGETS :=
CI_POST_INSTALL_TARGETS :=

ifeq ($(CI),true)
	SETUP_ROBO_TARGETS += install-stonehenge start-stonehenge set-permissions
	CI_POST_INSTALL_TARGETS += fix-files-permission
endif

SETUP_ROBO_TARGETS += up composer-install $(CI_POST_INSTALL_TARGETS) update-automation

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
	cd $(STONEHENGE_PATH) && COMPOSE_FILE=docker-compose.yml make up

$(PROJECT_DIR)/helfi-test-automation-python/.git:
	git clone https://github.com/City-of-Helsinki/helfi-test-automation-python.git $(PROJECT_DIR)/helfi-test-automation-python

PHONY += update-automation
update-automation: $(PROJECT_DIR)/helfi-test-automation-python/.git
	git pull

PHONY += install-drupal
install-drupal:
	$(call docker_run_ci,app,drush si minimal -y)
	$(call docker_run_ci,app,drush cr)
	$(call docker_run_ci,app,drush si minimal --existing-config -y)
	$(call docker_run_ci,app,drush deploy)
	$(call docker_run_ci,app,drush upwd helfi-admin Test_Automation)
	$(call docker_run_ci,app,drush en helfi_example_content syslog -y)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_unit --publish)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_service --publish)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_errand_service --publish)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_service_channel --publish)

PHONY += install-drupal-from-dump
install-drupal-from-dump:
	$(call docker_run_ci,app,drush sql-drop -y)
	$(call docker_run_ci,app,mysql --user=drupal --password=drupal --database=drupal --host=db --port=3306 -A < latest.sql)
	$(call docker_run_ci,app,drush cr)
	$(call docker_run_ci,app,drush cim -y)
	$(call docker_run_ci,app,drush upwd helfi-admin Test_Automation)
	$(call docker_run_ci,app,drush en helfi_example_content syslog -y)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_unit --publish)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_service --publish)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_errand_service --publish)
	$(call docker_run_ci,app,drush helfi:migrate-fixture tpr_service_channel --publish)

PHONY += save-dump
save-dump:
	$(call docker_run_ci,app,drush sql-dump --result-file=/app/latest.sql)

PHONY += robo-shell
robo-shell:
	@docker compose $(DOCKER_COMPOSE_FILES) exec robo sh

PHONY += set-permissions
set-permissions:
	chmod 777 /home/runner/.cache/composer -R
	chmod 777 -R $(PROJECT_DIR)

PHONY += fix-files-permission
fix-files-permission:
	mkdir $(PROJECT_DIR)public/sites/default/files -p && chmod 777 -R $(PROJECT_DIR)public/sites/default/files

define docker_run_ci
	docker compose exec -T $(1) sh -c "$(2)"
endef

PHONY += setup-robo
setup-robo: $(SETUP_ROBO_TARGETS)

PHONY += run-robo-tests
run-robo-tests:
	$(call docker_run_ci,robo,cd /app/helfi-test-automation-python && pabot --testlevelsplit --ordering ./environments/helfi_pabot_order_ci --processes 9 $(ROBOT_EXTRA_ARGS) -v useoriginalname:False -A environments/ci.args -v PREFIX:$(SITE_PREFIX) -v BASE_URL:varnish-$(DRUPAL_HOSTNAME) -v PICCOMPARE:False -v images_dir:robotframework-resources/screenshots/headlesschrome -v actual_dir:robotframework-reports -d robotframework-reports  .)
