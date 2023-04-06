import SearchComponents from '../enum/SearchComponents';
import type SearchState from '../types/SearchState';
import DrupalSearchParams from './DrupalSearchParams';

const MASK_KEYS = [
  SearchComponents.TITLE,
  SearchComponents.DISTRICTS,
  SearchComponents.THEME,
  SearchComponents.PHASE,
  SearchComponents.TYPE,
  SearchComponents.RESULTS
];

export const getInitialValues = () => {
  const params = new DrupalSearchParams(window.location.search);

  return params.toInitialValue();
};

const updateParams = (
  searchState: SearchState,
  searchParams: DrupalSearchParams = new DrupalSearchParams(),
  mask: string[] | null = null
) => {
  const keyArray = mask || MASK_KEYS;

  keyArray.forEach((key: string) => {
    if (!searchState[key]?.hasOwnProperty('value') || !keyArray.includes(key)) {
      return;
    }

    const value = searchState[key].value;

    if (Array.isArray(value)) {
      const transformedValue = value.map((selection: any) => selection.value);
      searchParams.set(key, JSON.stringify(transformedValue));
    } else if (value) {
      searchParams.set(key, value);
    } else {
      searchParams.delete(key);
    }
  });

  return searchParams;
};

/**
 * Update URL parameters.
 * @param searchState current searchState
 * @returns
 */
export const setParams = (searchState: any) => {
  const searchParams = new DrupalSearchParams();
  const transformedParams = updateParams(searchState, searchParams);
  
  try {
    const allParamsString = transformedParams.toString();

    // If resulting string is the same as current one, do nothing.
    if (window.location.search === allParamsString) {
      return;
    }

    const newUrl = new URL(window.location.pathname, window.location.origin);
    newUrl.search = allParamsString;
    window.history.pushState({}, '', newUrl.toString());
  } catch (e) {
    console.log(e)
    console.warn('Error setting URL parameters.');
  }
};

export const clearParams = () => {
  const newUrl = new URL(window.location.pathname, window.location.origin);
  window.history.pushState({}, '', newUrl.toString());
};
