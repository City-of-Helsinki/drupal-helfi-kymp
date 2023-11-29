#!/bin/bash

cd /var/www/html/public

function get_deploy_id {
  echo $(cat sites/default/files/deploy.id)
}

function set_deploy_id {
  echo ${1} > sites/default/files/deploy.id
}

function output_error_message {
  echo ${1}
  php ../docker/openshift/notify.php "${1}" || true
}

function rollback_deployment {
  output_error_message "Deployment failed: ${1}"
  set_deploy_id ${2}
  exit 1
}

if [ ! -d "sites/default/files" ]; then
  output_error_message "Container start error: Public file folder does not exist. Exiting early."
  exit 1
fi

# Make sure we have active Drupal configuration.
if [ ! -f "../conf/cmi/system.site.yml" ]; then
  output_error_message "Container start error: Codebase is not deployed properly. Exiting early."
  exit 1
fi

if [ ! -n "$OPENSHIFT_BUILD_NAME" ]; then
  output_error_message "Container start error: OPENSHIFT_BUILD_NAME is not defined. Exiting early."
  exit 1
fi

# Populate twig caches.
if [ ! -d "/tmp/twig" ]; then
  drush twig:compile || true
fi

# Capture the current deploy ID so we can roll back to previous version in case
# deployment fails.
CURRENT_DEPLOY_ID=$(get_deploy_id)

# This script is run every time a container is spawned and certain environments might
# start more than one Drupal container. This is used to make sure we run deploy
# tasks only once per deploy.
if [ "$CURRENT_DEPLOY_ID" != "$OPENSHIFT_BUILD_NAME" ]; then
  set_deploy_id $OPENSHIFT_BUILD_NAME

  if [ $? -ne 0 ]; then
    rollback_deployment "Failed to set deploy_id" $CURRENT_DEPLOY_ID
  fi
  # Put site in maintenance mode
  drush state:set system.maintenance_mode 1 --input-format=integer

  if [ $? -ne 0 ]; then
    rollback_deployment "Failed to enable maintenance_mode" $CURRENT_DEPLOY_ID
  fi
  # Run helfi specific pre-deploy tasks. Allow this to fail in case
  # the environment is not using the 'helfi_api_base' module.
  # @see https://github.com/City-of-Helsinki/drupal-module-helfi-api-base
  drush helfi:pre-deploy || true
  # Run maintenance tasks (config import, database updates etc)
  drush deploy

  if [ $? -ne 0 ]; then
    rollback_deployment "drush deploy failed with {$?} exit code. See logs for more information." $CURRENT_DEPLOY_ID
    exit 1
  fi
  # Run helfi specific post deploy tasks. Allow this to fail in case
  # the environment is not using the 'helfi_api_base' module.
  # @see https://github.com/City-of-Helsinki/drupal-module-helfi-api-base
  drush helfi:post-deploy || true
  # Disable maintenance mode
  drush state:set system.maintenance_mode 0 --input-format=integer

  if [ $? -ne 0 ]; then
    rollback_deployment "Failed to disable maintenance_mode" $CURRENT_DEPLOY_ID
  fi
fi
