#!/bin/bash

source /init.sh

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
