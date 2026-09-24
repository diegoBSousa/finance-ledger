import type { ImportStatus, RowStatus } from "./api/types";

export function formatMoney(minor: string): string {
  const value = BigInt(minor);
  const absolute = value < 0n ? -value : value;
  return `${value < 0n ? "−" : ""}R$\u00a0${(absolute / 100n).toLocaleString("pt-BR")},${(absolute % 100n).toString().padStart(2, "0")}`;
}
export const formatCount = (value: string | number) =>
  BigInt(value).toLocaleString("pt-BR");
export const formatDate = (iso: string) =>
  iso.slice(0, 10).split("-").reverse().join("/");
export const formatDateTime = (iso: string) =>
  new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(iso));
export const importLabel: Record<ImportStatus, string> = {
  pending: "Na fila",
  processing: "Processando",
  completed: "Concluída",
  completed_with_errors: "Concluída com rejeições",
  failed: "Interrompida",
};
export const rowLabel: Record<RowStatus, string> = {
  inserted: "Importado",
  duplicate: "Duplicado",
  rejected: "Rejeitado",
};
export const isActive = (status: ImportStatus) =>
  status === "pending" || status === "processing";
export const MAX_FILE_BYTES = 100_000_000;
export function validateFile(file: File | null): string {
  if (!file) return "Selecione um arquivo CSV.";
  if (!/\.csv$/i.test(file.name))
    return "Selecione um arquivo com extensão .csv.";
  if (file.size === 0) return "O arquivo está vazio.";
  if (file.size > MAX_FILE_BYTES)
    return "O arquivo deve ter no máximo 100 MB (100.000.000 bytes).";
  return "";
}
