/**
 * Widget entry point.
 *
 * Loaded `async` from the footer, so this runs at some unpredictable moment
 * after the page is usable and must assume nothing about what else has
 * happened. Everything is wrapped: a throw here would show in the merchant's
 * console on every page of their store, and FR-CHAT-03 makes a broken widget the
 * widget's own problem rather than the storefront's.
 */

import { ChatApi } from './api';
import styles from './widget.css?inline';
import { ChatWidget } from './widget';
import type { Appearance, BootData } from './types';

const INLINE_SELECTOR = '[data-storecrew-chat="inline"]';

declare global {
  interface Window {
    storecrewChat?: BootData;
  }
}

/**
 * A shadow root carrying the widget's styles.
 *
 * Shadow DOM rather than a prefixed stylesheet. A WooCommerce theme is free to
 * write `div { margin: 0 !important }`, and thousands do; out-specifying every
 * theme on WordPress.org is a fight with no end, and the boundary also stops
 * the widget's own styles leaking onto the merchant's page.
 */
function mount(container: HTMLElement, appearance: Appearance): ShadowRoot {
  const shadow = container.attachShadow({ mode: 'open' });
  const sheet = document.createElement('style');
  sheet.textContent = styles;
  shadow.appendChild(sheet);

  const host = shadow.host as HTMLElement;
  host.setAttribute('data-storecrew', 'chat');
  host.style.setProperty('--scr-accent', appearance.accent);
  host.style.setProperty('--scr-accent-ink', readableInk(appearance.accent));

  return shadow;
}

/**
 * Black or white text over the merchant's accent colour.
 *
 * Computed rather than configured. A merchant picking a pale yellow brand colour
 * would otherwise get white-on-yellow buttons, which fail WCAG contrast and are
 * a support ticket rather than a design choice — and asking them to choose a
 * text colour as well is asking them to solve the contrast equation by eye.
 */
function readableInk(hex: string): string {
  const match = /^#?([a-f\d]{6})$/i.exec(hex.trim());

  if (!match) {
    return '#ffffff';
  }

  const value = parseInt(match[1], 16);

  const channel = (c: number): number => {
    const s = c / 255;

    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };

  const luminance =
    0.2126 * channel((value >> 16) & 255) +
    0.7152 * channel((value >> 8) & 255) +
    0.0722 * channel(value & 255);

  // 0.179 is where contrast against black and against white are equal.
  return luminance > 0.179 ? '#16191f' : '#ffffff';
}

async function start(): Promise<void> {
  const config = window.storecrewChat;

  if (!config || !config.root) {
    return;
  }

  const api = new ChatApi(config.root);
  const boot = await api.boot();

  if (!boot.enabled || !boot.ready) {
    // A store with no provider key configured shows nothing at all, rather than
    // a launcher that apologises when opened.
    return;
  }

  const inline = Array.from(document.querySelectorAll<HTMLElement>(INLINE_SELECTOR));
  const widgets: ChatWidget[] = [];

  inline.forEach((container, index) => {
    widgets.push(
      new ChatWidget(
        mount(container, boot.appearance),
        api,
        boot.appearance,
        boot.maxChars,
        'inline',
        `scr-panel-inline-${index}`,
      ),
    );
  });

  // The floating launcher stands down where the merchant placed a panel
  // themselves. Two panels on one page would share a conversation but not a
  // transcript — each would show only what was typed into it.
  if (config.auto && inline.length === 0) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    widgets.push(
      new ChatWidget(
        mount(host, boot.appearance),
        api,
        boot.appearance,
        boot.maxChars,
        'floating',
        'scr-panel-floating',
      ),
    );
  }

  if (boot.conversation) {
    for (const widget of widgets) {
      widget.restore(boot.conversation.uuid, boot.conversation.messages);
    }
  }
}

function run(): void {
  start().catch(() => {
    /* The storefront is not the place to report our own failures. */
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', run);
} else {
  run();
}
