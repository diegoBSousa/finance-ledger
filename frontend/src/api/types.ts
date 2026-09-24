// IDs, money, versions and import counters deliberately stay decimal strings.
export interface User {
  id: string;
  name: string;
  email: string;
}
export interface Login {
  access_token: string;
  token_type: "Bearer";
  expires_in: number;
  user: User;
}
export interface Envelope<T> {
  data: T;
}
export interface PageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  has_next_page: boolean;
}
export interface Page<T> {
  data: T[];
  meta: PageMeta;
}
export interface Dashboard {
  currency: "BRL";
  income_minor: string;
  expense_minor: string;
  balance_minor: string;
  transaction_count: string;
  financial_revision: string;
}
export interface Balance {
  account_id: string;
  account_number: string;
  active: boolean;
  currency: "BRL";
  debit_total_minor: string;
  credit_total_minor: string;
  balance_minor: string;
  ledger_version: string;
  calculated_version: string;
  calculated_at: string | null;
}
export interface Transaction {
  id: string;
  account_number: string;
  transaction_date: string;
  description: string;
  type: "income" | "expense";
  amount_minor: string;
  currency: "BRL";
  created_at: string;
}
export type ImportStatus =
  | "pending"
  | "processing"
  | "completed"
  | "completed_with_errors"
  | "failed";
export interface CsvImport {
  id: string;
  original_name: string;
  file_size_bytes: string;
  status: ImportStatus;
  processed_rows: string;
  inserted_rows: string;
  duplicate_rows: string;
  rejected_rows: string;
  created_at: string;
  prepared_at: string | null;
  started_at: string | null;
  finished_at: string | null;
  error_code: string | null;
  status_url: string;
}
export type RowStatus = "inserted" | "duplicate" | "rejected";
export interface ImportRow {
  id: string;
  source_record_number: string;
  status: RowStatus;
  source_row_hash: string | null;
  journal_entry_id: string | null;
  error_code: string | null;
  error_message: string | null;
}
export type Query = Record<string, string | number | undefined>;
