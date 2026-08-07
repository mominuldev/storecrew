/**
 * Turning an agent's answer into DOM.
 *
 * Models reply in Markdown whether or not they were asked to, so an answer
 * rendered as flat text shows a customer literal asterisks and dashes. The
 * fragment this produces covers what actually appears in a support answer —
 * paragraphs, bullets, emphasis, code, links — and nothing else.
 *
 * **Nothing here ever assigns `innerHTML`.** Every piece of model output becomes
 * a text node, and every element is created by name, so there is no string
 * concatenation for an injection to survive in. That matters more here than
 * anywhere else in the plugin: the text being rendered was written by a model
 * that just read indexed product descriptions and customer reviews, which is to
 * say by something that has been reading attacker-supplied input all along.
 */

const BOLD = /\*\*([^*]+)\*\*/;
const CODE = /`([^`]+)`/;
const URL_RE = /https?:\/\/[^\s<>"')\]]+/;

/**
 * A document fragment for one message body.
 */
export function renderMessage(text: string): DocumentFragment {
  const fragment = document.createDocumentFragment();
  const blocks = text.replace(/\r\n/g, '\n').split(/\n{2,}/);

  for (const block of blocks) {
    const trimmed = block.trim();

    if (!trimmed) {
      continue;
    }

    // Lines are grouped into runs rather than the block being classified as a
    // whole. "Here is what I can tell you:" followed by three dashed lines is
    // the single most common shape a model produces, and treating the block as
    // one thing renders that as a paragraph full of literal hyphens.
    let prose: string[] = [];
    let bullets: string[] = [];

    const flushProse = (): void => {
      if (prose.length === 0) {
        return;
      }

      const paragraph = document.createElement('p');

      prose.forEach((line, index) => {
        if (index > 0) {
          paragraph.appendChild(document.createElement('br'));
        }

        paragraph.appendChild(renderInline(line));
      });

      fragment.appendChild(paragraph);
      prose = [];
    };

    const flushBullets = (): void => {
      if (bullets.length === 0) {
        return;
      }

      const list = document.createElement('ul');

      for (const line of bullets) {
        const item = document.createElement('li');
        item.appendChild(renderInline(line));
        list.appendChild(item);
      }

      fragment.appendChild(list);
      bullets = [];
    };

    for (const line of trimmed.split('\n')) {
      const bullet = /^\s*[-*]\s+(.*)$/.exec(line);

      if (bullet) {
        flushProse();
        bullets.push(bullet[1]);
      } else {
        flushBullets();
        prose.push(line);
      }
    }

    flushProse();
    flushBullets();
  }

  return fragment;
}

/**
 * Inline emphasis, code and links.
 *
 * One pass, longest-match-first, recursing on the remainder. Recursion rather
 * than a global regex so that a `**bold**` inside a bullet and one at the end of
 * a paragraph are handled by the same code.
 */
function renderInline(text: string): DocumentFragment {
  const fragment = document.createDocumentFragment();

  const candidates: Array<{ index: number; length: number; node: () => Node }> = [];

  const bold = BOLD.exec(text);

  if (bold) {
    candidates.push({
      index: bold.index,
      length: bold[0].length,
      node: () => {
        const strong = document.createElement('strong');
        strong.textContent = bold[1];

        return strong;
      },
    });
  }

  const code = CODE.exec(text);

  if (code) {
    candidates.push({
      index: code.index,
      length: code[0].length,
      node: () => {
        const element = document.createElement('code');
        element.textContent = code[1];

        return element;
      },
    });
  }

  const url = URL_RE.exec(text);

  if (url) {
    candidates.push({
      index: url.index,
      length: url[0].length,
      node: () => link(url[0]),
    });
  }

  if (candidates.length === 0) {
    fragment.appendChild(document.createTextNode(text));

    return fragment;
  }

  candidates.sort((a, b) => a.index - b.index);
  const first = candidates[0];

  if (first.index > 0) {
    fragment.appendChild(document.createTextNode(text.slice(0, first.index)));
  }

  fragment.appendChild(first.node());

  const rest = text.slice(first.index + first.length);

  if (rest) {
    fragment.appendChild(renderInline(rest));
  }

  return fragment;
}

/**
 * A link, or plain text if the scheme is anything but http(s).
 *
 * The regex already excludes other schemes, but the check is repeated against
 * the parsed URL rather than the raw string: `javascript:` cannot survive both,
 * and a rule this cheap should not depend on one regex staying correct through
 * every future edit.
 */
function link(href: string): Node {
  let parsed: URL;

  try {
    parsed = new URL(href);
  } catch {
    return document.createTextNode(href);
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    return document.createTextNode(href);
  }

  const anchor = document.createElement('a');
  anchor.href = parsed.href;
  anchor.textContent = href;
  anchor.target = '_blank';
  anchor.rel = 'noopener noreferrer nofollow';

  return anchor;
}
