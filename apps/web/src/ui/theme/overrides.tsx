import React from "react";

// Custom checkbox SVG icons (from Materio template)
const CheckboxIcon = () => (
  <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M8 4h8a4 4 0 0 1 4 4v8a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4Z"
      stroke="var(--mui-palette-text-secondary)" strokeWidth="2" />
  </svg>
);
const CheckboxCheckedIcon = () => (
  <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M3 8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Z" fill="currentColor" />
    <path d="m11 13.586 4.596-4.597.707.707L11 15l-3.182-3.182.707-.707L11 13.586Z" fill="var(--mui-palette-common-white)" />
  </svg>
);
const CheckboxIndeterminateIcon = () => (
  <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M3 8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Z" fill="currentColor" />
    <path d="M8.5 11.5h7v1h-7v-1Z" fill="var(--mui-palette-common-white)" />
  </svg>
);

// Custom radio SVG icons
const RadioCheckedIcon = () => (
  <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M12 18.5a6.5 6.5 0 1 1 0-13 6.5 6.5 0 0 1 0 13Z"
      fill="var(--mui-palette-common-white)" stroke="currentColor" strokeWidth="5" />
  </svg>
);
const RadioUncheckedIcon = () => (
  <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M12 20a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z" stroke="var(--mui-palette-text-secondary)" strokeWidth="2" />
  </svg>
);

// Icon size helper for buttons
const iconStyles = (size: string) => ({
  "& > *:nth-of-type(1)": {
    ...(size === "small" ? { fontSize: "14px" } :
      size === "medium" ? { fontSize: "16px" } : { fontSize: "20px" }),
  },
});

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Theme = any;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type OwnerState = any;

