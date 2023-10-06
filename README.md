# City of Helsinki - Liikenne Drupal

## Environments

Env | Branch | URL
--- |--------| ---
local | dev    | http://helfi-kymp.docker.so/
production | main | TBD

## Requirements

You need to have these applications installed to operate on all environments:

- [Docker](https://github.com/druidfi/guidelines/blob/master/docs/docker.md)
- [Stonehenge](https://github.com/druidfi/stonehenge)

## Create and start the environment

To install Drupal from scratch using existing configuration:

``
$ make new
``

To sync database from testing environment:

``
$ make fresh
``

## Login to Drupal container

This will log you inside the app container:

```
$ make shell
```

## Docker compose profiles

Modify the value of `COMPOSE_PROFILES` environment variable from `.env` file or start the project with `COMPOSE_RROFILES=your-profiles make up`.

### Available profiles:
- `search`
- `queue`
