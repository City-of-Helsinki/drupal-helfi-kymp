#!/bin/bash

# Checking if a new deployment is in progress, as we should not run cron while deploying.
if [ ! -n "$OPENSHIFT_BUILD_NAME" ]; then
  echo "OPENSHIFT_BUILD_NAME is not defined. Exiting early."
  exit 1
fi

function get_deploy_id {
  echo $(cat sites/default/files/deploy.lock)
}

function deployment_in_progress {
  if [ "$(get_deploy_id)" != "$OPENSHIFT_BUILD_NAME" ]; then
    return 1
  fi

  if [ "$(drush state:get system.maintenance_mode)" = "1" ]; then
    return 1
  fi

  return 0
}

ATTEMPTS=0
while [ deployment_in_progress ]
do
  let ATTEMPTS++

  if (( ATTEMPTS > 10 )); then
    echo "Failed to start a new cron pod - deployment probably failed"
    exit 1
  fi
  echo "A deployment is in progress - waiting for completion ..."
  sleep 60
done

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
