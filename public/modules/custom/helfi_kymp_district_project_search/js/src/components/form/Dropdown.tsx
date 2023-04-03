import { useEffect, useState } from 'react';
import { Combobox } from 'hds-react';
import type { ComboboxProps } from 'hds-react';

import useAggregations from '../../hooks/useAggregations';
import type { Aggregations } from '../../types/Aggregations';
import type OptionType from '../../types/OptionType';
import type SearchState from '../../types/SearchState';


type DropdownProps = Omit<
  ComboboxProps<OptionType>,
  'options' | 'clearButtonAriaLabel' | 'selectedItemRemoveButtonAriaLabel' | 'toggleButtonAriaLabel'
> & {
  componentId: string;
  indexKey: string;
  filterKey: string;
  initialValue: string[];
  initialize: Function;
  icon?: JSX.Element;
  label: string;
  placeholder: string;
  setQuery: Function;
  searchState: SearchState;
  clearButtonAriaLabel?: string;
  selectedItemRemoveButtonAriaLabel?: string;
  toggleButtonAriaLabel?: string;
};

const getAggregations = (searchStateValues: any, componentId: string) => {
  return !searchStateValues?.[componentId]?.aggregations ? [] : searchStateValues[componentId].aggregations;
};

const getDropdownValues = (searchStateValue: any, componentId: string, options: OptionType[]): OptionType[] => {
  if (!searchStateValue?.[componentId]?.value) {
    return [];
  }

  return options.filter(item => searchStateValue[componentId].value.find((val: OptionType) => val.value === item.value));
};

export const Dropdown = ({
  componentId,
  indexKey,
  filterKey,
  initialValue,
  initialize,
  icon,
  label,
  placeholder,
  setQuery,
  clearButtonAriaLabel = Drupal.t('Clear selection', {}, { context: 'District and project search clear button aria label' }),
  selectedItemRemoveButtonAriaLabel = Drupal.t('Remove item', {}, { context: 'District and project search remove item aria label' }),
  toggleButtonAriaLabel = Drupal.t('Open the combobox', {}, { context: 'District and project search open dropdown aria label' }),
  searchState,
}: DropdownProps): JSX.Element => {
  const aggregations: Aggregations = getAggregations(searchState, componentId)
  const options: OptionType[] = useAggregations(aggregations, indexKey, filterKey);
  const [value, setValue] = useState<OptionType[]>(() => getDropdownValues(searchState, componentId, options));
  const [loading, setLoading] = useState<boolean>(true);

  useEffect(() => {
    if (loading && aggregations && options) {
      if (!initialValue.length) {
        initialize(componentId);
        setLoading(false);
        return;
      }

      const values: OptionType[] = [];

      initialValue.forEach((value: string) => {
        values.push({value: value});
      });

      setQuery({
        value: values,
      });

      initialize(componentId);
      setLoading(false);
    }
  }, [aggregations, componentId, initialize, initialValue, loading, options, setQuery]);
  
  useEffect(() => {
    setValue(getDropdownValues(searchState, componentId, options))
  }, [searchState]);

  return (
    <div className="district-project-search-form__filter">
      <Combobox
        clearButtonAriaLabel={clearButtonAriaLabel}
        disabled={loading}
        label={label}
        icon={icon}
        // @ts-ignore
        options={options}
        onChange={(values: OptionType[]) => {
          let valuesWithoutLabel = values.map(({label, ...values}) => values);
          setQuery({
            value: valuesWithoutLabel,
          });
        }}
        placeholder={placeholder}
        multiselect={true}
        selectedItemRemoveButtonAriaLabel={selectedItemRemoveButtonAriaLabel}
        toggleButtonAriaLabel={toggleButtonAriaLabel}
        value={value}
        theme={{
          '--focus-outline-color': 'var(--hdbt-color-black)',
          '--multiselect-checkbox-background-selected': 'var(--hdbt-color-black)',
          '--placeholder-color': 'var(--hdbt-color-black)',
        }}
      />
    </div>
  );
};

export default Dropdown;
