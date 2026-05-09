"use client";

import { TextField, TextFieldProps } from "@mui/material";

export function FormTextField(props: TextFieldProps) {
  const hasExplicitShrink = typeof props.InputLabelProps?.shrink === "boolean";
  const inputType = typeof props.type === "string" ? props.type : undefined;
  const shouldForceShrink =
    Boolean(props.label) &&
    (props.value !== undefined ||
      props.defaultValue !== undefined ||
      Boolean(props.placeholder) ||
      (inputType !== undefined && ["date", "time", "datetime-local", "month", "week"].includes(inputType)));

  const resolvedInputLabelProps = hasExplicitShrink
    ? props.InputLabelProps
    : shouldForceShrink
      ? { ...(props.InputLabelProps ?? {}), shrink: true }
      : props.InputLabelProps;

  const spacingSx = props.label ? { my: 0.75 } : undefined;

  return (
    <TextField
      fullWidth
      size="medium"
      {...props}
      InputLabelProps={resolvedInputLabelProps}
      sx={{
        ...(spacingSx ?? {}),
        "& .MuiOutlinedInput-root": {
          borderRadius: "0.375rem",
          fontSize: "0.9375rem",
          color: "#697a8d",
          "& fieldset": {
            borderColor: "#d9dee3",
          },
          "&:hover fieldset": {
            borderColor: "#c7cdd4",
          },
          "&.Mui-focused fieldset": {
            borderColor: "#696cff",
            borderWidth: 1,
          },
        },
        "& .MuiInputLabel-root": {
          color: "#566a7f",
          fontSize: "0.8125rem",
          "&.Mui-focused": {
            color: "#696cff",
          },
        },
        ...((props.sx as object) || {}),
      }}
    />
  );
}
