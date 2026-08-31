(() => {
  const dialog = document.getElementById('chatgpt-onboarding');
  const openButton = document.getElementById('chatgpt-onboarding-open');
  const checkButton = document.getElementById('chatgpt-onboarding-check');
  const laterButton = document.getElementById('chatgpt-onboarding-later');
  const state = document.getElementById('chatgpt-onboarding-state');
  const note = document.getElementById('chatgpt-onboarding-note');
  if (!(dialog instanceof HTMLElement) || !(openButton instanceof HTMLButtonElement) || !(checkButton instanceof HTMLButtonElement)) return;

  let installUrl = 'https://chatgpt.com/plugins';
  const setState = (text, error = false) => {
    if (!(state instanceof HTMLElement)) return;
    state.textContent = text;
    state.classList.toggle('is-error', error);
    state.hidden = text === '';
  };
  const readStatus = async (showSuccess = false) => {
    const response = await fetch('/intern/api/chatgpt-onboarding.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'Status konnte nicht geprüft werden.');
    if (!data.required || data.installed) {
      dialog.hidden = true;
      if (showSuccess) window.dispatchEvent(new CustomEvent('svnet:notice', { detail: 'ChatGPT ist mit deinem Portalzugang verbunden.' }));
      return data;
    }
    installUrl = data.install_url;
    openButton.textContent = data.direct_install ? 'Plugin jetzt installieren' : 'Plugin-Verzeichnis öffnen';
    if (note instanceof HTMLElement) {
      note.textContent = data.direct_install
        ? 'Nach dem Klick meldest du dich mit deinem eigenen ChatGPT-/Work-Zugang an und bestätigst einmal die Verbindung zum Prüfportal.'
        : `Öffne das Verzeichnis und suche nach „${data.plugin_name}“. Danach bestätigst du einmal die Verbindung zum Prüfportal.`;
    }
    if (sessionStorage.getItem('svnet-chatgpt-onboarding-later') !== '1') dialog.hidden = false;
    return data;
  };

  openButton.addEventListener('click', () => {
    window.open(installUrl, '_blank', 'noopener,noreferrer');
    setState('ChatGPT wurde geöffnet. Installiere das Plugin dort und klicke anschließend hier auf „Verbindung prüfen“.');
    checkButton.focus();
  });
  checkButton.addEventListener('click', async () => {
    checkButton.disabled = true;
    setState('Verbindung wird geprüft …');
    try {
      const data = await readStatus(true);
      if (!data.installed) setState('Noch keine Verbindung erkannt. Schließe die Installation in ChatGPT ab und prüfe danach erneut.', true);
    } catch (error) {
      setState(error instanceof Error ? error.message : 'Verbindung konnte nicht geprüft werden.', true);
    } finally {
      checkButton.disabled = false;
    }
  });
  laterButton?.addEventListener('click', () => {
    sessionStorage.setItem('svnet-chatgpt-onboarding-later', '1');
    dialog.hidden = true;
  });
  readStatus().catch(() => { dialog.hidden = true; });
})();
