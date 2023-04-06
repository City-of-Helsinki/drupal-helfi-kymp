import { ReactiveBase } from '@appbaseio/reactivesearch';

import Settings from '../enum/Settings';

type Props = {
  children: React.ReactElement;
};

const BaseContainer = ({ children }: Props) => {
  const { elastic_proxy_url } = drupalSettings.helfi_kymp_district_project_search || '';

  if (!elastic_proxy_url && !process.env.REACT_APP_ELASTIC_URL) {
    return null;
  }

  return (
    <ReactiveBase
      app={Settings.INDEX}
      url={elastic_proxy_url || process.env.REACT_APP_ELASTIC_URL}
      theme={{
        typography: {
          fontFamily: 'inherit'
        }
      }}
      // Param props set only to prevent functionality
      getSearchParams={() => ''}
      setSearchParams={() => ''}
    >
      {children}
    </ReactiveBase>
  );
};

export default BaseContainer;
