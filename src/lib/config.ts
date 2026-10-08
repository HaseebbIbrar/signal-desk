import "server-only";
import { MASTER_FILE_NAME } from "./constants";

export type AppEnv = "production" | "development";
export type BackendKind = "graph" | "mock";

export interface ServerConfig {
  appEnv: AppEnv;
  backend: BackendKind;
  graph: {
    tenantId: string;
    clientId: string;
    clientSecret: string;
    driveId: string;
    itemId: string;
    /** Set in development: the target must NOT have the master file's name. */
    forbiddenFileName?: string;
  } | null;
  label: string;
}

function required(name: string): string {
  const v = process.env[name]?.trim();
  if (!v) throw new Error(`Missing required environment variable ${name}`);
  return v;
}

let cached: ServerConfig | null = null;

export function getServerConfig(): ServerConfig {
  if (cached) return cached;
  const appEnv: AppEnv = process.env.APP_ENV === "production" ? "production" : "development";
  const backend: BackendKind = process.env.WORKBOOK_BACKEND === "mock" ? "mock" : "graph";

  if (backend === "mock") {
    if (appEnv === "production") throw new Error("WORKBOOK_BACKEND=mock is not allowed when APP_ENV=production");
    cached = { appEnv, backend, graph: null, label: "MOCK DATA" };
    return cached;
  }

  const masterItemId = required("GRAPH_MASTER_ITEM_ID");
  let itemId = masterItemId;
  if (appEnv === "development") {
    itemId = required("GRAPH_DEV_ITEM_ID");
    if (itemId === masterItemId) {
      throw new Error("GRAPH_DEV_ITEM_ID equals GRAPH_MASTER_ITEM_ID. Development must run against a COPY of the workbook.");
    }
  }

  cached = {
    appEnv,
    backend,
    graph: {
      tenantId: required("AZURE_TENANT_ID"),
      clientId: required("AZURE_CLIENT_ID"),
      clientSecret: required("AZURE_CLIENT_SECRET"),
      driveId: required("GRAPH_DRIVE_ID"),
      itemId,
      forbiddenFileName: appEnv === "development" ? MASTER_FILE_NAME : undefined,
    },
    label: appEnv === "development" ? "DEV COPY" : "",
  };
  return cached;
}
