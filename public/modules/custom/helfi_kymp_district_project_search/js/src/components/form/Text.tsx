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

  useEffect(() => {
    if (loading) {
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
  }, [componentId, initialize, initialValue, loading, setQuery]);

  const title: string = searchState[componentId]?.value?.[0]?.value;

  return (
    <TextInput
      id="district-or-project-name"
      label={label}
      placeholder={placeholder}
      defaultValue={title}
      onChange={({ target: { value } }) => {
        if (value) {
          setQuery({value: [{value: value}]});
        } else {
          setQuery({value: []});
        }
      }}
    />
  );
};

export default Text;
