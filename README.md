# City of Helsinki - Liikenne Drupal

Kaupunkiympäristö ja liikenne (Kymp) is a site which contains information about the city itself, the development of the city and transportation.

## Environments

Env | Branch | URL
--- |--------| ---
local | dev    | http://helfi-kymp.docker.so/
production | main | https://hel.fi/fi/kaupunkiymparisto-ja-liikenne

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

## Instance specific features

### Project -content type (Hanke)

A content which can be searched with `District and project search`.


### Area -content type (Alue)

A content type which has a node for every district and subdistrict in Helsinki. The page contains some content related to the district and
a `subdistrict block` in sidebar.


### Subdistrict block

A block in `Area -content type` page's sidebar which lists district and subdistricts related to the current content.

***Module:*** part of helfi_kymp_content


### Plans (Suunnitelmat)

Lists city development plans for people to see and comment
The paragraph can be seen [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kaupunkisuunnittelu-ja-rakentaminen/osallistu-kaupungin-suunnitteluun)

- ***Module:*** helfi_kymp_plans
- ***API:*** https://ptp.hel.fi/rss/nahtavana_nyt/
- ***Cron:*** invalidate-tags-kymp

#### How it works

On page load the API is queried and result is cached. The plans are then shown on the page.
A simple javascript is used to hide and show the plans.
Cron is used to empty the cached plans once an hour.


### District and project search (Alue ja hankehaku)

The search allows searching for city districts and city development projects.
It can be found from [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kaupunkisuunnittelu-ja-rakentaminen/suunnitelmat-ja-rakennushankkeet)
City districts were imported using helfi_kymp_migrations module (might have been removed)

- ***Module:*** part of helfi_kymp_content
- ***React:*** district-and-project-search

#### How it works

Districts and subdistricts has been added via helfi_kymp_migrations module (module might have been removed)
Projects are created manually by content creators.
Searching for projects work as any other react search.


### Journey planner (Reittiopas)

An external tool by HSL which allows to find routes, for example [pyöräilyreittihaku](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/pyoraily/pyorareitit)

#### How it works

An iframe is added to the page which allows searching for routes.


### Snow ploughing schedule (Aurausaikataulu)

Ploughing schedule is a tool which allows user to get estimated snow ploughing schedule for a specific street.
Currently the search tool is located only [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kunnossapito/katujen-kunnossapito/katujen-talvikunnossapito)

- **Module**: helfi_kymp_content
- **Cron:** street_data
- **Street-data api:** https://kartta.hel.fi/ws/geoserver/avoindata/wfs
- **ElasticSearch:** street_data -index
- **React:** hdbt/ploughing-schedule

#### How it works

Unlike other ElasticSearch implementation, ploughing schedule is not using Drupal database as a datasource.
The data is directly fetched from API and indexed into ElasticSearch. Cron is used to automatically index the data to street_data index once a day.

UI is a simple react-application.


### Custom test content (module)

Uses helfi_test_content-module to add test data so developer can test the components without need to manually create content.


### Helfi kymp migrations (module)

Most likely useless module to be disabled and removed. REMOVE THIS FROM DOCUMENTATION IF THE CUSTOM MODULE HAS BEEN REMOVED.
