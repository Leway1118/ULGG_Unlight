import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

const r = (p: string) => fileURLToPath(new URL(p, import.meta.url));

export default defineConfig({
  resolve: {
    // 對齊 tsconfig 的 paths，測試不用先 build
    alias: {
      "@ulr/rule-schema": r("./packages/rule-schema/src/index.ts"),
      "@ulr/api-contract": r("./packages/api-contract/src/index.ts"),
      "@ulr/cost-engine": r("./packages/cost-engine/src/index.ts"),
      "@ulr/cdp-adapter": r("./packages/cdp-adapter/src/index.ts"),
    },
  },
  test: {
    include: ["packages/*/test/**/*.test.ts", "apps/*/test/**/*.test.ts"],
    environment: "node",
  },
});
