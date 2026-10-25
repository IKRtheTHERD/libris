const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class FakeClassList {
  constructor() {
    this.values = new Set();
  }

  add(value) {
    this.values.add(value);
  }

  remove(value) {
    this.values.delete(value);
  }

  toggle(value, force) {
    if (force === undefined) force = !this.values.has(value);
    if (force) this.values.add(value);
    else this.values.delete(value);
    return force;
  }
}

function loadNavigation() {
  const handlers = { document: {}, toggle: {}, navigation: {} };
  const attributes = { 'aria-expanded': 'false' };
  const navigationClasses = new FakeClassList();
  let focusCount = 0;
  const menuToggle = {
    addEventListener: (type, handler) => { handlers.toggle[type] = handler; },
    getAttribute: (name) => attributes[name],
    setAttribute: (name, value) => { attributes[name] = value; },
    focus: () => { focusCount += 1; },
  };
  const navigation = {
    addEventListener: (type, handler) => { handlers.navigation[type] = handler; },
    classList: navigationClasses,
  };
  class FakeAnchor {}
  const document = {
    addEventListener: (type, handler) => { handlers.document[type] = handler; },
    body: { classList: new FakeClassList() },
    documentElement: { dataset: {} },
    querySelector: (selector) => {
      if (selector === '[data-menu-toggle]') return menuToggle;
      if (selector === '#primary-nav') return navigation;
      return null;
    },
    querySelectorAll: () => [],
  };
  const context = {
    document,
    HTMLAnchorElement: FakeAnchor,
    window: { matchMedia: () => ({ matches: true }) },
  };

  vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../../assets/js/main.js'), 'utf8'),
    context,
  );

  return {
    attributes,
    createAnchor: () => new FakeAnchor(),
    focusCount: () => focusCount,
    handlers,
    navigationClasses,
  };
}

test('Escape closes an open menu, updates aria state, and returns focus to its toggle', () => {
  const menu = loadNavigation();
  menu.handlers.toggle.click();
  assert.equal(menu.attributes['aria-expanded'], 'true');
  assert.equal(menu.navigationClasses.values.has('is-open'), true);

  menu.handlers.document.keydown({ key: 'Escape' });

  assert.equal(menu.attributes['aria-expanded'], 'false');
  assert.equal(menu.navigationClasses.values.has('is-open'), false);
  assert.equal(menu.focusCount(), 1);
});

test('Escape while the menu is closed does not steal focus', () => {
  const menu = loadNavigation();

  menu.handlers.document.keydown({ key: 'Escape' });

  assert.equal(menu.attributes['aria-expanded'], 'false');
  assert.equal(menu.navigationClasses.values.has('is-open'), false);
  assert.equal(menu.focusCount(), 0);
});

test('activating a primary navigation link closes the menu and resets aria state', () => {
  const menu = loadNavigation();
  menu.handlers.toggle.click();
  const anchor = menu.createAnchor();
  menu.handlers.navigation.click({ target: anchor });

  assert.equal(menu.attributes['aria-expanded'], 'false');
  assert.equal(menu.navigationClasses.values.has('is-open'), false);
});
