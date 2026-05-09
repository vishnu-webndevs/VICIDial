"use client";

import { MenuItem, TextField, TextFieldProps } from "@mui/material";

type Option = { label: string; value: string };

export function FormSelect({
  options,
  ...props
}: TextFieldProps & {
  options: Option[];
}) {
  const hasExplicitShrink = typeof props.InputLabelProps?.shrink === "boolean";
  const shouldForceShrink = Boolean(props.label) && (props.value !== undefined || props.defaultValue !== undefined);

  const resolvedInputLabelProps = hasExplicitShrink
    ? props.InputLabelProps
    : shouldForceShrink
      ? { ...(props.InputLabelProps ?? {}), shrink: true }
      : props.InputLabelProps;

  const spacingSx = props.label ? { my: 0.75 } : undefined;

  return (
    <TextField
      select
      fullWidth
      size="medium"
      {...props}
      InputLabelProps={resolvedInputLabelProps}
      sx={{ ...(spacingSx ?? {}), ...((props.sx as object) || {}) }}
    >
      {options.map((option) => (
        <MenuItem key={option.value} value={option.value}>
          {option.label}
        </MenuItem>
      ))}
    </TextField>
  );
}
