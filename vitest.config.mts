import { defineConfig } from "vitest/config";

export default defineConfig({
  resolve: {
    alias: {
      "@": import.meta.dirname + "/src",
      // "server-only" throws outside a React Server bundle; it is a no-op guard for tests.
      "server-only": import.meta.dirname + "/tests/server-only-stub.ts",
    },
  },
  test: {
    environment: "node",
    include: ["tests/**/*.test.ts"],
  },
});
