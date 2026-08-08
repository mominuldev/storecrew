/**
 * The chat panel.
 *
 * Plain DOM, no framework. The whole surface is a launcher, a scrolling log and
 * a text box; a rendering library here would cost more than the widget it
 * renders, and the storefront budget (45 KB gzipped, FR-CHAT-01) is the tightest
 * constraint in the product.
 *
 * Accessibility is built in rather than added: the floating panel is a dialog
 * with a focus trap and an Escape route, the log is a live region so an answer
 * is announced when it arrives, every control clears the 24 px target minimum,
 * and motion is dropped entirely under `prefers-reduced-motion` (FR-CHAT-04,
 * WCAG 2.2 AA).
 */

import { ApiFailure, ChatApi } from './api';
import { renderMessage } from './render';
import type { Appearance, ChatMessage, WidgetStrings } from './types';

type Mode = 'floating' | 'inline';

const FOCUSABLE = 'button:not([disabled]), textarea:not([disabled]), a[href]';

export class ChatWidget {
  private readonly panel: HTMLElement;
  private readonly log: HTMLElement;
  private readonly input: HTMLTextAreaElement;
  private readonly send: HTMLButtonElement;
  private readonly counter: HTMLElement;
  private launcher: HTMLButtonElement | null = null;

  private uuid = '';
  private sessionReady = false;
  private srRegion: HTMLElement | null = null;
  private open = false;
  private busy = false;
  private greeted = false;

  constructor(
    private readonly host: ShadowRoot,
    private readonly api: ChatApi,
    private readonly appearance: Appearance,
    private readonly maxChars: number,
    private readonly mode: Mode,
    private readonly id: string,
    private readonly strings: WidgetStrings,
  ) {
    this.panel = this.buildPanel();
    this.log = this.panel.querySelector('.scr-log') as HTMLElement;
    this.input = this.panel.querySelector('.scr-input') as HTMLTextAreaElement;
    this.send = this.panel.querySelector('.scr-send') as HTMLButtonElement;
    this.counter = this.panel.querySelector('.scr-count') as HTMLElement;

    if (this.mode === 'floating') {
      this.launcher = this.buildLauncher();
      this.host.appendChild(this.launcher);
      this.panel.classList.add('scr-hidden');
    } else {
      this.open = true;
      this.greet();
    }

    this.host.appendChild(this.panel);
    this.wire();
  }

  /**
   * Adopt a conversation the boot call already resolved.
   */
  public restore(uuid: string, messages: ChatMessage[]): void {
    this.uuid = uuid;

    if (messages.length === 0) {
      return;
    }

    // The greeting is dropped rather than pushed above the transcript. A
    // customer returning to a conversation they were already having does not
    // need to be introduced to it again.
    this.log.replaceChildren();
    this.greeted = true;

    for (const message of messages) {
      this.append(message.role, message.content);
    }
  }

  public toggle(): void {
    this.open ? this.hide() : this.show();
  }

  public show(): void {
    this.open = true;
    this.panel.classList.remove('scr-hidden');
    this.launcher?.setAttribute('aria-expanded', 'true');
    this.greet();

    // Focus lands in the text box rather than on the panel: the customer opened
    // this to type, and a screen reader reads the dialog's label on entry either
    // way.
    window.setTimeout(() => this.input.focus(), 0);
  }

  public hide(): void {
    this.open = false;
    this.panel.classList.add('scr-hidden');
    this.launcher?.setAttribute('aria-expanded', 'false');
    this.launcher?.focus();
  }

  /**
   * The greeting is a rendered message, not a stored one.
   *
   * Writing it to the conversation would spend a database row and a prompt turn
   * on something the model never said, and would make every abandoned page view
   * look like a conversation in the merchant's inbox.
   */
  private greet(): void {
    if (this.greeted || !this.appearance.greeting) {
      return;
    }

    this.greeted = true;
    this.append('assistant', this.appearance.greeting);
  }

  private buildLauncher(): HTMLButtonElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'scr-launcher';
    button.dataset.position = this.appearance.position;
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', this.id);
    button.textContent = this.appearance.launcher;

