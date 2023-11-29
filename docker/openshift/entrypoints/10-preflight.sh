#!/bin/sh

source /init.sh

if [ ! -f "../docker/openshift/preflight.php" ]; then
  echo "Preflight not enabled. Skipping ..."
  exit 0
fi

echo "Running preflight checks ..."
php ../docker/openshift/preflight/preflight.php
