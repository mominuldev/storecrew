/**
 * The shapes the widget receives from `storecrew/v1`.
 *
 * Kept as a hand-written mirror of the controller rather than generated: the
 * widget consumes four endpoints, and a code generator is a build dependency
 * that would outweigh the file it produces.
 */

export interface BootData {
  root: string;
  auto: boolean;
}

export interface Appearance {
  position: 'left' | 'right';
  accent: string;
  title: string;
  launcher: string;
  greeting: string;
  placeholder: string;
  offline: string;
}

export interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
  agentId?: string;
  at?: string;
}

export interface BootPayload {
  enabled: boolean;
  ready: boolean;
  nonce: string;
  maxChars: number;
  appearance: Appearance;
  conversation: { uuid: string; messages: ChatMessage[] } | null;
}

export interface SessionPayload {
  uuid: string;
  token: string;
  messages: ChatMessage[];
}

export interface ReplyPayload {
  uuid: string;
  reply: { role: 'assistant'; content: string; agentId: string };
  outcome: string;
  escalated: boolean;
}

export interface ApiError {
  code: string;
  message: string;
  retryAfter?: number;
}
