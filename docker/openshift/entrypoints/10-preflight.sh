#!/bin/sh

if [ -f "../docker/openshift/preflight.php" ]; then
  source /init.sh
  echo "Running preflight checks ..."
  php ../docker/openshift/preflight/preflight.php
fi

