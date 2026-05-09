"use client";

import { DatePicker as MuiDatePicker } from "@mui/x-date-pickers/DatePicker";
import { LocalizationProvider } from "@mui/x-date-pickers/LocalizationProvider";
import { AdapterDayjs } from "@mui/x-date-pickers/AdapterDayjs";
import dayjs, { Dayjs } from "dayjs";

export function DatePicker({
  value,
  onChange,
  label,
}: {
  value: string | null;
  onChange: (value: string | null) => void;
  label?: string;
}) {
  return (
    <LocalizationProvider dateAdapter={AdapterDayjs}>
      <MuiDatePicker
        label={label}
        value={value ? dayjs(value) : null}
        onChange={(next: Dayjs | null) => onChange(next ? next.toISOString() : null)}
        slotProps={{
          textField: {
            size: "medium",
            fullWidth: true,
          },
        }}
      />
    </LocalizationProvider>
  );
}
