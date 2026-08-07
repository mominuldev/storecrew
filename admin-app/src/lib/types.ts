export type Bootstrap = {
  version: string;
  apiVersion: string;
  features: Record<string, boolean>;
  catalog: { slug: string; label: string; tier: string; description: string }[];
  routes: { path: string; label: string; feature: string | null; icon: string; order: number; inMenu: boolean; locked: boolean }[];
  onboarding: { hasProvider: boolean; canEmbed: boolean; configuredProviders: string[]; complete: boolean };
  user: { canManage: boolean; canViewStats: boolean; canEditAgents: boolean };
};

export type Health = {
  environment: { php: string; wordpress: string; woocommerce: string | null; hpos: boolean | null };
  queue: { available: boolean; pending: number; oldest: number };
  index: { chunks: number; embedded: number; pending: number; mismatched: number; model: string; dimensions: number; sources: Record<string, number> };
  indexRun: null | { id: number; status: string; total: number; processed: number; failed: number; alive: boolean; startedAt: string };
  spend: { capMicros: number; spentMicros: number; remainingMicros: number; percentUsed: number; blocked: boolean; behaviour: string };
  encryption: { source: string; secure: boolean; advice: string };
};

export type Provider = {
  id: string;
  label: string;
  configured: boolean;
  keyHint: string | null;
  capabilities: Record<string, boolean>;
  chatModels: string[];
  embedModels: string[];
  owner: string | null;
};

export type Settings = {
  modelPolicy: Record<string, { provider: string; model: string }>;
  resolved: Record<string, { provider: string; model: string } | null>;
  spend: Health['spend'];
  pricing: { ratesVerified: string };
  canEmbed: boolean;
  tasks: string[];
  chat: ChatSettings;
};

export type ChatSettings = {
  enabled: boolean;
  autoPlace: boolean;
  position: 'left' | 'right';
  accent: string;
  title: string;
  launcher: string;
  greeting: string;
  placeholder: string;
  offlineNotice: string;
};

export type IndexStatus = {
  health: Health['index'];
  sources: Record<string, number>;
  queue: Health['queue'];
  active: null | { id: number; status: string; total: number; processed: number; failed: number; alive: boolean };
  recentRuns: { id: number; type: string; status: string; total: number; processed: number; failed: number; alive: boolean; startedAt: string; finishedAt: string | null; lastError: string | null }[];
};

export type SearchResult = {
  query: string;
  strategy: string;
  candidates: number;
  truncated: boolean;
  degraded: string;
  results: { id: number; score: number; dense: number; lexical: number; content: string; sourceTitle: string; sourceType: string; sourceUrl: string; objectId: number }[];
};

export type ConversationSummary = {
  uuid: string;
  status: string;
  channel: string;
  messageCount: number;
  runCount: number;
  identityVerified: boolean;
  startedAt: string;
  lastActivityAt: string;
};

export type Conversation = ConversationSummary & {
  verifiedOrderId: number;
  turns: { role: string; agentId: string; content: string; tokensIn: number; tokensOut: number; createdAt: string }[];
  runs: {
    id: number; agentId: string; provider: string; model: string; status: string;
    tokensIn: number; tokensOut: number; costMicros: number; latencyMs: number;
    retrieved: { id: number; score: number }[] | null; errorCode: string;
    toolCalls: { id: number; toolId: string; intent: string; authMode: string; status: string; arguments: unknown; result: unknown; durationMs: number }[];
  }[];
};

export type Approval = {
  id: number;
  toolId: string;
  arguments: Record<string, unknown> | null;
  createdAt: string;
};
