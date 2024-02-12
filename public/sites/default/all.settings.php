<?php

/**
 * @file
 * Contains site specific overrides.
 */

if ($hotjar_id = getenv('HOTJAR_ID')) {
  $config['helfi_hotjar.settings']['hjid'] = $hotjar_id;
}

$elastic_url = getenv('ELASTICSEARCH_URL');

if (!$elastic_url && getenv('APP_ENV') === 'local') {
  $elastic_url = gethostbyname('elastic');
}

// Elasticsearch settings.
if ($elastic_url) {
  $config['elasticsearch_connector.cluster.kymp']['url'] = $elastic_url;

  if (getenv('ELASTIC_USER') && getenv('ELASTIC_PASSWORD')) {
    $config['elasticsearch_connector.cluster.kymp']['options']['use_authentication'] = '1';
    $config['elasticsearch_connector.cluster.kymp']['options']['authentication_type'] = 'Basic';
    $config['elasticsearch_connector.cluster.kymp']['options']['username'] = getenv('ELASTIC_USER');
    $config['elasticsearch_connector.cluster.kymp']['options']['password'] = getenv('ELASTIC_PASSWORD');
  }
}


// Elastic proxy URL.
$config['elastic_proxy.settings']['elastic_proxy_url'] = getenv('ELASTIC_PROXY_URL');

// Sentry DSN for React.
$config['react_search.settings']['sentry_dsn_react'] = getenv('SENTRY_DSN_REACT');

$additionalEnvVars = [
  'AZURE_BLOB_STORAGE_SAS_TOKEN|BLOBSTORAGE_SAS_TOKEN',
  'AZURE_BLOB_STORAGE_NAME',
  'AZURE_BLOB_STORAGE_CONTAINER',
  'DRUPAL_VARNISH_HOST',
  'DRUPAL_VARNISH_PORT',
  'PROJECT_NAME',
  'DRUPAL_API_ACCOUNTS',
  'DRUPAL_VAULT_ACCOUNTS',
  'REDIS_HOST',
  'REDIS_PORT',
  'REDIS_PASSWORD',
  'TUNNISTAMO_CLIENT_ID',
  'TUNNISTAMO_CLIENT_SECRET',
  'TUNNISTAMO_ENVIRONMENT_URL',
  'SENTRY_DSN',
  'SENTRY_ENVIRONMENT',
  // Project specific variables.
  'ELASTIC_PROXY_URL',
  'ELASTICSEARCH_URL',
  'ELASTIC_USER',
  'ELASTIC_PASSWORD',
  'SENTRY_DSN_REACT',
];
foreach ($additionalEnvVars as $var) {
  $preflight_checks['environmentVariables'][] = $var;
}
