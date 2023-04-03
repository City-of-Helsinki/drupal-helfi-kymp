import { useRef, useState } from 'react';
import { ReactiveList } from '@appbaseio/reactivesearch';

import Pagination from '../components/results/Pagination';
import ResultCard from '../components/results/ResultCard';
import ResultsHeading from '../components/results/ResultsHeading';

import SearchComponents from '../enum/SearchComponents';
import SortOptions from '../enum/SortOptions';
import IndexFields from '../enum/IndexFields';

import useResultListQuery from '../hooks/useResultListQuery';
import useWindowDimensions from '../hooks/useWindowDimensions';
import { setParams } from '../helpers/Params';

import type Result from '../types/Result';
import type InitialState from '../types/InitialState';
import type SearchState from '../types/SearchState';

type ResultsContainerProps = {
  initialParams: InitialState;
  searchState: SearchState;
};

type ResultsData = {
  data: Result[];
};

const ResultsContainer = ({ initialParams, searchState }: ResultsContainerProps): JSX.Element => {
  const resultListFilter = useResultListQuery();
  const dimensions = useWindowDimensions();
  const resultsWrapper = useRef<HTMLDivElement | null>(null);
  const pages = dimensions.isMobile ? 3 : 5;
  const [sort, setSort] = useState(SortOptions[0]);

  const sorting: any = {
    'most_relevant': {
      _score: { order: "desc" },
      [`${IndexFields.TITLE}`]: { order: "asc" }
    },
    'a_o': {
      [`${IndexFields.TITLE}`]: { order: "asc" },
    },
    'o_a': {
      [`${IndexFields.TITLE}`]: { order: "desc" },
    },
  };
  
  return (
    <div ref={resultsWrapper}>
      <ResultsHeading setSort={setSort} />
      <ReactiveList
        className="district-project-search__container"
        componentId={SearchComponents.RESULTS}
        dataField={IndexFields.TITLE}
        // Seems like a bug in ReactiveSearch.
        // Setting defaultPage prop does nothing.
        // currentPage props used in source but missing in props type declarations.
        // @ts-ignore
        currentPage={initialParams.page}
        onPageChange={() => {
          setParams(searchState);

          if (!resultsWrapper.current) {
            return;
          }

          if (Math.abs(resultsWrapper.current.getBoundingClientRect().y) < window.scrollY) {
            resultsWrapper.current.scrollIntoView({ behavior: 'smooth' });
          }
        }}
        pages={pages}
        pagination={true}
        showResultStats={false}
        size={10}
        defaultQuery={() => ({
          query: {
            ...resultListFilter,
          },
          sort: [
            sorting[sort.value]
          ]
        })}
        react={{
          and: [SearchComponents.SUBMIT]
        }}
        render={({ data }: ResultsData) => {
          return (
            <ul className="district-project-search__listing">
              {data.map((item: Result) => (
                <ResultCard key={item._id} {...item} />
              ))}
            </ul>
          )
        }}
        renderNoResults={() => (
          <div className="district-project-search__listing__no-results">
            <h2>{Drupal.t('Oh no! We did not find anything matching the search terms.', {}, { context: 'District and project search' })}</h2>
            <p>{Drupal.t('Our website currently shows only some of the projects and residential areas of Helsinki. You can try again by removing some of the limiting search terms or by starting over.', {}, { context: 'District and project search' })}</p>
          </div>
        )}
        renderPagination={(props) => <Pagination {...props} />}
      />
    </div>
  );
};

export default ResultsContainer;
