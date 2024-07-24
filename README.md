# City of Helsinki - Liikenne Drupal

Kaupunkiympäristö ja liikenne (Kymp) is a site which contains information about the city itself, the development of the city and transportation.

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

## Instance specific features

### Helfi kymp migrations

Most likely useless module to be disabled and removed. REMOVE THIS FROM DOCUMENTATION IF THE CUSTOM MODULE HAS BEEN REMOVED.


### Custom test content

Uses helfi_test_content-module to add test data so developer can test the components without need to manually create content.


### Plans (Suunnitelmat)

Plans lists city development plans for people to see and comment
The paragraph can be seen [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kaupunkisuunnittelu-ja-rakentaminen/osallistu-kaupungin-suunnitteluun)

#### Used tools

***Module:*** helfi_kymp_plans
***API:*** https://ptp.hel.fi/rss/nahtavana_nyt/
***Cron:*** invalidate-tags-kymp

#### How it works

On site load the API is queried and result is cached. The plans are then shown on the page.
A simple javascript is used to hide and show the plans.
Cron is used to empty the cached plans once an hour.


### Project ???



### District and project search (Alue ja hankehaku)

The search allows searching for city districts and city development projects.
It can be found from [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kaupunkisuunnittelu-ja-rakentaminen/suunnitelmat-ja-rakennushankkeet)
City districts were imported using helfi_kymp_migrations module (might have been removed)

#### Used tools

***Module:*** part of helfi_kymp_content
***API:***
***React:*** district-and-project-search

#### How it works


### Journey planner / plans ? list of plans ?
#### Used tools
#### How it works


### District/Subdistrict ???
#### How it works


### Snow ploughing schedule (Aurausaikataulu)

Ploughing schedule is a tool which allows user to get estimated snow ploughing schedule for a specific street.
Currently the search tool is located only [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kunnossapito/katujen-kunnossapito/katujen-talvikunnossapito)

#### Used tools

- **Module**: helfi_kymp_content
- **Cron:** street_data
- **Street-data api:** https://kartta.hel.fi/ws/geoserver/avoindata/wfs
- **ElasticSearch:** street_data -index
- **React:** hdbt/ploughing-schedule

#### How it works

Unlike other ElasticSearch implementation, ploughing schedule is not using Drupal database as a datasource.
The data is directly fetched from API and indexed into ElasticSearch. Cron is used to automatically index the data to street_data index once a day.

UI is a simple react-application.





