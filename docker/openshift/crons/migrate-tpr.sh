#!/bin/bash

echo "Running TPR Migrations: $(date)"

while true
do
  echo "Running TPR Unit migrate: $(date)"
  drush migrate:import tpr_unit
  echo "Running TPR Service migrate: $(date)"
  drush migrate:import tpr_service
  echo "Running TPR Errand Service migrate: $(date)"
  drush migrate:import tpr_errand_service
  echo "Running TPR Service Channel migrate: $(date)"
  drush migrate:import tpr_service_channel
  # Sleep for 6 hours.
  sleep 21600
done
