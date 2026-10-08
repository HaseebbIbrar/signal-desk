import { defineConfig } from "vitest/config";

// Live tests against the DEV COPY workbook. Not part of `npm test`.
export default defineConfig({
  resolve: {
    alias: {
      "@": import.meta.dirname + "/src",
      "server-only": import.meta.dirname + "/tests/server-only-stub.ts",
    },
  },
  test: {
    environment: "node",
    include: ["tests-integration/**/*.int.test.ts"],
    fileParallelism: false,
  },
});
