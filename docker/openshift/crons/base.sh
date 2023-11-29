#!/bin/bash

# Checking if a new deployment is in progress, as we should not run cron while deploying.
if [ ! -n "$OPENSHIFT_BUILD_NAME" ]; then
  echo "OPENSHIFT_BUILD_NAME is not defined. Exiting early."
  exit 1
fi

function get_deploy_id {
  echo $(cat sites/default/files/deploy.lock)
}

if [ "$(get_deploy_id)" != "$OPENSHIFT_BUILD_NAME" ]; then
  echo "Current deploy_id $OPENSHIFT_BUILD_NAME not set. Probably a deployment is in progress."
  exit 1
fi

if [ "$(drush state:get system.maintenance_mode)" = "1" ]; then
  echo "Maintenance mode on. Probably a deployment is in progress."
  exit 1
fi

echo "Starting cron: $(date)"

# You can add any additional cron "daemons" here:
#
# exec "/crons/some-command.sh" &
#
# Example cron (docker/openshift/crons/some-command.sh):
# @code
# #!/bin/bash
# while true
# do
#   drush some-command
#   sleep 600
# done
# @endcode

exec "/crons/migrate-tpr.sh" &
exec "/crons/purge-queue.sh" &
exec "/crons/update-translations.sh" &
exec "/crons/content-scheduler.sh" &
exec "/crons/invalidate-tags-kymp.sh" &
exec "/crons/pubsub.sh" &

while true
do
  echo "Running cron: $(date +'%Y-%m-%dT%H:%M:%S%:z')\n"
  drush cron
  # Sleep for 10 minutes.
  sleep 600
done
