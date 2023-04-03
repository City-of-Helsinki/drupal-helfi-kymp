import { Button, IconCross } from 'hds-react';
import { ReactElement, memo } from 'react';

import type OptionType from '../types/OptionType';

import { capitalize } from '../helpers/helpers';
import { clearParams } from '../helpers/Params';

import SearchComponents from '../enum/SearchComponents';

type SelectionsContainerProps = {
  searchState: any;
  setSearchState: Function;
  clearSelection: Function;
};

const SelectionsContainer = ({ searchState, setSearchState, clearSelection }: SelectionsContainerProps) => {
  const clearSelections = () => {
    setSearchState({});
    clearParams();
  };

  const filters: ReactElement<HTMLLIElement>[] = [];

  [SearchComponents.DISTRICTS, SearchComponents.THEME, SearchComponents.PHASE, SearchComponents.TYPE].forEach((key) => {
    if (searchState[key]?.value?.length) {
      searchState[key].value.forEach((value: OptionType) =>
        filters.push(
          <li
            className='content-tags__tags__tag content-tags__tags--interactive'
            key={`${key}-${value.value}`}
            onClick={() => clearSelection(value, key)}
          >
            <Button
              aria-label={Drupal.t(
                'Remove @item from search results',
                { '@item': value.value },
                { context: 'Search: remove item aria label' }
              )}
              className='district-project-search-form__remove-selection-button'
              iconRight={<IconCross />}
              variant='supplementary'
            >
              {capitalize(value.value)}
            </Button>
          </li>
        )
      );
    }
  });

  if (!filters.length) {
    return null;
  }

  return (
    <div className='district-project-search-form__selections-wrapper'>
      <ul className='district-project-search-form__selections-container content-tags__tags'>
        {filters}
        <li className='district-project-search-form__clear-all'>
          <Button
            aria-hidden={filters.length ? 'true' : 'false'}
            className='district-project-search-form__clear-all-button'
            iconLeft={<IconCross className='district-project-search-form__clear-all-icon' />}
            onClick={clearSelections}
            style={filters.length ? {} : { visibility: 'hidden' }}
            variant='supplementary'
          >
            {Drupal.t('Clear selections', {}, { context: 'District and project search' })}
          </Button>
        </li>
      </ul>
    </div>
  );
};

const updateSelections = (prev: SelectionsContainerProps, next: SelectionsContainerProps) => {
  if (prev.searchState[SearchComponents.SUBMIT]?.value === next.searchState[SearchComponents.SUBMIT]?.value) {
    return true;
  }

  return false;
};

export default memo(SelectionsContainer, updateSelections);
