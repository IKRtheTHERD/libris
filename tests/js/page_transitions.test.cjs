'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function loadPageMotion(initiallyReduced = false) {
  const documentHandlers = {};
  const windowHandlers = {};
  const mediaHandlers = {};
  const htmlDataset = {};
  const preference = {
    matches: initiallyReduced,
    addEventListener: (type, handler) => { mediaHandlers[type] = handler; },
  };
  const context = {
    document: {
      addEventListener: (type, handler) => { documentHandlers[type] = handler; },
      body: { classList: { add() {} } },
      documentElement: { dataset: htmlDataset },
      querySelector: () => null,
      querySelectorAll: () => [],
    },
    window: {
      addEventListener: (type, handler) => { windowHandlers[type] = handler; },
      matchMedia: (query) => {
        if (query === '(prefers-color-scheme: dark)') return { matches: false };
        assert.equal(query, '(prefers-reduced-motion: reduce)');
        return preference;
      },
    },
  };

  vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../../assets/js/main.js'), 'utf8'),
    context,
  );

  return { htmlDataset, mediaHandlers, preference, windowHandlers };
}

test('page motion is enabled when the user has not requested reduced motion', () => {
  const page = loadPageMotion();
  assert.equal(page.htmlDataset.pageTransitions, 'enabled');
});

test('page motion follows live reduced-motion preference changes', () => {
  const page = loadPageMotion(true);
  assert.equal(page.htmlDataset.pageTransitions, undefined);

  page.preference.matches = false;
  page.mediaHandlers.change();
  assert.equal(page.htmlDataset.pageTransitions, 'enabled');

  page.preference.matches = true;
  page.mediaHandlers.change();
  assert.equal(page.htmlDataset.pageTransitions, undefined);
});

test('pageshow rechecks motion preference after a BFCache restore', () => {
  const page = loadPageMotion();
  page.preference.matches = true;

  page.windowHandlers.pageshow({ persisted: true });

  assert.equal(page.htmlDataset.pageTransitions, undefined);
});
