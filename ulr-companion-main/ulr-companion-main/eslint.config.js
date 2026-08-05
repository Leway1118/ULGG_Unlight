// @ts-check
import eslint from "@eslint/js";
import tseslint from "typescript-eslint";

export default tseslint.config(
  {
    ignores: ["**/dist/**", "**/node_modules/**", "**/*.json"],
  },
  eslint.configs.recommended,
  ...tseslint.configs.recommended,
  {
    rules: {
      "@typescript-eslint/consistent-type-imports": "error",
      "@typescript-eslint/no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_" },
      ],
      // 規格書 §12：規則內容只能驅動白名單化的計算分支，
      // 不得轉譯為 eval / Function / 動態模組載入。
      "no-eval": "error",
      "no-implied-eval": "error",
      "no-new-func": "error",
    },
  },
);
