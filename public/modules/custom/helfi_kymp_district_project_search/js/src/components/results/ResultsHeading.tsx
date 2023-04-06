import { StateProvider, ReactiveComponent } from '@appbaseio/reactivesearch';

import useLanguageQuery from '../../hooks/useLanguageQuery';
import SearchComponents from '../../enum/SearchComponents';
import SortDropdown from '../../components/form/SortDropdown';

type ResultsHeadingProps = {
  setSort: Function;
};

function ResultsHeading({ setSort }: ResultsHeadingProps): JSX.Element {
  const { RESULT_STATS, SORT } = SearchComponents;
  const languageFilter = useLanguageQuery();

  return (
    <div className="district-project-search__results_heading">
      <div className="district-project-search__count__container">
        <ReactiveComponent
          componentId={RESULT_STATS}
          URLParams={false}
          defaultQuery={() => ({
            query: languageFilter
          })}
          render={() => {
            return (
              <StateProvider
                render={({ searchState }) => {
                  return (
                    <span className="district-project-search__count">
                      <span className="district-project-search__count-total">{searchState?.page?.hits?.total} </span>
                      <span className="district-project-search__count-label">{Drupal.t('search results', {}, { context: 'District and project search' })} </span>
                    </span>
                  );
                }}
              />
            );
          }}
        />
      </div>
      <div className="district-project-search__sort__container">
        <ReactiveComponent
          componentId={SORT}
          URLParams={false}
          defaultQuery={() => ({
            query: languageFilter,
          })}
          render={({ setQuery }) => {
            return (
              <StateProvider includeKeys={['value']}
                render={({ searchState }) => {
                  return (
                    <SortDropdown
                      componentId={SORT}
                      label={Drupal.t('Sort search results', {}, { context: 'District and project search form label' })}
                      setQuery={setQuery}
                      searchState={searchState}
                      setSort={setSort}
                    />
                  );
                }}
              />
            );
          }}
        />
      </div>
    </div>
  );
};

export default ResultsHeading;
