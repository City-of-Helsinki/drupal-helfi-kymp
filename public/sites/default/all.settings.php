<?php

/**
 * @file
 * Contains site specific overrides.
 */

if ($hotjar_id = getenv('HOTJAR_ID')) {
  $config['helfi_hotjar.settings']['hjid'] = $hotjar_id;
}

if (
  ($redis_host = getenv('REDIS_HOST')) &&
  file_exists('modules/contrib/redis/example.services.yml')
) {
  $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');
  $redis_port = getenv('REDIS_PORT') ?: 6379;

  if ($redis_prefix = getenv('REDIS_PREFIX')) {
    $settings['cache_prefix']['default'] = $redis_prefix;
  }

  if ($redis_password = getenv('REDIS_PASSWORD')) {
    $settings['redis.connection']['password'] = $redis_password;
  }
  $settings['redis.connection']['interface'] = 'Predis';
  $settings['redis.connection']['host'] = $redis_host;
  $settings['redis.connection']['port'] = $redis_port;
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';
}
