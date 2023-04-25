//@ts-ignore
import { ReactiveBase } from '@appbaseio/reactivesearch';
import { render } from '@testing-library/react';
import { rest } from 'msw';
import { setupServer } from 'msw/node';
import { FC, ReactElement, ReactNode } from 'react';

import mockResponse from './mockResponse.json';

export const renderWithStore = (ui: ReactElement, renderOptions = {}) => {
  const Wrapper: FC<{ children: ReactNode }> = ({ children }) => {
    return (
      <ReactiveBase app="testing" url="https://helfi-kymp.docker.so/fi">
        {children}
      </ReactiveBase>
    );
  };

  return render(ui, { wrapper: Wrapper, ...renderOptions });
};

export const server = setupServer(
  rest.post('https://helfi-kymp.docker.so/fi/testing/_msearch', (req, res, ctx) => {
    return res(ctx.json(mockResponse));
  })
);
