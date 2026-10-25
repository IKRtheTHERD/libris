(() => {
  const menuToggle = document.querySelector('[data-menu-toggle]');
  const themeToggle = document.querySelector('[data-theme-toggle]');
  const navigation = document.querySelector('#primary-nav');

  const themeKey = 'libris-theme';
  const savedTheme = (() => {
    try {
      return window.localStorage?.getItem(themeKey);
    } catch {
      return null;
    }
  })();
  const systemPrefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches === true;
  const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    themeToggle?.setAttribute('aria-pressed', String(theme === 'dark'));
  };
  applyTheme(savedTheme === 'dark' || (savedTheme !== 'light' && systemPrefersDark) ? 'dark' : 'light');
  themeToggle?.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    applyTheme(theme);
    try {
      window.localStorage?.setItem(themeKey, theme);
    } catch {
      // The selected theme remains active for this page when storage is unavailable.
    }
  });

  const closeMenu = () => {
    if (!menuToggle || !navigation) return;
    menuToggle.setAttribute('aria-expanded', 'false');
    navigation.classList.remove('is-open');
  };

  menuToggle?.addEventListener('click', () => {
    const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
    menuToggle.setAttribute('aria-expanded', String(!isOpen));
    navigation?.classList.toggle('is-open', !isOpen);
  });

  navigation?.addEventListener('click', (event) => {
    if (event.target instanceof HTMLAnchorElement) closeMenu();
  });

  document.querySelectorAll('[data-auto-submit]').forEach((control) => {
    control.addEventListener('change', () => control.form?.requestSubmit());
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menuToggle?.getAttribute('aria-expanded') === 'true') {
      closeMenu();
      menuToggle?.focus();
    }
  });

  document.querySelectorAll('[data-book-cover]').forEach((image) => {
    image.addEventListener('error', () => {
      if (image.dataset.fallback === 'true') return;
      image.dataset.fallback = 'true';
      image.src = image.dataset.fallbackSrc || `${document.documentElement.dataset.basePath || ''}/assets/images/book-placeholder.svg`;
    }, { once: true });
  });

  const revealItems = document.querySelectorAll('[data-reveal]');
  const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
  const syncPageTransitions = () => {
    if (motionPreference.matches) {
      delete document.documentElement.dataset.pageTransitions;
      return;
    }
    document.documentElement.dataset.pageTransitions = 'enabled';
  };
  syncPageTransitions();
  motionPreference.addEventListener?.('change', syncPageTransitions);
  window.addEventListener?.('pageshow', syncPageTransitions);

  const reduceMotion = motionPreference.matches;
  let revealObserver = null;
  if (!reduceMotion && 'IntersectionObserver' in window && revealItems.length > 0) {
    document.body.classList.add('reveal-ready');
    revealObserver = new IntersectionObserver((entries, activeObserver) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          activeObserver.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -48px 0px', threshold: 0.08 });
    revealItems.forEach((item) => revealObserver.observe(item));
  } else {
    revealItems.forEach((item) => item.classList.add('is-visible'));
  }

  document.addEventListener('focusin', (event) => {
    if (!(event.target instanceof Element)) return;
    const revealItem = event.target.closest('[data-reveal]');
    if (!revealItem) return;
    revealItem.classList.add('is-visible');
    revealObserver?.unobserve(revealItem);
  });
})();
