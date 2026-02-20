<?php

/**
 * @file
 * Contains site specific overrides.
 */

// Elastic proxy URL.
$config['elastic_proxy.settings']['elastic_proxy_url'] = getenv('ELASTIC_PROXY_URL');
// Sentry DSN for React.
$config['react_search.settings']['sentry_dsn_react'] = getenv('SENTRY_DSN_REACT');
$config['openid_connect.client.tunnistamo']['settings']['ad_roles'] = [
  [
    'ad_role' => 'Drupal_Helfi_kaupunkitaso_paakayttajat',
    'roles' => ['admin'],
  ],
  [
    'ad_role' => 'Drupal_Helfi_Kaupunkiymp_ja_liikenne_sisallontuottajat_laaja',
    'roles' => ['editor'],
  ],
  [
    'ad_role' => 'Drupal_Helfi_Kaupunkiymp_ja_liikenne_sisallontuottajat_suppea',
    'roles' => ['content_producer'],
  ],
  [
    'ad_role' => 'Drupal_Helfi_Etusivu_kayttajakyselyt',
    'roles' => ['survey_editor'],
  ],
  [
    'ad_role' => '947058f4-697e-41bb-baf5-f69b49e5579a',
    'roles' => ['super_administrator'],
  ],
];

$additionalEnvVars = [
  'AZURE_BLOB_STORAGE_SAS_TOKEN|BLOBSTORAGE_SAS_TOKEN',
  'AZURE_BLOB_STORAGE_NAME',
  'AZURE_BLOB_STORAGE_CONTAINER',
  'DRUPAL_VARNISH_HOST',
  'DRUPAL_VARNISH_PORT',
  'PROJECT_NAME',
  'DRUPAL_PUBSUB_VAULT',
  'DRUPAL_NAVIGATION_VAULT',
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
  // 'AMQ_BROKERS',
  // 'AMQ_USER',
  // 'AMQ_PASSWORD',
  // MobileNote WFS API.
  'MN_WFS_USERNAME',
  'MN_WFS_PASSWORD',
  'MN_WFS_URL',
  'PAIKKATIETOHAKU_API_KEY',
];
foreach ($additionalEnvVars as $var) {
  $preflight_checks['environmentVariables'][] = $var;
}

// Mobilenote integration spams a lot of failures.
// The failures should be re-tried automatically.
// Silence search api logger to reduce Sentry noise.
$config['raven.settings']['ignored_channels'][] = 'search_api';

// MobileNote WFS API settings.
$settings['helfi_kymp_mobilenote'] = [
  'wfs_url' => getenv('MN_WFS_URL') ?: '',
  'wfs_username' => getenv('MN_WFS_USERNAME') ?: '',
  'wfs_password' => getenv('MN_WFS_PASSWORD') ?: '',
  'address_api_key' => getenv('PAIKKATIETOHAKU_API_KEY') ?: '',
  // Sync filter: fetch items where voimassaoloAlku >= (today - offset).
  'sync_lookback_offset' => '-30 days',
  // Cleanup: remove items where (voimassaoloLoppu + offset) < today.
  'sync_removal_offset' => '+30 days',
];

