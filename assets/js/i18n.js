(() => {
  const root = document.documentElement;
  const languageKey = 'libris-language';

  const writePreference = (key, value) => {
    try {
      window.localStorage.setItem(key, value);
    } catch {
      // The language cookie still keeps this interface preference across requests.
    }
  };

  const setLanguageCookie = (language) => {
    document.cookie = `libris_lang=${language}; path=/; max-age=31536000; samesite=lax`;
  };

  const currentLanguage = root.lang === 'ckb' ? 'ckb' : 'en';
  writePreference(languageKey, currentLanguage);

  document.querySelector('[data-language-toggle]')?.addEventListener('click', () => {
    const nextLanguage = currentLanguage === 'ckb' ? 'en' : 'ckb';
    writePreference(languageKey, nextLanguage);
    setLanguageCookie(nextLanguage);
    window.location.reload();
  });
})();
