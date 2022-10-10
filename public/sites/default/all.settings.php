<?php

/**
 * @file
 * Contains site specific overrides.
 */

if ($hotjar_id = getenv('HOTJAR_ID')) {
  $config['helfi_hotjar.settings']['hjid'] = $hotjar_id;
}

if ($drush_options_uri = getenv('DRUSH_OPTIONS_URI')) {
  if (str_contains($drush_options_uri, 'www.hel.fi')) {
    $config['helfi_proxy.settings']['default_proxy_domain'] = 'www.hel.fi';
  }
}

// Elasticsearch settings.
if (getenv('ELASTICSEARCH_URL')) {
  $config['elasticsearch_connector.cluster.kymp']['url'] = getenv('ELASTICSEARCH_URL');

  if (getenv('ELASTIC_USER') && getenv('ELASTIC_PASSWORD')) {
    $config['elasticsearch_connector.cluster.kymp']['options']['use_authentication'] = '1';
    $config['elasticsearch_connector.cluster.kymp']['options']['authentication_type'] = 'Basic';
    $config['elasticsearch_connector.cluster.kymp']['options']['username'] = getenv('ELASTIC_USER');
    $config['elasticsearch_connector.cluster.kymp']['options']['password'] = getenv('ELASTIC_PASSWORD');
  }
}
