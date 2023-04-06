import React from 'react';
import ReactDOM from 'react-dom';

import BaseContainer from './containers/BaseContainer';
import SearchContainer from './containers/SearchContainer';

const rootSelector: string = 'helfi-kymp-district-project-search';
const rootElement: HTMLElement | null = document.getElementById(rootSelector);

if (rootElement) {
  ReactDOM.render(
    <React.StrictMode>
      <BaseContainer>
        <SearchContainer />
      </BaseContainer>
    </React.StrictMode>,
    rootElement
  );
}

