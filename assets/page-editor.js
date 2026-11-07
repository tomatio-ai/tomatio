/**
 * TomaTío page editor (#632), inside the sandboxed frame of the members
 * address in edit mode (/a/{slug}?edit=1). Only pages without scripts of
 * their own get it.
 *
 * Text blocks (headings, paragraphs, list items, quotes, table cells and
 * blocks holding only text) become editable, with a toolbar: bold, italic,
 * link, add a paragraph or list item, remove one. The frame cannot reach the
 * site (opaque origin), so it saves nothing itself: when the person presses
 * Save on the page around it, that page asks for the body here and saves it.
 *
 * Messages, to the parent (data-parent origin only): {type: 'ab-edit:ready'},
 * {type: 'ab-edit:dirty'}, {type: 'ab-edit:body', id, html}. From the
 * parent (its window only): {type: 'ab-edit:collect', id}.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var parentOrigin = script && script.getAttribute('data-parent');
  if (!parentOrigin || window.parent === window) {
    return;
  }

  var BLOCK = /^(ADDRESS|ARTICLE|ASIDE|BLOCKQUOTE|CAPTION|DD|DETAILS|DIV|DL|DT|FIELDSET|FIGCAPTION|FIGURE|FOOTER|FORM|H[1-6]|HEADER|HR|LI|MAIN|NAV|OL|P|PRE|SECTION|TABLE|TBODY|TD|TFOOT|TH|THEAD|TR|UL)$/;
  var TEXT = /^(H[1-6]|P|LI|BLOCKQUOTE|TD|TH|FIGCAPTION|CAPTION|DT|DD|DIV|SECTION|ASIDE|HEADER|FOOTER|ARTICLE)$/;
  var UI = 'ab-editor-ui';
  var dirty = false;
  var current = null;
  var savedRange = null;

  function hasBlockChild(el) {
    for (var c = el.firstElementChild; c; c = c.nextElementSibling) {
      if (BLOCK.test(c.tagName) || hasBlockChild(c)) {
        return true;
      }
    }
    return false;
  }

  function isTextBlock(el) {
    return TEXT.test(el.tagName) && !el.closest('.' + UI) && !hasBlockChild(el)
      && (el.textContent.trim() !== '' || /^(P|LI|TD|TH|DD|DT)$/.test(el.tagName));
  }

  function makeEditable(el) {
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('data-ab-edit', '');
  }

  function markDirty() {
    if (!dirty) {
      dirty = true;
      window.parent.postMessage({ type: 'ab-edit:dirty' }, parentOrigin);
    }
  }

  function placeCaret(el, atEnd) {
    el.focus();
    var range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(!atEnd);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function editableOf(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    return el ? el.closest('[data-ab-edit]') : null;
  }

  function after(el, tag) {
    var next = document.createElement(tag);
    next.setAttribute('data-ab-new', '');
    makeEditable(next);
    el.after(next);
    return next;
  }

  /** A new block after the current one: a list item in a list, else a paragraph after the list or table. */
  function addBlock(kind) {
    if (!current) {
      return;
    }
    var anchor = current;
    var tag = 'P';
    if (kind === 'item' && current.tagName === 'LI') {
      tag = 'LI';
    } else if (current.tagName === 'LI' || current.tagName === 'TD' || current.tagName === 'TH') {
      anchor = current.closest('ul, ol, table') || current;
    }
    var next = after(anchor, tag);
    placeCaret(next, false);
    markDirty();
  }

  function removeBlock() {
    if (!current || !/^(P|LI|BLOCKQUOTE|DIV|DD|DT|FIGCAPTION|H[1-6])$/.test(current.tagName)) {
      return;
    }
    var gone = current;
    var list = gone.parentElement;
    var prev = gone.previousElementSibling;
    gone.remove();
    if (list && /^(UL|OL)$/.test(list.tagName) && !list.querySelector('li')) {
      list.remove();
    }
    current = null;
    if (prev && prev.hasAttribute('data-ab-edit')) {
      placeCaret(prev, true);
    }
    markDirty();
  }

  /** Enter splits the block at the caret: a new list item, or a paragraph after anything else. */
  function split(el) {
    var sel = window.getSelection();
    if (!sel.rangeCount) {
      return;
    }
    var range = sel.getRangeAt(0);
    range.deleteContents();
    var rest = document.createRange();
    rest.selectNodeContents(el);
    rest.setStart(range.endContainer, range.endOffset);
    var tail = rest.extractContents();
    var next = after(el, el.tagName === 'LI' ? 'LI' : 'P');
    next.appendChild(tail);
    if (next.textContent === '') {
      next.innerHTML = '';
    }
    placeCaret(next, false);
    markDirty();
  }

  function onKey(event) {
    var el = editableOf(event.target);
    if (!el) {
      return;
    }
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      if (/^(TD|TH|CAPTION)$/.test(el.tagName)) {
        return;
      }
      split(el);
    } else if (event.key === 'Backspace' && el.textContent === '' && /^(P|LI)$/.test(el.tagName)) {
      event.preventDefault();
      current = el;
      removeBlock();
    } else if ((event.metaKey || event.ctrlKey) && /^[bi]$/i.test(event.key)) {
      event.preventDefault();
      document.execCommand(event.key.toLowerCase() === 'b' ? 'bold' : 'italic');
      markDirty();
    }
  }

  function onPaste(event) {
    if (!editableOf(event.target)) {
      return;
    }
    event.preventDefault();
    var text = (event.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text.replace(/\s*\n\s*/g, ' '));
  }

  function rememberSelection() {
    var sel = window.getSelection();
    savedRange = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
  }

  function restoreSelection() {
    if (savedRange) {
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    }
  }

  function linkTarget(raw) {
    var url = raw.trim();
    if (url === '') {
      return '';
    }
    if (/^(https?:|mailto:|tel:|#|\/)/i.test(url)) {
      return url;
    }
    return /^[a-z][a-z0-9+.-]*:/i.test(url) ? null : 'https://' + url;
  }

  function button(label, title, action) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.title = title;
    b.setAttribute('aria-label', title);
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', action);
    return b;
  }

  function toolbar() {
    var bar = document.createElement('div');
    bar.className = UI;
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting');

    var linkBox = document.createElement('span');
    linkBox.className = 'ab-editor-link';
    linkBox.hidden = true;
    var input = document.createElement('input');
    input.type = 'url';
    input.placeholder = 'https://…';
    input.setAttribute('aria-label', 'Link address');
    linkBox.appendChild(input);

    function applyLink() {
      var target = linkTarget(input.value);
      linkBox.hidden = true;
      if (target === null) {
        return;
      }
      restoreSelection();
      document.execCommand(target === '' ? 'unlink' : 'createLink', false, target);
      markDirty();
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        applyLink();
      } else if (e.key === 'Escape') {
        linkBox.hidden = true;
        restoreSelection();
      }
    });
    linkBox.appendChild(button('OK', 'Apply the link', applyLink));

    bar.appendChild(button('B', 'Bold', function () { document.execCommand('bold'); markDirty(); }));
    bar.appendChild(button('I', 'Italic', function () { document.execCommand('italic'); markDirty(); }));
    bar.appendChild(button('Link', 'Link the selected text (empty to remove)', function () {
      rememberSelection();
      var a = savedRange && editableOf(savedRange.startContainer) && savedRange.startContainer.parentElement.closest('a');
      input.value = a ? a.getAttribute('href') : '';
      linkBox.hidden = false;
      input.focus();
    }));
    bar.appendChild(button('+ Paragraph', 'Add a paragraph after this one', function () { addBlock('paragraph'); }));
    bar.appendChild(button('+ Item', 'Add a list item after this one', function () { addBlock('item'); }));
    bar.appendChild(button('Remove', 'Remove this paragraph or list item', removeBlock));
    bar.appendChild(linkBox);
    return bar;
  }

  /** The body as it should be saved: no editor, no edit markers, images back to their own files. */
  function body() {
    var copy = document.body.cloneNode(true);
    copy.querySelectorAll('.' + UI + ', script[data-parent]').forEach(function (n) { n.remove(); });
    copy.querySelectorAll('[data-ab-new]').forEach(function (n) {
      if (n.textContent.trim() === '' && !n.querySelector('img')) {
        n.remove();
      }
    });
    copy.querySelectorAll('[data-ab-edit], [data-ab-new]').forEach(function (n) {
      n.removeAttribute('contenteditable');
      n.removeAttribute('data-ab-edit');
      n.removeAttribute('data-ab-new');
    });
    copy.querySelectorAll('img[data-ab-src]').forEach(function (img) {
      img.setAttribute('src', img.getAttribute('data-ab-src'));
      img.removeAttribute('data-ab-src');
    });
    return copy.innerHTML;
  }

  function start() {
    Array.prototype.forEach.call(document.body.querySelectorAll('*'), function (el) {
      if (isTextBlock(el)) {
        makeEditable(el);
      }
    });
    document.body.insertBefore(toolbar(), document.body.firstChild);
    document.addEventListener('keydown', onKey);
    document.addEventListener('paste', onPaste);
    document.addEventListener('input', markDirty);
    document.addEventListener('focusin', function (e) {
      var el = editableOf(e.target);
      if (el) {
        current = el;
      }
    });
    document.addEventListener('click', function (e) {
      if (e.target.closest('a') && editableOf(e.target)) {
        e.preventDefault();
      }
    });
    window.addEventListener('message', function (e) {
      if (e.source !== window.parent || !e.data || e.data.type !== 'ab-edit:collect') {
        return;
      }
      window.parent.postMessage({ type: 'ab-edit:body', id: e.data.id, html: body() }, parentOrigin);
    });
    window.parent.postMessage({ type: 'ab-edit:ready' }, parentOrigin);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
