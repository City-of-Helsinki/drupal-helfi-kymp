const SortOptions = [
  { label: Drupal.t('Most relevant first', {}, { context: 'District and project search sort option' }), value: 'most_relevant' },
  { label: Drupal.t('Alphabetical @AO', {'@AO':'A-Ö'}, { context: 'District and project search sort option' }), value: 'a_o' },
  { label: Drupal.t('Alphabetical @OA', {'@OA': 'Ö-A'}, { context: 'District and project search sort option' }), value: 'o_a' }
];

export default SortOptions;