export function getComponentOverrides() {
  const disableRipple = false;

  return {
    // ─── Accordion ───
    MuiAccordion: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          transition: theme.transitions.create(["margin", "border-radius", "box-shadow"]),
          boxShadow: "var(--mui-customShadows-xs)",
          "&:not(.Mui-expanded):has(+ .Mui-expanded)": {
            borderBottomLeftRadius: "var(--mui-shape-borderRadius)",
            borderBottomRightRadius: "var(--mui-shape-borderRadius)",
          },
          "&.Mui-expanded": {
            borderRadius: "var(--mui-shape-borderRadius)",
            boxShadow: "var(--mui-customShadows-md)",
            margin: theme.spacing(2, 0),
            "& + .MuiAccordion-root": {
              borderTopLeftRadius: "var(--mui-shape-borderRadius)",
              borderTopRightRadius: "var(--mui-shape-borderRadius)",
              "&:before": { opacity: 0 },
            },
          },
        }),
      },
    },
    MuiAccordionSummary: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(3, 5),
          color: "var(--mui-palette-text-primary)",
          "&.Mui-expanded": { minHeight: 48 },
          "& .MuiTypography-root": {
            color: "inherit",
            fontWeight: theme.typography.fontWeightMedium,
          },
        }),
        content: { margin: "0 !important" },
        expandIconWrapper: {
          color: "var(--mui-palette-text-primary)",
          fontSize: "1.25rem",
          "& i, & svg": { fontSize: "inherit" },
        },
      },
    },
    MuiAccordionDetails: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(0, 5, 5),
          "& .MuiTypography-root": { color: "var(--mui-palette-text-secondary)" },
        }),
      },
    },

    // ─── Alerts ───
    MuiAlert: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(4),
          gap: theme.spacing(4),
          ...theme.typography.body1,
          "&:not(:has(.MuiAlertTitle-root))": {
            "& .MuiAlert-icon + .MuiAlert-message": { alignSelf: "center" },
          },
        }),
        icon: {
          padding: 0,
          margin: 0,
          minWidth: 30,
          height: 30,
          borderRadius: "var(--mui-shape-borderRadius)",
          alignItems: "center",
          justifyContent: "center",
          "& i, & svg": { fontSize: "inherit" },
        },
        message: { padding: 0 },
        action: { padding: 0, marginRight: 0 },
      },
      variants: [
        { props: { variant: "standard", severity: "error" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-error-main)", color: "var(--mui-palette-error-contrastText)" } } },
        { props: { variant: "standard", severity: "warning" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-warning-main)", color: "var(--mui-palette-warning-contrastText)" } } },
        { props: { variant: "standard", severity: "info" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-info-main)", color: "var(--mui-palette-info-contrastText)" } } },
        { props: { variant: "standard", severity: "success" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-success-main)", color: "var(--mui-palette-success-contrastText)" } } },
        { props: { variant: "outlined", severity: "error" }, style: { borderColor: "var(--mui-palette-error-main)", "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-error-mainOpacity)", color: "var(--mui-palette-error-main)" } } },
        { props: { variant: "outlined", severity: "warning" }, style: { borderColor: "var(--mui-palette-warning-main)", "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-warning-mainOpacity)", color: "var(--mui-palette-warning-main)" } } },
        { props: { variant: "outlined", severity: "info" }, style: { borderColor: "var(--mui-palette-info-main)", "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-info-mainOpacity)", color: "var(--mui-palette-info-main)" } } },
        { props: { variant: "outlined", severity: "success" }, style: { borderColor: "var(--mui-palette-success-main)", "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-success-mainOpacity)", color: "var(--mui-palette-success-main)" } } },
        { props: { variant: "filled", severity: "error" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-common-white)", color: "var(--mui-palette-error-main)", boxShadow: "var(--mui-customShadows-xs)" } } },
        { props: { variant: "filled", severity: "warning" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-common-white)", color: "var(--mui-palette-warning-main)", boxShadow: "var(--mui-customShadows-xs)" } } },
        { props: { variant: "filled", severity: "info" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-common-white)", color: "var(--mui-palette-info-main)", boxShadow: "var(--mui-customShadows-xs)" } } },
        { props: { variant: "filled", severity: "success" }, style: { "& .MuiAlert-icon": { backgroundColor: "var(--mui-palette-common-white)", color: "var(--mui-palette-success-main)", boxShadow: "var(--mui-customShadows-xs)" } } },
      ],
    },
    MuiAlertTitle: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          ...theme.typography.h5,
          marginTop: 0,
          marginBottom: theme.spacing(1),
          color: "inherit",
        }),
      },
    },

    // ─── Autocomplete ───
    MuiAutocomplete: {
      defaultProps: { ChipProps: { size: "medium" as const } },
      styleOverrides: {
        root: {
          "& .MuiButtonBase-root.Mui-disabled i, & .MuiButtonBase-root.Mui-disabled svg": {
            color: "var(--mui-palette-action-disabled)",
          },
          "& .MuiOutlinedInput-input": { height: "1.4375em" },
        },
        input: {
          "& + .MuiAutocomplete-endAdornment": {
            right: "1rem",
            "& i, & svg": { fontSize: "1.5rem", color: "var(--mui-palette-text-primary)" },
            "& .MuiAutocomplete-clearIndicator": { padding: 2 },
          },
          "&.MuiInputBase-inputSizeSmall + .MuiAutocomplete-endAdornment": {
            "& i, & svg": { fontSize: "1.375rem" },
          },
        },
        paper: { boxShadow: "var(--mui-customShadows-lg)", marginBlockStart: "0.125rem" },
        listbox: ({ theme }: { theme: Theme }) => ({
          "& .MuiAutocomplete-option": {
            padding: theme.spacing(2, 5),
            '&[aria-selected="true"]': {
              backgroundColor: "var(--mui-palette-primary-lightOpacity)",
              color: "var(--mui-palette-primary-main)",
              "&.Mui-focused, &.Mui-focusVisible": {
                backgroundColor: "var(--mui-palette-primary-mainOpacity)",
              },
            },
          },
          "& .MuiAutocomplete-option.Mui-focusVisible": {
            backgroundColor: "var(--mui-palette-action-hover)",
          },
        }),
      },
    },

    // ─── Avatar ───
    MuiAvatarGroup: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          justifyContent: "flex-end",
          "& .MuiAvatar-root": { borderColor: "var(--mui-palette-background-paper)" },
          "&.pull-up .MuiAvatar-root": {
            cursor: "pointer",
            transition: theme.transitions.create(["box-shadow", "transform"], {
              easing: "ease",
              duration: theme.transitions.duration.shorter,
            }),
            "&:hover": {
              zIndex: 2,
              boxShadow: "var(--mui-customShadows-md)",
              transform: "translateY(-5px)",
            },
          },
        }),
      },
    },
    MuiAvatar: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          color: "var(--mui-palette-text-primary)",
          fontSize: theme.typography.body1.fontSize,
          lineHeight: 1.2,
        }),
      },
    },

    // ─── Backdrop ───
    MuiBackdrop: {
      styleOverrides: {
        root: {
          "&:not(.MuiBackdrop-invisible)": { backgroundColor: "var(--backdrop-color)" },
        },
      },
    },

    // ─── Badge ───
    MuiBadge: {
      styleOverrides: {
        standard: ({ theme }: { theme: Theme }) => ({
          height: 22,
          minWidth: 22,
          borderRadius: 20,
          fontSize: theme.typography.subtitle2.fontSize,
          lineHeight: 1.07,
          padding: theme.spacing(1, 2),
        }),
      },
    },

    // ─── Breadcrumbs ───
    MuiBreadcrumbs: {
      styleOverrides: {
        root: {
          "& svg, & i": { fontSize: "1.25rem" },
          "& a": {
            textDecoration: "none",
            color: "var(--mui-palette-text-secondary)",
            "&:hover": { color: "var(--mui-palette-text-primary)" },
          },
        },
        li: ({ theme }: { theme: Theme }) => ({
          lineHeight: theme.typography.body1.lineHeight,
          "& > *:not(a)": { color: "var(--mui-palette-text-primary)" },
        }),
      },
    },

    // ─── Button ───
    MuiButtonBase: {
      defaultProps: { disableRipple },
    },
    MuiButton: {
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState.variant === "text"
            ? {
                ...(ownerState.size === "small" && { padding: theme.spacing(2, 2.5) }),
                ...(ownerState.size === "medium" && { padding: theme.spacing(2, 3.5) }),
                ...(ownerState.size === "large" && { padding: theme.spacing(2, 4.5) }),
              }
            : ownerState.variant === "outlined"
            ? {
                ...(ownerState.size === "small" && { padding: theme.spacing(1.75, 3.25) }),
                ...(ownerState.size === "medium" && { padding: theme.spacing(1.75, 4.25) }),
                ...(ownerState.size === "large" && { padding: theme.spacing(1.75, 5.25) }),
              }
            : {
                ...(ownerState.size === "small" && { padding: theme.spacing(2, 3.5) }),
                ...(ownerState.size === "medium" && { padding: theme.spacing(2, 4.5) }),
                ...(ownerState.size === "large" && { padding: theme.spacing(2, 5.5) }),
              }),
        }),
        contained: ({ ownerState }: { ownerState: OwnerState }) => ({
          boxShadow: "var(--mui-customShadows-xs)",
          ...(!ownerState.disabled && {
            "&:hover, &.Mui-focusVisible": { boxShadow: "var(--mui-customShadows-xs)" },
            "&:active": { boxShadow: "none" },
          }),
        }),
        sizeSmall: ({ theme }: { theme: Theme }) => ({
          lineHeight: 1.38462,
          fontSize: theme.typography.body2.fontSize,
          borderRadius: "var(--mui-shape-customBorderRadius-sm)",
        }),
        sizeLarge: {
          fontSize: "1.0625rem",
          lineHeight: 1.529412,
          borderRadius: "var(--mui-shape-customBorderRadius-lg)",
        },
        startIcon: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState.size === "small" ? { marginInlineEnd: theme.spacing(1.5) } :
            ownerState.size === "medium" ? { marginInlineEnd: theme.spacing(2) } :
            { marginInlineEnd: theme.spacing(2.5) }),
          ...iconStyles(ownerState.size),
        }),
        endIcon: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState.size === "small" ? { marginInlineStart: theme.spacing(1.5) } :
            ownerState.size === "medium" ? { marginInlineStart: theme.spacing(2) } :
            { marginInlineStart: theme.spacing(2.5) }),
          ...iconStyles(ownerState.size),
        }),
      },
      variants: [
        ...["primary", "secondary", "error", "warning", "info", "success"].map((color) => ({
          props: { variant: "text" as const, color: color as "primary" },
          style: {
            "&:not(.Mui-disabled):hover, &:not(.Mui-disabled):active, &.Mui-focusVisible:not(:has(span.MuiTouchRipple-root))": {
              backgroundColor: `var(--mui-palette-${color}-lighterOpacity)`,
            },
            "&.Mui-disabled": { opacity: 0.45, color: `var(--mui-palette-${color}-main)` },
          },
        })),
        ...["primary", "secondary", "error", "warning", "info", "success"].map((color) => ({
          props: { variant: "outlined" as const, color: color as "primary" },
          style: {
            borderColor: `var(--mui-palette-${color}-main)`,
            "&:not(.Mui-disabled):hover, &:not(.Mui-disabled):active, &.Mui-focusVisible:not(:has(span.MuiTouchRipple-root))": {
              backgroundColor: `var(--mui-palette-${color}-lighterOpacity)`,
            },
            "&.Mui-disabled": {
              opacity: 0.45,
              color: `var(--mui-palette-${color}-main)`,
              borderColor: `var(--mui-palette-${color}-main)`,
            },
          },
        })),
        ...["primary", "secondary", "error", "warning", "info", "success"].map((color) => ({
          props: { variant: "contained" as const, color: color as "primary" },
          style: {
            "&:not(.Mui-disabled):active, &.Mui-focusVisible:not(:has(span.MuiTouchRipple-root))": {
              backgroundColor: `var(--mui-palette-${color}-dark)`,
            },
            "&.Mui-disabled": {
              opacity: 0.45,
              color: `var(--mui-palette-${color}-contrastText)`,
              backgroundColor: `var(--mui-palette-${color}-main)`,
            },
          },
        })),
      ],
    },

    // ─── ButtonGroup ───
    MuiButtonGroup: {
      defaultProps: { disableRipple },
      styleOverrides: {
        contained: ({ ownerState }: { ownerState: OwnerState }) => ({
          boxShadow: "var(--mui-customShadows-xs)",
          ...(ownerState.disabled && { boxShadow: "none" }),
        }),
      },
      variants: ["primary", "secondary", "error", "warning", "info", "success"].map((color) => ({
        props: { variant: "text" as const, color: color as "primary" },
        style: {
          "& .MuiButtonGroup-firstButton, & .MuiButtonGroup-middleButton": {
            borderColor: `var(--mui-palette-${color}-main)`,
          },
        },
      })),
    },

    // ─── Card (Sneat style) ───
    MuiCard: {
      styleOverrides: {
        root: ({ ownerState }: { ownerState: OwnerState }) => ({
          borderRadius: "0.375rem",
          ...(ownerState.variant !== "outlined" && {
            boxShadow: "0 2px 6px rgba(67, 89, 113, 0.12)",
          }),
        }),
      },
    },
    MuiCardHeader: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(5),
          "& + .MuiCardContent-root, & + .MuiCardActions-root": { paddingBlockStart: 0 },
          "& + .MuiCollapse-root .MuiCardContent-root:first-of-type, & + .MuiCollapse-root .MuiCardActions-root:first-of-type": {
            paddingBlockStart: 0,
          },
        }),
        subheader: ({ theme }: { theme: Theme }) => ({
          ...theme.typography.subtitle1,
          color: "rgb(var(--mui-palette-text-primaryChannel) / 0.55)",
        }),
        action: ({ theme }: { theme: Theme }) => ({
          ...theme.typography.body1,
          color: "var(--mui-palette-text-disabled)",
          marginBlock: 0,
          marginInlineEnd: 0,
          "& .MuiIconButton-root": { color: "inherit" },
        }),
      },
    },
    MuiCardContent: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(5),
          color: "var(--mui-palette-text-secondary)",
          "&:last-child": { paddingBlockEnd: theme.spacing(5) },
          "& + .MuiCardHeader-root, & + .MuiCardContent-root, & + .MuiCardActions-root": {
            paddingBlockStart: 0,
          },
        }),
      },
    },
    MuiCardActions: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(5),
        }),
      },
    },

    // ─── Checkbox ───
    MuiCheckbox: {
      defaultProps: {
        icon: <CheckboxIcon />,
        checkedIcon: <CheckboxCheckedIcon />,
        indeterminateIcon: <CheckboxIndeterminateIcon />,
      },
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState.size === "small"
            ? { padding: theme.spacing(1), "& svg": { fontSize: "1.25rem" } }
            : { padding: theme.spacing(1.5), "& svg": { fontSize: "1.5rem" } }),
          "&.Mui-checked:not(.Mui-disabled) svg": {
            filter: "drop-shadow(var(--mui-customShadows-xs))",
          },
          "&.Mui-disabled": {
            opacity: 0.45,
            "&:not(.Mui-checked)": { color: "var(--mui-palette-text-secondary)" },
            "&.Mui-checked.MuiCheckbox-colorPrimary": { color: "var(--mui-palette-primary-main)" },
            "&.Mui-checked.MuiCheckbox-colorSecondary": { color: "var(--mui-palette-secondary-main)" },
            "&.Mui-checked.MuiCheckbox-colorError": { color: "var(--mui-palette-error-main)" },
            "&.Mui-checked.MuiCheckbox-colorWarning": { color: "var(--mui-palette-warning-main)" },
            "&.Mui-checked.MuiCheckbox-colorInfo": { color: "var(--mui-palette-info-main)" },
            "&.Mui-checked.MuiCheckbox-colorSuccess": { color: "var(--mui-palette-success-main)" },
          },
        }),
      },
    },

    // ─── Chip ───
    MuiChip: {
      styleOverrides: {
        root: ({ ownerState, theme }: { ownerState: OwnerState; theme: Theme }) => ({
          ...theme.typography.body2,
          fontWeight: theme.typography.fontWeightMedium,
          "& .MuiChip-deleteIcon": {
            ...(ownerState.size === "small"
              ? { fontSize: "1rem", marginInlineEnd: theme.spacing(1), marginInlineStart: theme.spacing(-2) }
              : { fontSize: "1.25rem", marginInlineEnd: theme.spacing(2), marginInlineStart: theme.spacing(-3) }),
          },
          "& .MuiChip-avatar, & .MuiChip-icon": {
            "& i, & svg": { ...(ownerState.size === "small" ? { fontSize: 13 } : { fontSize: 15 }) },
            ...(ownerState.size === "small"
              ? { height: 16, width: 16, marginInlineStart: theme.spacing(1), marginInlineEnd: theme.spacing(-2) }
              : { height: 20, width: 20, marginInlineStart: theme.spacing(2), marginInlineEnd: theme.spacing(-3) }),
          },
        }),
        label: ({ ownerState, theme }: { ownerState: OwnerState; theme: Theme }) => ({
          ...(ownerState.size === "small" ? { paddingInline: theme.spacing(3) } : { paddingInline: theme.spacing(4) }),
        }),
        iconMedium: { fontSize: "1.25rem" },
        iconSmall: { fontSize: "1rem" },
      },
    },

    // ─── Dialog ───
    MuiDialog: {
      styleOverrides: {
        paper: ({ theme }: { theme: Theme }) => ({
          boxShadow: "var(--mui-customShadows-xl)",
          [theme.breakpoints.down("sm")]: {
            "&:not(.MuiDialog-paperFullScreen)": { margin: theme.spacing(6) },
          },
        }),
      },
    },
    MuiDialogTitle: {
      defaultProps: { variant: "h5" as const },
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(5),
          "& + .MuiDialogActions-root": { paddingTop: 0 },
        }),
      },
    },
    MuiDialogContent: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(5),
          "& + .MuiDialogContent-root, & + .MuiDialogActions-root": { paddingTop: 0 },
        }),
      },
    },
    MuiDialogActions: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(5),
          "& .MuiButtonBase-root:not(:first-of-type)": { marginInlineStart: theme.spacing(4) },
        }),
      },
    },

    // ─── Drawer ───
    MuiDrawer: {
      styleOverrides: {
        paper: { boxShadow: "var(--mui-customShadows-lg)" },
      },
    },

    // ─── FAB ───
    MuiFab: {
      variants: ["default", "primary", "secondary", "error", "warning", "info", "success"].map(
        (color) => ({
          props: { color: color as "primary" },
          style: {
            ...(color === "default"
              ? {
                  color: "rgb(var(--mui-mainColorChannels-light) / 0.9)",
                  "&.Mui-focusVisible:not(:has(span.MuiTouchRipple-root))": {
                    backgroundColor: "var(--mui-palette-grey-A100)",
                  },
                }
              : {
                  "&.Mui-focusVisible:not(:has(span.MuiTouchRipple-root))": {
                    backgroundColor: `var(--mui-palette-${color}-dark)`,
                  },
                }),
          },
        })
      ),
    },

    // ─── FormControlLabel ───
    MuiFormControlLabel: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({ marginInlineStart: theme.spacing(-2) }),
        label: {
          "&, &.Mui-disabled": { color: "var(--mui-palette-text-primary)" },
          "&.Mui-disabled": { opacity: 0.45 },
        },
      },
    },

    // ─── IconButton ───
    MuiIconButton: {
      styleOverrides: {
        root: { "& .MuiSvgIcon-root, & i, & svg": { fontSize: "inherit" } },
        sizeSmall: ({ theme }: { theme: Theme }) => ({ padding: theme.spacing(1.75), fontSize: "1.25rem" }),
        sizeMedium: ({ theme }: { theme: Theme }) => ({ padding: theme.spacing(2), fontSize: "1.375rem" }),
        sizeLarge: ({ theme }: { theme: Theme }) => ({ padding: theme.spacing(2.25), fontSize: "1.5rem" }),
      },
      variants: ["default", "primary", "secondary", "error", "warning", "info", "success"].map(
        (color) => ({
          props: { color: color as "default" },
          style: {
            "&:not(.Mui-disabled):hover, &:not(.Mui-disabled):active": {
              backgroundColor:
                color === "default"
                  ? "rgb(var(--mui-palette-text-primaryChannel) / 0.08)"
                  : `var(--mui-palette-${color}-lighterOpacity)`,
            },
            "&.Mui-disabled": {
              opacity: 0.45,
              color: color === "default" ? "var(--mui-palette-action-active)" : `var(--mui-palette-${color}-main)`,
            },
          },
        })
      ),
    },

    // ─── Input ───
    MuiFormControl: {
      styleOverrides: {
        root: {
          "&:has(.MuiRadio-root) .MuiFormHelperText-root, &:has(.MuiCheckbox-root) .MuiFormHelperText-root, &:has(.MuiSwitch-root) .MuiFormHelperText-root": {
            marginInline: 0,
          },
        },
      },
    },
    MuiInputBase: {
      styleOverrides: {
        root: {
          lineHeight: 1.6,
          "&.MuiInput-underline": {
            "&:before": { borderColor: "var(--mui-palette-customColors-inputBorder)" },
            "&:not(.Mui-disabled, .Mui-error):hover:before": {
              borderColor: "var(--mui-palette-action-active)",
            },
          },
          "&.Mui-disabled .MuiInputAdornment-root, &.Mui-disabled .MuiInputAdornment-root > *": {
            color: "var(--mui-palette-action-disabled)",
          },
        },
      },
    },
    MuiFilledInput: {
      styleOverrides: {
        root: {
          "&:before": { borderBottom: "1px solid var(--mui-palette-text-secondary)" },
          "&.Mui-disabled:before": { borderBottomStyle: "solid" },
        },
      },
    },
    MuiInputLabel: {
      styleOverrides: {
        shrink: ({ ownerState }: { ownerState: OwnerState }) => ({
          ...(ownerState.variant === "outlined" && {
            color: "var(--mui-palette-text-secondary)",
            transform: "translate(14px, -8px) scale(0.867)",
          }),
          ...(ownerState.variant === "filled" && { transform: "translate(12px, 7px) scale(0.867)" }),
          ...(ownerState.variant === "standard" && { transform: "translate(0, -1.5px) scale(0.867)" }),
        }),
      },
    },
    MuiOutlinedInput: {
      styleOverrides: {
        root: {
          "&:not(.Mui-focused):not(.Mui-error):not(.Mui-disabled):hover .MuiOutlinedInput-notchedOutline": {
            borderColor: "var(--mui-palette-action-active)",
          },
          "&.Mui-disabled .MuiOutlinedInput-notchedOutline": {
            borderColor: "var(--mui-palette-action-disabledBackground)",
          },
        },
        input: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState?.size === "medium" && {
            "&:not(.MuiInputBase-inputMultiline, .MuiInputBase-inputAdornedStart)": {
              paddingBlock: theme.spacing(4),
            },
            height: "1.5em",
          }),
          "& ~ .MuiOutlinedInput-notchedOutline": {
            borderColor: "var(--mui-palette-customColors-inputBorder)",
          },
        }),
        notchedOutline: { "& legend": { fontSize: "0.867em" } },
      },
    },
    MuiInputAdornment: {
      styleOverrides: {
        root: {
          color: "var(--mui-palette-text-primary)",
          "& i, & svg": { fontSize: "1.25rem" },
          "& *": { color: "inherit !important" },
        },
      },
    },
    MuiFormHelperText: {
      styleOverrides: {
        root: { lineHeight: 1, letterSpacing: "unset" },
      },
    },

    // ─── List ───
    MuiListItem: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({ gap: theme.spacing(4) }),
        padding: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(!ownerState.dense && {
            paddingBlock: theme.spacing(2),
            paddingInlineStart: theme.spacing(5),
          }),
        }),
      },
    },
    MuiListItemAvatar: {
      styleOverrides: { root: { minWidth: "unset" } },
    },
    MuiListItemIcon: {
      styleOverrides: {
        root: {
          minWidth: 0,
          color: "var(--mui-palette-text-primary)",
          fontSize: "1.375rem",
          "& > svg, & > i": { fontSize: "inherit" },
        },
      },
    },
    MuiListItemButton: {
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          gap: theme.spacing(4),
          ...(!ownerState.dense && { paddingBlock: theme.spacing(2) }),
          paddingInlineStart: theme.spacing(5),
          "&.Mui-selected": {
            backgroundColor: "var(--mui-palette-primary-lightOpacity)",
            "&:hover, &.Mui-focused, &.Mui-focusVisible": {
              backgroundColor: "var(--mui-palette-primary-mainOpacity)",
            },
            "& .MuiTypography-root": { color: "var(--mui-palette-primary-main)" },
          },
        }),
      },
    },
    MuiListItemText: {
      styleOverrides: {
        root: { margin: 0 },
        primary: { color: "var(--mui-palette-text-primary)" },
      },
    },
    MuiListSubheader: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          ...theme.typography.subtitle2,
          paddingBlock: 10,
          paddingInline: theme.spacing(5),
        }),
      },
    },

    // ─── Menu ───
    MuiMenu: {
      styleOverrides: {
        paper: ({ theme }: { theme: Theme }) => ({
          marginBlockStart: theme.spacing(0.5),
          boxShadow: "var(--mui-customShadows-lg)",
        }),
      },
    },
    MuiMenuItem: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          paddingBlock: theme.spacing(2),
          color: "var(--mui-palette-text-primary)",
          "& i, & svg": { fontSize: "1.375rem" },
          "& .MuiListItemIcon-root": { minInlineSize: 0 },
          "&.Mui-selected": {
            backgroundColor: "var(--mui-palette-primary-lightOpacity)",
            color: "var(--mui-palette-primary-main)",
            "& .MuiListItemIcon-root": { color: "var(--mui-palette-primary-main)" },
            "&:hover, &.Mui-focused, &.Mui-focusVisible": {
              backgroundColor: "var(--mui-palette-primary-mainOpacity)",
            },
          },
          "&.Mui-disabled": { color: "var(--mui-palette-text-disabled)", opacity: 1 },
        }),
      },
    },

    // ─── Pagination ───
    MuiPagination: {
      styleOverrides: { ul: { rowGap: 6 } },
    },
    MuiPaginationItem: {
      styleOverrides: {
        root: ({ ownerState }: { ownerState: OwnerState }) => ({
          ...(ownerState.size === "medium" && { height: "2.375rem", minWidth: "2.375rem" }),
          ...(ownerState.shape !== "rounded" && { borderRadius: "50px" }),
          "&.Mui-selected.Mui-disabled": {
            color: "var(--mui-palette-text-primary)",
            opacity: 0.45,
          },
          "&.Mui-disabled": { opacity: 0.45 },
        }),
        sizeSmall: { height: "2.125rem", minWidth: "2.125rem" },
        sizeLarge: { height: "2.625rem", minWidth: "2.625rem" },
      },
    },

    // ─── Paper ───
    MuiPaper: {
      styleOverrides: { root: { backgroundImage: "none" } },
    },

    // ─── Popover ───
    MuiPopover: {
      styleOverrides: {
        paper: { boxShadow: "var(--mui-customShadows-sm)" },
      },
    },

    // ─── Progress ───
    MuiLinearProgress: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          height: 6,
          borderRadius: theme.shape.borderRadius,
          "& .MuiLinearProgress-bar": { borderRadius: theme.shape.borderRadius },
        }),
      },
    },

    // ─── Radio ───
    MuiRadio: {
      defaultProps: {
        icon: <RadioUncheckedIcon />,
        checkedIcon: <RadioCheckedIcon />,
      },
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState.size === "small"
            ? { padding: theme.spacing(1), "& svg": { fontSize: "1.25rem" } }
            : { padding: theme.spacing(1.5), "& svg": { fontSize: "1.5rem" } }),
          "&.Mui-checked:not(.Mui-disabled) svg": {
            filter: "drop-shadow(var(--mui-customShadows-xs))",
          },
          "&.Mui-disabled": {
            opacity: 0.45,
            "&:not(.Mui-checked)": { color: "var(--mui-palette-text-secondary)" },
            "&.Mui-checked.MuiRadio-colorPrimary": { color: "var(--mui-palette-primary-main)" },
            "&.Mui-checked.MuiRadio-colorSecondary": { color: "var(--mui-palette-secondary-main)" },
            "&.Mui-checked.MuiRadio-colorError": { color: "var(--mui-palette-error-main)" },
            "&.Mui-checked.MuiRadio-colorWarning": { color: "var(--mui-palette-warning-main)" },
            "&.Mui-checked.MuiRadio-colorInfo": { color: "var(--mui-palette-info-main)" },
            "&.Mui-checked.MuiRadio-colorSuccess": { color: "var(--mui-palette-success-main)" },
          },
        }),
      },
    },

    // ─── Rating ───
    MuiRating: {
      styleOverrides: {
        root: {
          gap: "2px",
          color: "var(--mui-palette-warning-main)",
          "& i, & svg": { flexShrink: 0 },
        },
        sizeSmall: { "& .MuiRating-icon i, & .MuiRating-icon svg": { fontSize: "1.25rem" } },
        sizeLarge: { "& .MuiRating-icon i, & .MuiRating-icon svg": { fontSize: "1.75rem" } },
      },
    },

    // ─── Select ───
    MuiSelect: {
      styleOverrides: {
        select: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          ...(ownerState.variant === "outlined" && { minHeight: "1.5em" }),
          '&[aria-expanded="true"] ~ i, &[aria-expanded="true"] ~ svg': {
            transform: "rotate(180deg)",
          },
          "& ~ i, & ~ svg": {
            userSelect: "none",
            display: "inline-block",
            fill: "currentColor",
            flexShrink: 0,
            transition: theme.transitions.create("fill", {
              duration: theme.transitions.duration.shorter,
            }),
            fontSize: "1.25rem",
            position: "absolute" as const,
            right: "1rem",
            top: "calc(50% - 0.5em)",
            pointerEvents: "none" as const,
          },
        }),
      },
    },

    // ─── Slider ───
    MuiSlider: {
      styleOverrides: {
        root: ({ ownerState }: { ownerState: OwnerState }) => ({
          boxSizing: "border-box",
          ...(ownerState.orientation === "horizontal"
            ? ownerState.size !== "small" ? { height: 6 } : { height: 4 }
            : ownerState.size !== "small" ? { width: 6 } : { width: 4 }),
          "&.Mui-disabled": {
            opacity: 0.45,
            color: `var(--mui-palette-${ownerState.color}-main)`,
          },
        }),
        thumb: ({ ownerState }: { ownerState: OwnerState }) => ({
          ...(ownerState.size === "small"
            ? {
                height: 14,
                width: 14,
                border: "2px solid currentColor",
                "&:hover, &.Mui-focusVisible": {
                  boxShadow: `0 0 0 7px var(--mui-palette-${ownerState.color}-lightOpacity)`,
                },
                "&.Mui-active.Mui-focusVisible": {
                  boxShadow: `0 0 0 10px var(--mui-palette-${ownerState.color}-lightOpacity)`,
                },
              }
            : { height: 22, width: 22, border: "4px solid currentColor" }),
          backgroundColor: "var(--mui-palette-common-white)",
          ...(!ownerState.disabled && { boxShadow: "var(--mui-customShadows-sm)" }),
          "&:before": { boxShadow: "none" },
          "&:hover, &.Mui-focusVisible": {
            boxShadow: `0 0 0 8px var(--mui-palette-${ownerState.color}-lightOpacity)`,
          },
          "&.Mui-active.Mui-focusVisible": {
            boxShadow: `0 0 0 13px var(--mui-palette-${ownerState.color}-lightOpacity)`,
          },
        }),
        rail: ({ ownerState }: { ownerState: OwnerState }) => ({
          opacity: 1,
          color: `var(--mui-palette-${ownerState.color}-lightOpacity)`,
        }),
      },
    },

    // ─── Snackbar ───
    MuiSnackbarContent: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          padding: theme.spacing(0, 4),
          boxShadow: "var(--mui-customShadows-xs)",
          "& .MuiSnackbarContent-message": { paddingBlock: theme.spacing(3) },
        }),
      },
    },

    // ─── Switch ───
    MuiSwitch: {
      defaultProps: { disableRipple: true },
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          "&:has(.Mui-disabled)": { opacity: 0.45 },
          ...(ownerState.size !== "small"
            ? { width: 46, height: 36, padding: theme.spacing(2.25, 2) }
            : {
                width: 42,
                height: 30,
                padding: theme.spacing(1.75, 2),
                "& .MuiSwitch-thumb": { width: 12, height: 12 },
                "& .MuiSwitch-switchBase": {
                  padding: 7,
                  left: 3,
                  "&.Mui-checked": { left: -3 },
                },
              }),
        }),
        switchBase: {
          top: 2,
          left: 1,
          "&.Mui-checked": {
            left: -7,
            color: "var(--mui-palette-common-white)",
            "& + .MuiSwitch-track": { opacity: 1 },
          },
          "&.Mui-disabled + .MuiSwitch-track": { opacity: 1 },
        },
        thumb: { width: 14, height: 14, boxShadow: "var(--mui-customShadows-xs)" },
        track: {
          opacity: 1,
          borderRadius: 10,
          backgroundColor: "var(--mui-palette-action-focus)",
          boxShadow: "0 0 4px rgb(0 0 0 / 0.16) inset",
        },
      },
    },

    // ─── TablePagination ───
    MuiTablePagination: {
      styleOverrides: {
        toolbar: ({ theme }: { theme: Theme }) => ({
          paddingInlineEnd: `${theme.spacing(3)} !important`,
        }),
        select: ({ theme }: { theme: Theme }) => ({
          ...theme.typography.body1,
          paddingInlineStart: 0,
          "& ~ i, & ~ svg": {
            fontSize: 20,
            right: "2px !important",
            color: "var(--mui-palette-action-active)",
          },
        }),
        selectLabel: ({ theme }: { theme: Theme }) => ({
          ...theme.typography.body1,
          color: "var(--mui-palette-text-secondary)",
        }),
        input: ({ theme }: { theme: Theme }) => ({
          marginInlineEnd: theme.spacing(6),
        }),
        displayedRows: ({ theme }: { theme: Theme }) => ({ ...theme.typography.body1 }),
        actions: ({ theme }: { theme: Theme }) => ({
          marginInlineStart: theme.spacing(6),
          "& .Mui-disabled": { color: "var(--mui-palette-action-active)" },
          "& .MuiIconButton-root:last-of-type": { marginInlineStart: theme.spacing(2) },
        }),
      },
    },

    // ─── Tabs ───
    MuiTabs: {
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          minBlockSize: 38,
          ...(ownerState.orientation === "horizontal"
            ? { borderBlockEnd: "1px solid var(--mui-palette-divider)" }
            : { borderInlineEnd: "1px solid var(--mui-palette-divider)" }),
          "& .MuiTab-root:hover": {
            ...(ownerState.orientation === "horizontal"
              ? {
                  paddingBlockEnd: theme.spacing(1.5),
                  color: "var(--mui-palette-primary-main)",
                  borderBlockEnd: "2px solid var(--mui-palette-primary-lightOpacity)",
                }
              : {
                  paddingInlineEnd: theme.spacing(5),
                  color: "var(--mui-palette-primary-main)",
                  borderInlineEnd: "2px solid var(--mui-palette-primary-mainOpacity)",
                }),
          },
          "& ~ .MuiTabPanel-root": {
            ...(ownerState.orientation === "horizontal"
              ? { paddingBlockStart: theme.spacing(5) }
              : { paddingInlineStart: theme.spacing(5) }),
          },
        }),
        vertical: {
          minWidth: 131,
          "& .MuiTab-root": { minWidth: 130 },
        },
      },
    },
    MuiTab: {
      styleOverrides: {
        root: ({ theme, ownerState }: { theme: Theme; ownerState: OwnerState }) => ({
          lineHeight: 1.4667,
          padding: theme.spacing(2, 5.5),
          minBlockSize: 38,
          color: "var(--mui-palette-text-primary)",
          "& > .MuiTab-iconWrapper": {
            fontSize: "1.125rem",
            ...(ownerState.iconPosition === "start" && { marginInlineEnd: theme.spacing(1.5) }),
            ...(ownerState.iconPosition === "end" && { marginInlineStart: theme.spacing(1.5) }),
          },
        }),
      },
    },
    MuiTabPanel: {
      styleOverrides: { root: { padding: 0 } },
    },

    // ─── Timeline ───
    MuiTimeline: {
      styleOverrides: { root: { padding: 0 } },
    },
    MuiTimelineDot: {
      styleOverrides: {
        root: ({ theme }: { theme: Theme }) => ({
          margin: theme.spacing(3, 0),
          boxShadow: "none",
          "&:has(> i), &:has(> svg)": { padding: 6 },
          "& > svg, & > i": { fontSize: "1.25rem" },
          "&:has(svg)": { width: 32, height: 32, alignItems: "center", justifyContent: "center" },
        }),
      },
    },
    MuiTimelineConnector: {
      styleOverrides: {
        root: { width: 1, backgroundColor: "var(--mui-palette-divider)" },
      },
    },
    MuiTimelineContent: {
      styleOverrides: { root: { paddingBottom: "1rem" } },
    },

    // ─── ToggleButton ───
    MuiToggleButtonGroup: {
      styleOverrides: {
        root: ({ ownerState }: { ownerState: OwnerState }) => ({
          ...(ownerState.size === "small" && { borderRadius: "var(--mui-shape-customBorderRadius-sm)" }),
          ...(ownerState.size === "large" && { borderRadius: "var(--mui-shape-customBorderRadius-lg)" }),
        }),
      },
    },
    MuiToggleButton: {
      styleOverrides: {
        root: {
          "&:not(.Mui-selected):not(.Mui-disabled)": {
            color: "var(--mui-palette-text-secondary)",
          },
        },
        sizeSmall: { borderRadius: "var(--mui-shape-customBorderRadius-sm)" },
        sizeLarge: { borderRadius: "var(--mui-shape-customBorderRadius-lg)" },
      },
    },

    // ─── Tooltip ───
    MuiTooltip: {
      styleOverrides: {
        popper: {
          '&[data-popper-placement*="bottom"] .MuiTooltip-tooltip': { marginTop: "6px !important" },
          '&[data-popper-placement*="top"] .MuiTooltip-tooltip': { marginBottom: "6px !important" },
          '&[data-popper-placement*="left"] .MuiTooltip-tooltip': { marginRight: "6px !important" },
          '&[data-popper-placement*="right"] .MuiTooltip-tooltip': { marginLeft: "6px !important" },
        },
        tooltip: ({ theme }: { theme: Theme }) => ({
          borderRadius: "var(--mui-shape-customBorderRadius-sm)",
          fontSize: theme.typography.subtitle2.fontSize,
          lineHeight: 1.539,
          color: "var(--mui-palette-customColors-tooltipText)",
          paddingInline: theme.spacing(3),
        }),
      },
    },

    // ─── Typography ───
    MuiTypography: {
      styleOverrides: {
        gutterBottom: ({ theme }: { theme: Theme }) => ({
          marginBottom: theme.spacing(2),
        }),
      },
      variants: [
        { props: { variant: "h1" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "h2" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "h3" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "h4" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "h5" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "h6" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "body1" }, style: { color: "var(--mui-palette-text-secondary)" } },
        { props: { variant: "body2" }, style: { color: "var(--mui-palette-text-secondary)" } },
        { props: { variant: "button" }, style: { color: "var(--mui-palette-text-primary)" } },
        { props: { variant: "caption" }, style: { color: "var(--mui-palette-text-disabled)", display: "inline-block" } },
        { props: { variant: "overline" }, style: { color: "var(--mui-palette-text-primary)", display: "inline-block" } },
      ],
    },
  };
}
