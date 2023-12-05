#!/bin/bash

echo "Start indexing street data from external datasource: $(date)"

while true
do
  drush sapi-r && drush sapi-i
  sleep 86400
done
