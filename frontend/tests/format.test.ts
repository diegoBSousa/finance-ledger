import { describe, expect, it } from "vitest";
import {
  formatMoney,
  formatCount,
  formatDate,
  validateFile,
  MAX_FILE_BYTES,
} from "../src/format";

describe("exact BRL formatting and CSV selection", () => {
  it.each([
    ["0", "R$\u00a00,00"],
    ["1", "R$\u00a00,01"],
    ["494618", "R$\u00a04.946,18"],
    ["-1", "−R$\u00a00,01"],
    ["-494618", "−R$\u00a04.946,18"],
    ["9223372036854775807", "R$\u00a092.233.720.368.547.758,07"],
    ["-9223372036854775808", "−R$\u00a092.233.720.368.547.758,08"],
  ])("renders %s cents without floating point rounding", (value, expected) => {
    expect(formatMoney(value)).toBe(expected);
  });
  it("preserves large counters and calendar dates", () => {
    expect(formatCount("9007199254740993")).toBe("9.007.199.254.740.993");
    expect(formatDate("2026-08-16")).toBe("16/08/2026");
  });
  it("checks the decimal byte boundary without reading the file", () => {
    const file = new File(["a"], "movimentos.CSV");
    Object.defineProperty(file, "size", {
      configurable: true,
      value: MAX_FILE_BYTES,
    });
    expect(validateFile(file)).toBe("");
    Object.defineProperty(file, "size", { value: MAX_FILE_BYTES + 1 });
    expect(validateFile(file)).toContain("100.000.000");
  });
  it("rejects a missing, empty or non-CSV file", () => {
    expect(validateFile(null)).toContain("Selecione");
    expect(validateFile(new File([], "empty.csv"))).toContain("vazio");
    expect(validateFile(new File(["zip"], "file.zip"))).toContain(".csv");
  });
});
