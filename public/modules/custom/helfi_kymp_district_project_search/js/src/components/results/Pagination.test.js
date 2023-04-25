import { render } from '@testing-library/react';

import Pagination from './Pagination';

const mockProps = {
  pages: 5,
  totalPages: 20,
  currentPage: 10,
  setPage: () => null,
  setSize: () => null,
};

test('Renders correctly', () => {
  render(<Pagination {...mockProps} />);

  // Should render 9 li-elements (ellipses and first / last page included)
  const liArray = document.querySelectorAll('li');
  expect(liArray.length).toEqual(9);

  // Expect next and previous to render correctly
  const prevButton = document.querySelector('.hds-pagination__button-prev');
  expect(prevButton.tagName).toEqual('A');
  const nextButton = document.querySelector('.hds-pagination__button-next');
  expect(nextButton.tagName).toEqual('A');

  // Expect current page item content to be currentPage + 1
  const currentItem = document.querySelector('.pager__item.is-active a');
  expect(parseInt(currentItem.textContent)).toEqual(mockProps.currentPage + 1);
});
