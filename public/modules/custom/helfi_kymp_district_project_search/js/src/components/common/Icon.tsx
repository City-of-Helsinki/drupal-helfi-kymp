interface IconProps {
  icon: string;
  className?: string;
  label?: string;
};

export const Icon = (props: IconProps): JSX.Element => {
  const {
    icon,
    className,
    label,
  } = props;

  return (
    <span
      className={`hel-icon hel-icon--${icon} ${className}`}
      aria-label={label}
      aria-hidden={ label ? 'true' : 'false'}>
    </span>
  );
};

export default Icon;