    return button;
  }

  private buildPanel(): HTMLElement {
    const panel = document.createElement('div');
    panel.className = 'scr-panel';
    panel.id = this.id;
    panel.dataset.mode = this.mode;
    panel.dataset.position = this.appearance.position;

    if (this.mode === 'floating') {
      // A dialog, but not a modal one: the storefront behind it stays usable,
      // and `aria-modal` would tell a screen reader otherwise.
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', this.appearance.title);
    } else {
      panel.setAttribute('role', 'region');
      panel.setAttribute('aria-label', this.appearance.title);
    }

    const head = document.createElement('div');
    head.className = 'scr-head';

    const title = document.createElement('h2');
    title.className = 'scr-title';
    title.textContent = this.appearance.title;
    head.appendChild(title);

    if (this.mode === 'floating') {
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'scr-close';
      close.setAttribute('aria-label', this.strings.close);
      close.textContent = '×';
      head.appendChild(close);
    }

    const log = document.createElement('div');
    log.className = 'scr-log';
    log.setAttribute('role', 'log');
    log.setAttribute('aria-live', 'polite');
    log.setAttribute('aria-relevant', 'additions text');
    log.setAttribute('aria-label', this.strings.conversation);

    const form = document.createElement('form');
    form.className = 'scr-form';

    const input = document.createElement('textarea');
    input.className = 'scr-input';
    input.rows = 1;
    input.placeholder = this.appearance.placeholder;
    input.setAttribute('aria-label', this.appearance.placeholder);
    input.maxLength = this.maxChars;

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'scr-send';
    submit.setAttribute('aria-label', this.strings.send);
    submit.textContent = '↑';

    form.appendChild(input);
    form.appendChild(submit);

    const counter = document.createElement('div');
    counter.className = 'scr-count scr-hidden';

    panel.appendChild(head);
    panel.appendChild(log);
    panel.appendChild(form);
    panel.appendChild(counter);

    return panel;
  }

  private wire(): void {
    this.launcher?.addEventListener('click', () => this.toggle());

    this.panel.querySelector('.scr-close')?.addEventListener('click', () => this.hide());

    this.panel.querySelector('.scr-form')?.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit();
    });

    this.input.addEventListener('keydown', (event) => {
      // Enter sends; Shift+Enter is a new line. The reverse would make a
      // two-paragraph question impossible to type without an accident.
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void this.submit();
      }
    });

    this.input.addEventListener('input', () => this.onInput());

    if (this.mode === 'floating') {
      this.panel.addEventListener('keydown', (event) => this.trap(event));
    }
  }

  private onInput(): void {
    const length = this.input.value.length;

    this.input.style.height = 'auto';
    this.input.style.height = `${Math.min(120, this.input.scrollHeight)}px`;

    // Shown only as the limit approaches. A counter visible from the first
    // keystroke reads as a restriction on a box nobody was going to fill.
    const near = length > this.maxChars * 0.8;
    this.counter.classList.toggle('scr-hidden', !near);
    this.counter.textContent = near ? `${length} / ${this.maxChars}` : '';
  }

  /**
   * Keep Tab inside the panel while it is open.
   */
  private trap(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.stopPropagation();
      this.hide();

      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    const focusable = Array.from(this.panel.querySelectorAll<HTMLElement>(FOCUSABLE));

    if (focusable.length === 0) {
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = this.host.activeElement;

    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  private async submit(): Promise<void> {
    const message = this.input.value.trim();

    if (!message || this.busy) {
      return;
    }

    this.input.value = '';
    this.onInput();
    this.append('user', message);
    this.setBusy(true);

    const typing = this.typing();

    try {
      // Opened on the first message rather than on page load, so a store with a
      // thousand visitors a day does not get a thousand empty conversations in
      // its inbox.
      //
      // Called even when boot already named a conversation: a signed-in customer
      // is recognised by their account before any session token exists, and this
      // is the call that mints one. Without it their first message would be
      // refused for having no credential.
      if (!this.sessionReady) {
        const session = await this.api.session();
        this.uuid = session.uuid;
        this.sessionReady = true;
      }

      // Streaming (FR-CHAT-02). Deltas paint into a plain-text bubble as they
      // arrive; the finished text is re-rendered through the Markdown path and
      // announced once. Accessibility is deliberate here: the log is a polite
      // live region, and token-by-token churn would make a screen reader
      // stutter through every fragment — so the streaming bubble is excluded
      // from announcement while it grows, and the completed reply is announced
      // whole via the visually-hidden status region (11 § 5).
      let live: HTMLElement | null = null;
      let raw = '';

      const reply = await this.api.sendStream(this.uuid, message, (delta) => {
        if (!live) {
          typing.remove();
          live = this.appendStreaming();
        }

        raw += delta;
        live.textContent = raw;
        this.scroll();
      });

      typing.remove();

      if (live !== null) {
        // Swap accumulated plain text for the rendered reply, in place.
        const bubble = (live as HTMLElement).parentElement as HTMLElement;
        bubble.replaceChildren(renderMessage(reply.reply.content));
        bubble.removeAttribute('aria-hidden');
      } else {
        // No deltas arrived — the buffered path, byte-for-byte the old flow.
        this.append('assistant', reply.reply.content);
      }

      this.announce(reply.reply.content);

      if (reply.escalated) {
        this.note('Someone from the store team will pick this up.');
      }
    } catch (error) {
      typing.remove();
      this.note(this.explain(error));
    } finally {
      this.setBusy(false);
      this.input.focus();
    }
  }

  /**
   * What to tell the customer when a call fails.
   *
   * Deliberately never the raw error. "storecrew_rate_limited" is a fact about
   * our implementation, not an answer to the person waiting.
   */
  private explain(error: unknown): string {
    if (!(error instanceof ApiFailure)) {
      return this.appearance.offline;
    }

    if (error.code === 'storecrew_rate_limited') {
      const seconds = Math.max(1, error.retryAfter);

      return this.strings.rateLimited.replace('%d', String(seconds));
    }

    if (error.code === 'storecrew_conversation_closed') {
      return this.strings.closed;
    }

    if (error.code === 'storecrew_message_too_long') {
      return this.strings.tooLong.replace('%d', String(this.maxChars));
    }

    // The free-tier conversation cap. Only ever refused when *opening* a new
    // conversation — one in progress is never cut off mid-question.
    if (error.code === 'storecrew_at_capacity') {
      return this.strings.atCapacity;
    }

    return this.appearance.offline;
  }

  private setBusy(busy: boolean): void {
    this.busy = busy;
    this.send.disabled = busy;
    this.input.disabled = busy;
  }

  private typing(): HTMLElement {
    const bubble = document.createElement('div');
    bubble.className = 'scr-msg';
    bubble.dataset.role = 'assistant';
    bubble.setAttribute('aria-label', this.strings.working);

    const dots = document.createElement('span');
    dots.className = 'scr-typing';
    dots.appendChild(document.createElement('i'));
    dots.appendChild(document.createElement('i'));
    dots.appendChild(document.createElement('i'));

    bubble.appendChild(dots);
    this.log.appendChild(bubble);
    this.scroll();

    return bubble;
  }

  /**
   * A bubble that grows as tokens arrive.
   *
   * `aria-hidden` while streaming: the log's live region would otherwise
   * announce every fragment. The finished text is announced once, whole,
   * through announce(). Returns the inner element deltas write into.
   */
  private appendStreaming(): HTMLElement {
    const bubble = document.createElement('div');
    bubble.className = 'scr-msg';
    bubble.dataset.role = 'assistant';
    bubble.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    bubble.appendChild(text);

    this.log.appendChild(bubble);
    this.scroll();

    return text;
  }

  /**
   * One whole-message announcement for screen readers (FR-CHAT-04).
   */
  private announce(content: string): void {
    if (!this.srRegion) {
      this.srRegion = document.createElement('div');
      this.srRegion.className = 'scr-sr';
      this.srRegion.setAttribute('role', 'status');
      this.panel.appendChild(this.srRegion);
    }

    this.srRegion.textContent = content;
  }

  private append(role: 'user' | 'assistant', content: string): void {
    const bubble = document.createElement('div');
    bubble.className = 'scr-msg';
    bubble.dataset.role = role;
    bubble.appendChild(renderMessage(content));

    this.log.appendChild(bubble);
    this.scroll();
  }

  private note(text: string): void {
    const note = document.createElement('div');
    note.className = 'scr-note';
    note.setAttribute('role', 'status');
    note.textContent = text;

    this.log.appendChild(note);
    this.scroll();
  }

  private scroll(): void {
    this.log.scrollTop = this.log.scrollHeight;
  }
}
