import { Button } from 'hds-react';
import { useEffect, useState } from 'react';

import { useLanguageQuery } from '../../hooks/useLanguageQuery';
import getQuery from '../../helpers/GetQuery';
import type SearchState from '../../types/SearchState';
import { setParams } from '../../helpers/Params';

type SubmitButtonProps = {
  initialized: boolean;
  searchState: SearchState;
  setQuery: Function;
};

export const SubmitButton = ({ initialized, searchState, setQuery }: SubmitButtonProps) => {
  const [mounted, setMounted] = useState<boolean>(false);
  const languageFilter = useLanguageQuery();

  useEffect(() => {
    if (initialized && !mounted) {
      setQuery(getQuery({searchState, languageFilter}));
      setMounted(true);
    }
  }, [getQuery, initialized, mounted, setMounted, setQuery]);

  return (
    <Button
      className='district-project-search-form__submit-button'
      type='submit'
      disabled={!initialized}
      onClick={() => {
        setQuery(getQuery({searchState, languageFilter}));
        setParams(searchState);
      }}
      variant='primary'
      theme='black'
    >
      {Drupal.t('Search', {}, { context: 'District and project search' })}
    </Button>
  );
};

export default SubmitButton;
