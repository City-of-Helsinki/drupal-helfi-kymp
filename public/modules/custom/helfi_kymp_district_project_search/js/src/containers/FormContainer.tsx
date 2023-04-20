import { useRef, useState } from 'react';
import { ReactiveComponent } from '@appbaseio/reactivesearch';
import { Accordion, IconLocation } from 'hds-react';

import Dropdown from '../components/form/Dropdown';
import Text from '../components/form/Text';
import SelectionsContainer from './SelectionsContainer';
import SearchComponents from '../enum/SearchComponents';
import SubmitButton from '../components/form/SubmitButton';
import IndexFields from '../enum/IndexFields';
import useLanguageQuery from '../hooks/useLanguageQuery';
import getQuery from '../helpers/GetQuery';
import InitialState from '../types/InitialState';
import type OptionType from '../types/OptionType';
import type SearchState from '../types/SearchState';

type InitializationMap = {
  districts: boolean;
  project_theme: boolean;
  project_phase: boolean;
  project_type: boolean;
};

type InitialParam = Omit<InitialState, 'page'>;

type FormContainerProps = {
  initialParams: Omit<InitialState, 'page'>;
  searchState: SearchState;
  setSearchState: Function;
};

const FormContainer = ({ initialParams, searchState, setSearchState }: FormContainerProps) => {
  const [initialized, setInitialized] = useState<InitializationMap>({
    districts: false,
    project_theme: false,
    project_phase: false,
    project_type: false
  });

  const languageFilter = useLanguageQuery();
  const submitButton = useRef<any>(null);
  const districtRef = useRef<any>(null);
  const themeRef = useRef<any>(null);
  const phaseRef = useRef<any>(null);
  const typeRef = useRef<any>(null);

  const initialize = (key: string) => {
    setInitialized((prev: InitializationMap) => ({ ...prev, [key]: true }));
  };

  const { districts, project_theme, project_phase, project_type } = initialized;

  const clearSelection = (selection: OptionType, selectionType: string) => {
    const newValue = {...searchState}
    let ref;

    switch (selectionType) {
      case 'districts':
        ref = districtRef;
        break;
      case 'project_theme':
        ref = themeRef;
        break;
      case 'project_phase':
        ref = phaseRef;
        break;
      case 'project_type':
        ref = typeRef;
        break;
      default:
        break;
    }

    const index = newValue[selectionType].value.findIndex((option: any) => {
      return option.value === selection.value;
    });

    if (index !== undefined) {
      newValue[selectionType].value.splice(index, 1);
    }

    ref?.current.setQuery({ value: newValue[selectionType].value });
    submitButton.current.setQuery(getQuery({searchState: newValue, languageFilter}));
  };

  return (
    <form onSubmit={(e) => e.preventDefault()}>
      <div className="district-project-search-form__filters-container">
        <div className="district-project-search-form__filters">
          <ReactiveComponent
            componentId={SearchComponents.TITLE}
            defaultQuery={() => ({
              query: languageFilter
            })}
            render={({ setQuery }) => {
              return (
                <Text 
                  componentId={SearchComponents.TITLE}
                  initialValue={initialParams[SearchComponents.TITLE as keyof InitialParam] ?? []}
                  initialize={initialize}
                  label={Drupal.t('Name of residential area or project', {}, { context: 'District and project search form label' })}
                  placeholder={Drupal.t('Use a search word such as "Pasila"', {}, { context: 'District and project search form label' })}
                  setQuery={setQuery}
                  searchState={searchState}
                />
              )}}
            URLParams={false}
          />
          <ReactiveComponent
            componentId={SearchComponents.DISTRICTS}
            ref={districtRef}
            defaultQuery={() => ({
              aggs: {
                [IndexFields.FIELD_PROJECT_DISTRICT_TITLE]: {
                  terms: {
                    field: `${IndexFields.FIELD_PROJECT_DISTRICT_TITLE_FOR_UI}`,
                    size: 500,
                    order: { _key: 'asc' }
                  }
                },
                [IndexFields.TITLE]: {
                  terms: {
                    field: `${IndexFields.TITLE_FOR_UI}`,
                    size: 500,
                    order: { _key: 'asc' }
                  }
                },
                [IndexFields.FIELD_DISTRICT_SUBDISTRICTS_TITLE]: {
                  terms: {
                    field: `${IndexFields.FIELD_DISTRICT_SUBDISTRICTS_TITLE_FOR_UI}`,
                    size: 500,
                    order: { _key: 'asc' }
                  }
                },
                districts_for_filters: {
                  terms: {
                    field: `${IndexFields.DISTRICTS_FOR_FILTERS_DISTRICT_TITLE}`,
                    size: 500,
                    order: { _key: 'asc' }
                  }
                }
              },
              query: languageFilter
            })}
            render={({ setQuery }) => {
              return (
                <Dropdown
                  componentId={SearchComponents.DISTRICTS}
                  indexKey={IndexFields.FIELD_PROJECT_DISTRICT_TITLE}
                  filterKey="districts_for_filters"
                  initialValue={initialParams[SearchComponents.DISTRICTS as keyof InitialParam] ?? []}
                  initialize={initialize}
                  icon={<IconLocation />}
                  label={Drupal.t('Select the residential area from the list', {}, { context: 'District and project search form label' })}
                  placeholder={Drupal.t('Select area', {}, { context: 'District and project search form label' })}
                  setQuery={setQuery}
                  searchState={searchState}
                />
              )}}
            URLParams={false}
          />
        </div>
        <Accordion
          className='district-project-search-form__additional-filters'
          size='s'
          initiallyOpen={new URLSearchParams(window.location.search).toString() ? true : false}
          headingLevel={4}
          heading={Drupal.t('Refine the project search', {}, { context: 'District and project search' })}
          language={window.drupalSettings.path.currentLanguage || 'fi'}
          theme={{
            '--header-font-size': 'var(--fontsize-heading-xxs)',
            '--header-line-height': 'var(--lineheight-s)',
          }}
        >
          <div className='district-project-search-form__filters'>
            <ReactiveComponent
              componentId={SearchComponents.THEME}
              ref={themeRef}
              defaultQuery={() => ({
                aggs: {
                  [IndexFields.FIELD_PROJECT_THEME_NAME]: {
                    terms: {
                      field: `${IndexFields.FIELD_PROJECT_THEME_NAME}`,
                      size: 500,
                      order: { _key: 'asc' }
                    }
                  },
                  project_theme_taxonomy_terms: {
                    terms: {
                      field: `${IndexFields.PROJECT_THEME_NAME}`,
                      size: 500,
                      order: { _key: 'asc' }
                    }
                  }
                },
                query: languageFilter,
              })}
              render={({ setQuery }) => {
                return (
                  <Dropdown
                    componentId={SearchComponents.THEME}
                    indexKey={IndexFields.FIELD_PROJECT_THEME_NAME}
                    filterKey="project_theme_taxonomy_terms"
                    initialValue={initialParams[SearchComponents.THEME as keyof InitialParam] ?? []}
                    initialize={initialize}
                    label={Drupal.t('Project theme', {}, { context: 'District and project search form label' })}
                    placeholder={Drupal.t('All themes', {}, { context: 'District and project search form label' })}
                    setQuery={setQuery}
                    searchState={searchState}
                  />
                )}}
              URLParams={false}
            />
            <ReactiveComponent
              componentId={SearchComponents.PHASE}
              ref={phaseRef}
              defaultQuery={() => ({
                aggs: {
                  [IndexFields.FIELD_PROJECT_PHASE_NAME]: {
                    terms: {
                      field: `${IndexFields.FIELD_PROJECT_PHASE_NAME}`,
                      size: 500,
                      order: { _key: 'asc' }
                    }
                  },
                  project_phase_taxonomy_terms: {
                    terms: {
                      field: `${IndexFields.PROJECT_PHASE_NAME}`,
                      size: 500,
                      order: { _key: 'asc' }
                    }
                  }
                },
                query: languageFilter
              })}
              render={({ setQuery }) => {
                return (
                  <Dropdown
                    componentId={SearchComponents.PHASE}
                    indexKey={IndexFields.FIELD_PROJECT_PHASE_NAME}
                    filterKey="project_phase_taxonomy_terms"
                    initialValue={initialParams[SearchComponents.PHASE as keyof InitialParam] ?? []}
                    initialize={initialize}
                    label={Drupal.t('Project stage', {}, { context: 'District and project search form label' })}
                    placeholder={Drupal.t('All stages', {}, { context: 'District and project search form label' })}
                    setQuery={setQuery}
                    searchState={searchState}
                  />
                )}}
              URLParams={false}
            />
            <ReactiveComponent
              componentId={SearchComponents.TYPE}
              ref={typeRef}
              defaultQuery={() => ({
                aggs: {
                  [IndexFields.FIELD_PROJECT_TYPE_NAME]: {
                    terms: {
                      field: `${IndexFields.FIELD_PROJECT_TYPE_NAME}`,
                      size: 500,
                      order: { _key: 'asc' }
                    }
                  },
                  project_type_taxonomy_terms: {
                    terms: {
                      field: `${IndexFields.PROJECT_TYPE_NAME}`,
                      size: 500,
                      order: { _key: 'asc' }
                    }
                  }
                },
                query: languageFilter
              })}
              render={({ setQuery }) => {
                return (
                  <Dropdown
                    componentId={SearchComponents.TYPE}
                    indexKey={IndexFields.FIELD_PROJECT_TYPE_NAME}
                    filterKey="project_type_taxonomy_terms"
                    initialValue={initialParams[SearchComponents.TYPE as keyof InitialParam] ?? []}
                    initialize={initialize}
                    label={Drupal.t('Project type', {}, { context: 'District and project search form label' })}
                    placeholder={Drupal.t('All types', {}, { context: 'District and project search form label' })}
                    setQuery={setQuery}
                    searchState={searchState}
                  />
                )}}
              URLParams={false}
            />
          </div>
        </Accordion>
        <ReactiveComponent
          componentId={SearchComponents.SUBMIT}
          ref={submitButton}
          render={({ setQuery }) => {
            return (
              <div className='district-project-search-form__submit'>
                <SubmitButton
                  initialized={districts && project_theme && project_phase && project_type}
                  searchState={searchState}
                  setQuery={setQuery}
                />
              </div>
            );
          }}
          URLParams={false}
        />
        <ReactiveComponent
          componentId={SearchComponents.FILTER_BULLETS}
          render={() => {
            return (
              <SelectionsContainer
                searchState={searchState}
                setSearchState={setSearchState}
                clearSelection={clearSelection}
              />
            );
          }}
          URLParams={false}
        />
      </div>
    </form>
  );
};

export default FormContainer;
