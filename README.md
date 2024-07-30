# City of Helsinki - KYMP Drupal project

KYMP, short for Kaupunkiympäristö ja liikenne, is a site that contains information about the city, its development, and
transportation.

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

### Custom node types

#### Project (project)
A content which can be searched with `District and project search`.

#### Area (area)
A content type which has a node for every district and subdistrict in Helsinki. The page contains some content related to the district and
a `subdistrict block` in sidebar.

### Custom blocks

#### Subdistrict block
A block in `Area -content type` page's sidebar which lists district and subdistricts related to the current content.

***Module:*** part of helfi_kymp_content

### Custom paragraphs

#### List of Plans (list_of_plans)
Lists city development plans for people to see and comment
The paragraph can be seen [HERE](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kaupunkisuunnittelu-ja-rakentaminen/osallistu-kaupungin-suunnitteluun)

- ***Module:*** helfi_kymp_plans
- ***API:*** https://ptp.hel.fi/rss/nahtavana_nyt/
- ***Cron:*** invalidate-tags-kymp

##### How it works
On page load the API is queried and result is cached. The plans are then shown on the page.
A simple javascript is used to hide and show the plans.
Cron is used to empty the cached plans once an hour.

#### District and project search (district_and_project_search)
The search allows searching for city districts and city development projects.
It can be found from [here](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/kaupunkisuunnittelu-ja-rakentaminen/suunnitelmat-ja-rakennushankkeet)
City districts were imported using helfi_kymp_migrations module (might have been removed)

- ***Module:*** part of helfi_kymp_content
- ***React:*** district-and-project-search

##### How it works
Districts and subdistricts has been added via helfi_kymp_migrations module (module might have been removed)
Projects are created manually by content creators.
Searching for projects work as any other react search.

#### Journey planner (journey_planner)
An external tool by HSL which allows to find routes, for example [pyöräilyreittihaku](https://www.hel.fi/fi/kaupunkiymparisto-ja-liikenne/pyoraily/pyorareitit)

##### How it works
An iframe is added to the page which allows searching for routes.

#### Ploughing schedule (ploughing_schedule)
The Ploughing Schedule paragraph is a tool that allows website users to get an estimated snow ploughing schedule for a
specific street.

This search functionality is built with React and does not have any fallback listing or similar feature. All
React-based searches are located in the `hdbt` theme, where most of the related logic is implemented.

- **Module**: helfi_kymp_content
- **Cron:** street_data
- **Street-data api:** https://kartta.hel.fi/ws/geoserver/avoindata/wfs
- **ElasticSearch:** street_data -index
- **React:** hdbt/ploughing-schedule

##### How it works
Unlike other ElasticSearch implementations, the ploughing schedule does not use the Drupal database as a data source.
Instead, the data is directly fetched from an API and indexed into ElasticSearch. A cron job automatically indexes the
data into the `street_data` index once a day. The UI is a simple React application.

### Helfi Custom Test Content (helfi_custom_test_content module)
This module depends on another test content creation module called `helfi_test_content`. The purpose of this module is
to provide additional custom test content unique to this instance, allowing developers to test components without
manually creating content.

### Helfi kymp migrations (helfi_kymp_migrations module)
Most likely useless module to be disabled and removed. REMOVE THIS FROM DOCUMENTATION IF THE CUSTOM MODULE HAS BEEN
REMOVED.
