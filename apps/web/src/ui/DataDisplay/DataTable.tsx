"use client";

import {
  Box,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TableSortLabel,
  Typography,
  useMediaQuery,
  useTheme,
} from "@mui/material";
import { ReactNode, useMemo, useState } from "react";

type SortDirection = "asc" | "desc";

export type DataTableColumn<T> = {
  key: keyof T & string;
  label: string;
  render?: (value: T[keyof T], row: T) => ReactNode;
};

export function DataTable<T extends Record<string, unknown>>({
  rows,
  columns,
  rowKey,
}: {
  rows: T[];
  columns: DataTableColumn<T>[];
  rowKey: (row: T) => string;
}) {
  const theme = useTheme();
  const isSmall = useMediaQuery(theme.breakpoints.down("sm"));
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [sortBy, setSortBy] = useState(columns[0]?.key ?? "");
  const [sortDirection, setSortDirection] = useState<SortDirection>("asc");

  const sortedRows = useMemo(() => {
    const copy = [...rows];
    if (!sortBy) {
      return copy;
    }
    copy.sort((a, b) => {
      const left = String(a[sortBy] ?? "");
      const right = String(b[sortBy] ?? "");
      return sortDirection === "asc" ? left.localeCompare(right) : right.localeCompare(left);
    });
    return copy;
  }, [rows, sortBy, sortDirection]);

  const paginatedRows = sortedRows.slice(page * rowsPerPage, page * rowsPerPage + rowsPerPage);

  const handleSort = (column: string) => {
    if (sortBy === column) {
      setSortDirection((prev) => (prev === "asc" ? "desc" : "asc"));
      return;
    }
    setSortBy(column);
    setSortDirection("asc");
  };

  if (isSmall) {
    return (
      <Box sx={{ display: "grid", gap: 2 }}>
        {paginatedRows.map((row) => (
          <Paper key={rowKey(row)} variant="outlined" sx={{ p: 2 }}>
            {columns.map((column) => (
              <Box key={column.key} sx={{ display: "flex", justifyContent: "space-between", py: 0.5 }}>
                <Typography variant="caption" color="text.secondary">
                  {column.label}
                </Typography>
                <Typography variant="body2">
                  {column.render ? column.render(row[column.key], row) : String(row[column.key] ?? "-")}
                </Typography>
              </Box>
            ))}
          </Paper>
        ))}
        <TablePagination
          component="div"
          count={rows.length}
          page={page}
          rowsPerPage={rowsPerPage}
          onPageChange={(_, nextPage) => setPage(nextPage)}
          onRowsPerPageChange={(event) => {
            setRowsPerPage(Number(event.target.value));
            setPage(0);
          }}
        />
      </Box>
    );
  }

  return (
    <Box>
      <TableContainer component={Paper} variant="outlined">
        <Table size="medium">
          <TableHead>
            <TableRow>
              {columns.map((column) => (
                <TableCell key={column.key}>
                  <TableSortLabel
                    active={sortBy === column.key}
                    direction={sortBy === column.key ? sortDirection : "asc"}
                    onClick={() => handleSort(column.key)}
                  >
                    {column.label}
                  </TableSortLabel>
                </TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {paginatedRows.map((row) => (
              <TableRow key={rowKey(row)} hover>
                {columns.map((column) => (
                  <TableCell key={column.key}>
                    {column.render ? column.render(row[column.key], row) : String(row[column.key] ?? "-")}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        component="div"
        count={rows.length}
        page={page}
        rowsPerPage={rowsPerPage}
        onPageChange={(_, nextPage) => setPage(nextPage)}
        onRowsPerPageChange={(event) => {
          setRowsPerPage(Number(event.target.value));
          setPage(0);
        }}
      />
    </Box>
  );
}
