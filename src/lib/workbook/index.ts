import "server-only";
import { getServerConfig } from "../config";
import { createSignalService, type SignalService } from "../service";
import { buildFixture } from "./fixture";
import { GraphBackend } from "./graph";
import { MemoryBackend } from "./memory";

// One service per server instance (kept on globalThis so dev hot-reload does not reset mock data).
const g = globalThis as unknown as { __signalDeskService?: SignalService };

export function getService(): SignalService {
  if (g.__signalDeskService) return g.__signalDeskService;
  const cfg = getServerConfig();
  const backend =
    cfg.backend === "mock" || !cfg.graph
      ? new MemoryBackend(buildFixture(), cfg.label)
      : new GraphBackend({ ...cfg.graph, label: cfg.label });
  g.__signalDeskService = createSignalService({ backend });
  return g.__signalDeskService;
}
