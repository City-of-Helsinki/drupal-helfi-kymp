import { useEffect, useState } from 'react';
import { TextInput } from 'hds-react';

import type OptionType from '../../types/OptionType';
import type SearchState from '../../types/SearchState';

type TextProps = {
  componentId: string;
  initialValue: string[];
  initialize: Function;
  label: string;
  placeholder: string;
  setQuery: Function;
  searchState: SearchState;
};

export const Text = ({
  componentId,
  initialValue,
  initialize,
  label,
  placeholder,
  setQuery,
  searchState,
}: TextProps): JSX.Element => {
  const [loading, setLoading] = useState<boolean>(true);
  const [value, setValue] = useState<string>();

  useEffect(() => {
    if (loading) {
      if (!initialValue.length) {
        initialize(componentId);
        setLoading(false);
        return;
      }

      const values: OptionType[] = [];

      initialValue.forEach((value: string) => {
        values.push({ value: value });
      });

      setQuery({
        value: values,
      });

      initialize(componentId);
      setLoading(false);
    }
  }, [componentId, initialize, initialValue, loading, setQuery]);

  useEffect(() => {
    const newValue = !searchState?.[componentId]?.value ? '' : searchState[componentId]?.value?.[0]?.value;
    setValue(newValue);
  }, [searchState]);

  return (
    <TextInput
      id="district-or-project-name"
      label={label}
      placeholder={placeholder}
      value={value}
      onChange={({ target: { value } }) => {
        setValue(value);
        if (value) {
          setQuery({ value: [{ value: value }] });
        } else {
          setQuery({ value: [] });
        }
      }}
    />
  );
};

export default Text;
