#!/bin/bash

echo "Start indexing street data from external datasource: $(date)"

while true
do
  drush sapi-r street_data && drush sapi-i street_data --batch-size=500
  sleep 86400
done
