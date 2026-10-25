'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function loadTheme({ saved = null, systemDark = false, storageThrows = false } = {}) {
  const handlers = {};
  const dataset = {};
  const values = new Map(saved === null ? [] : [['libris-theme', saved]]);
  const button = {
    attributes: {},
    addEventListener: (name, handler) => { handlers[name] = handler; },
    setAttribute: (name, value) => { button.attributes[name] = value; },
  };
  const localStorage = {
    getItem: (key) => {
      if (storageThrows) throw new Error('storage disabled');
      return values.get(key) ?? null;
    },
    setItem: (key, value) => {
      if (storageThrows) throw new Error('storage disabled');
      values.set(key, value);
    },
  };
  const context = {
    document: {
      addEventListener() {},
      body: { classList: { add() {} } },
      documentElement: { dataset },
      querySelector: (selector) => selector === '[data-theme-toggle]' ? button : null,
      querySelectorAll: () => [],
    },
    window: {
      addEventListener() {},
      localStorage,
      matchMedia: (query) => ({
        matches: query === '(prefers-color-scheme: dark)' ? systemDark : false,
        addEventListener() {},
      }),
    },
  };
  for (const script of ['i18n.js', 'main.js']) {
    vm.runInNewContext(
      fs.readFileSync(path.join(__dirname, `../../assets/js/${script}`), 'utf8'),
      context,
    );
  }
  return { button, dataset, handlers, values };
}

test('uses system dark preference when the reader has no saved choice', () => {
  const page = loadTheme({ systemDark: true });
  assert.equal(page.dataset.theme, 'dark');
  assert.equal(page.button.attributes['aria-pressed'], 'true');
});

test('saved reader choice takes precedence over system preference', () => {
  const page = loadTheme({ saved: 'light', systemDark: true });
  assert.equal(page.dataset.theme, 'light');
  assert.equal(page.button.attributes['aria-pressed'], 'false');
});

test('toggle updates the document, accessible state, and persisted preference', () => {
  const page = loadTheme();
  page.handlers.click();
  assert.equal(page.dataset.theme, 'dark');
  assert.equal(page.button.attributes['aria-pressed'], 'true');
  assert.equal(page.values.get('libris-theme'), 'dark');
  page.handlers.click();
  assert.equal(page.dataset.theme, 'light');
  assert.equal(page.values.get('libris-theme'), 'light');
});

test('theme toggle remains usable when browser storage is unavailable', () => {
  const page = loadTheme({ storageThrows: true });
  page.handlers.click();
  assert.equal(page.dataset.theme, 'dark');
  assert.equal(page.button.attributes['aria-pressed'], 'true');
});
