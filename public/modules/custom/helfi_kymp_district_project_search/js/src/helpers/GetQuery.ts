import IndexFields from '../enum/IndexFields';
import SearchComponents from '../enum/SearchComponents';
import type BooleanQuery from '../types/BooleanQuery';
import type SearchState from '../types/SearchState';
import { ComponentMap } from './helpers';

type GetQueryProps = {
  searchState?: SearchState;
  languageFilter: any;
};

const getQuery = ({ searchState, languageFilter }: GetQueryProps) => {
  const weight: number = 2;

  let query: BooleanQuery = {
    function_score: {
      query: {
        bool: {
          should: [
            {
              bool: {
                _name: "Match district",
                should: [],
                filter: {
                  term: {
                    _index: "districts"
                  }
                }
              }
            },
            {
              bool: {
                _name: "Match Project",
                should: [],
                must: [],
                filter: {
                  term: {
                    _index: "projects"
                  }
                }
              }
            }
          ],
          filter: languageFilter.bool.filter,
        },
      },
      functions: [
        {
          filter: { term: { content_type: "district" } },
          weight: weight,
        }
      ],
      score_mode: "sum",
      boost_mode: "max", // max of query score and function score
      min_score: 0,
    },
  }

  const isProjectFilterSet = Object.keys(ComponentMap).filter((item: string) => item !== 'title' && item !== 'districts')
    .find((key: string) => searchState?.[key]?.value?.length);
  const isDistrictFilterSet = searchState?.['districts']?.value?.length;
  const isTitleFilterSet = searchState?.['title']?.value?.length;

  Object.keys(ComponentMap).forEach((key: string) => {
    const state = searchState?.[key] || null;

    if (state && state.value && state.value.length) {
      query.function_score.min_score = (isProjectFilterSet && isDistrictFilterSet) || (isProjectFilterSet && isTitleFilterSet) ? Number(100) : Number(weight + 1);

      if (key === SearchComponents.TITLE) {
        const districtWildcards: object[] = [];
        const projectWildcards: object[] = [];

        state.value.forEach((value: any) => {
          districtWildcards.push({ wildcard: { [IndexFields.TITLE]: { value: `*${value.value.toLowerCase()}*`, boost: 300 } } });
          districtWildcards.push({ wildcard: { [IndexFields.FIELD_DISTRICT_SUBDISTRICTS_TITLE]: { value: `*${value.value.toLowerCase()}*`, boost: 200 } } });
          districtWildcards.push({ wildcard: { [IndexFields.FIELD_DISTRICT_SEARCH_METATAGS]: { value: `*${value.value.toLowerCase()}*`, boost: 150 } } });

          projectWildcards.push({ wildcard: { [`${IndexFields.TITLE}`]: { value: `*${value.value.toLowerCase()}*`, boost: 150 } } });
          // if project filter is also set, boost projects.
          projectWildcards.push({ wildcard: { [IndexFields.FIELD_PROJECT_DISTRICT_TITLE]: { value: `*${value.value.toLowerCase()}*`, boost: isProjectFilterSet ? 3000 : 150 } } });
          projectWildcards.push({ wildcard: { [IndexFields.FIELD_PROJECT_SEARCH_METATAGS]: { value: `*${value.value.toLowerCase()}*`, boost: 150 } } });
        });

        query.function_score.query.bool.should[0].bool.should.push(...districtWildcards);
        query.function_score.query.bool.should[1].bool.should.push(...projectWildcards);
      }
      else if (key === SearchComponents.DISTRICTS) {
        const districtTerms: object[] = [];
        const projectTerms: object[] = [];

        state.value.forEach((value: any) => {
          districtTerms.push({ term: { [IndexFields.TITLE]: { value: value.value.toLowerCase(), boost: isProjectFilterSet ? 150 : 1000 } } });
          // if project filter is also set, don't boost districts with subdistricts.
          districtTerms.push({ term: { [IndexFields.FIELD_DISTRICT_SUBDISTRICTS_TITLE]: { value: value.value.toLowerCase(), boost: isProjectFilterSet ? 0 : 1000 } } });

          projectTerms.push({ term: { [IndexFields.TITLE]: { value: value.value.toLowerCase(), boost: isProjectFilterSet ? 3000 : 150 } } });
          // if project filter is also set, boost projects.
          projectTerms.push({ term: { [IndexFields.FIELD_PROJECT_DISTRICT_TITLE]: { value: value.value.toLowerCase(), boost: isProjectFilterSet ? 3000 : 150 } } });
        });

        query.function_score.query.bool.should[0].bool.should.push(...districtTerms);
        query.function_score.query.bool.should[1].bool.should.push(...projectTerms);
      }
      else {
        state.value.forEach((value: any) => {
          query.function_score.query.bool.should[1].bool.must?.push({
            term: {
              [ComponentMap[key]]: { value: value.value, boost: 50 }
            }
          })
        });
      }
    }
  });

  return {
    query: query,
    // add Submit component value by default.
    value: Number(searchState?.submit?.value) + 1 || 1
  };
}

export default getQuery;
