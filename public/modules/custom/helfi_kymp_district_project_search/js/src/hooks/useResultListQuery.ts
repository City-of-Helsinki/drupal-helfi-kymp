import IndexFields from '../enum/IndexFields';


export const useResultListQuery = () => {
  return {
    bool: {
      filter: [
        { term: { _language: window.drupalSettings.path.currentLanguage || 'fi' } },
        { terms: { [`${IndexFields.CONTENT_TYPE}`]: ["project", "district"] } },
      ],
    }
  };
};

export default useResultListQuery;
