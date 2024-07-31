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

#### Area (area)
A content type that includes a node for every district and subdistrict in Helsinki. The page layout closely follows the
standard page layout. Each area can be either a district or a subdistrict, depending on the contents of the
Subdistricts (`field_subdistricts`) field on the node. The content was initially created automatically from Helsinki
city districts and subdistricts and is manually adjusted over time.

#### Project (project)

A custom content type for the KYMP instance, closely related to the [area](#area-area) content type. Each project is
associated with an area via the entity reference field Project District (field_project_district). Projects can be
categorized in various ways and follow a layout similar to the standard page. Projects can be searched using the [District and Project Search](#district-and-project-search-district_and_project_search).

### Custom blocks

#### Subdistrict block
A block in the sidebar of pages with the content type [area](#area-area) lists subdistricts related to the viewed
district. The logic for this block is located in the custom module [`helfi_kymp_content`](https://github.com/City-of-Helsinki/drupal-helfi-kymp/tree/dev/public/modules/custom/helfi_kymp_content).

- **Module:** part of helfi_kymp_content

### Custom paragraphs

#### List of Plans (list_of_plans)
This paragraph lists the city development plans for people to see and comment.

- **Module:** helfi_kymp_plans
- **API:** https://ptp.hel.fi/rss/nahtavana_nyt/
- **Cron:** invalidate-tags-kymp

##### How it works
The paragraph has configurable title and description fields, but the rest of the logic is hardcoded. On page load, the
API is queried and the result is cached. The plans are then displayed in the block. A simple JavaScript is used to
toggle the visibility of the plans. The related JavaScript and PHP code can be found [here](https://github.com/City-of-Helsinki/drupal-helfi-kymp/tree/dev/public/modules/custom/helfi_kymp_plans).
The template for the paragraph is located in the [`hdbt_subtheme`](https://github.com/City-of-Helsinki/drupal-helfi-kymp/blob/dev/public/themes/custom/hdbt_subtheme/templates/paragraph/paragraph--list-of-plans.html.twig).
Cron is used to clear the cached plans once an hour.

#### District and Project Search (district_and_project_search)
The District and Project Search tool allows users to search for city districts and development projects.

The District and Project Search is a React search that uses views listing (`district_and_project_search`) as a fallback
when JavaScript is not enabled. All React searches are in the `hdbt` theme, so most of the related logic is also found
there. The district_and_project_search paragraph has an editable title and description. When development on the feature
started the city districts were imported using [`helfi_kymp_migrations`](#helfi-kymp-migrations-helfi_kymp_migrations-module) module.

- **Module:** part of helfi_kymp_content
- **React:** district-and-project-search
- **ElasticSearch:** districts, districts_for_filters, projects, project_phases, project_themes and project_types indexes

##### How it works
Districts and subdistricts have been added to the Drupal database via the [`helfi_kymp_migrations`](#helfi-kymp-migrations-helfi_kymp_migrations-module)
module. Projects are created manually by content creators. Searching for projects works like any other React search.

#### Journey Planner (journey_planner)
An embedded external tool by HSL allows users to find bike routes within the HSL area.

##### How it works
The paragraph contains title and description fields, and includes an embedded iframe. This iframe is hardcoded into the
`hdbt_subtheme` [here](https://github.com/City-of-Helsinki/drupal-helfi-kymp/blob/dev/public/themes/custom/hdbt_subtheme/hdbt_subtheme.theme)
and is not configurable through the editor. The layout is constructed in the template using data provided by the
`hdbt_subtheme` preprocess hook.

#### Ploughing Schedule (ploughing_schedule)
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
