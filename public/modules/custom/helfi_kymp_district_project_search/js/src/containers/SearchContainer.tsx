import { StateProvider } from '@appbaseio/reactivesearch';

import FormContainer from './FormContainer';
import ResultsContainer from './ResultsContainer';
import { getInitialValues } from '../helpers/Params';

const SearchContainer = (): JSX.Element => {
  const initialParams = getInitialValues();

  return (
    <div>
      <StateProvider includeKeys={['value', 'aggregations']}>
        {({ searchState, setSearchState }) => (
          <>
            <FormContainer initialParams={initialParams} searchState={searchState} setSearchState={setSearchState} />
            <ResultsContainer initialParams={initialParams} searchState={searchState} />
          </>
        )}
      </StateProvider>
    </div>
  );
};

export default SearchContainer;
