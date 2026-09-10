import MsgReader from '@kenjiuno/msgreader';
import PostalMime from 'postal-mime';

(() => {
  const API = '/intern/api/google-drive-sync.php';
  const BROWSER = '/intern/api/case-file-browser.php';
  const VIEWER_MARKER = 'svnet-mail-viewer-v1';
  const input = document.getElementById('vf-case-mail-files');
  const drop = document.getElementById('vf-case-mail-drop');
  const refresh = document.getElementById('vf-case-mail-refresh');
  const state = document.getElementById('vf-case-mail-state');
  const list = document.getElementById('vf-case-mail-list');
  const activePanel = document.getElementById('vf-active');
  if (!input || !drop || !refresh || !state || !list || !activePanel) return;

  let renderedItems = [];
  let previewUrls = [];
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);
  const activeCase = () => {
    for (const storage of [sessionStorage, localStorage]) {
      try {
        const value = JSON.parse(storage.getItem('svnet-case') || 'null');
        if (value?.folder_id) return value;
      } catch {}
    }
    return null;
  };
  const request = async (url, options = {}) => {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw Error(data.error || `HTTP ${response.status}`);
    return data;
  };
  const isMessage = item => /\.(?:msg|eml)$/i.test(item.name || '') || ['message/rfc822', 'application/vnd.ms-outlook'].includes(item.mimeType || '');
  const flatten = (items, inCorrespondence = false, result = []) => {
    for (const item of items || []) {
      const normalizedName = String(item.name || '').toLowerCase().replace(/ä/g, 'ae');
      const correspondence = inCorrespondence || (item.folder && /^07[_\s-]*korrespondenz$/.test(normalizedName));
      if (item.folder) flatten(item.children, correspondence, result);
      else if (correspondence || isMessage(item)) result.push(item);
    }
    return result;
  };
  const formatDate = value => {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  };
  const formatSize = bytes => {
    const size = Number(bytes || 0);
    if (!size) return '';
    return size < 1024 * 1024 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
  };
  const textFromHtml = html => {
    if (!html) return '';
    const document = new DOMParser().parseFromString(String(html), 'text/html');
    return document.body?.innerText || document.body?.textContent || '';
  };
  const address = value => {
    if (!value) return '';
    if (typeof value === 'string') return value;
    const email = value.address || value.email || '';
    return value.name && email ? `${value.name} <${email}>` : value.name || email;
  };
  const addresses = values => (Array.isArray(values) ? values : values ? [values] : []).map(address).filter(Boolean).join('; ');
  const normalizeMsg = (reader, info) => ({
    subject: info.subject || '(ohne Betreff)',
    from: address({ name: info.senderName, email: info.senderEmail }),
    to: addresses((info.recipients || []).filter(recipient => String(recipient.recipType || '').toLowerCase() === 'to')),
    cc: addresses((info.recipients || []).filter(recipient => String(recipient.recipType || '').toLowerCase() === 'cc')),
    date: info.clientSubmitTime || info.messageDeliveryTime || info.creationTime || info.lastModificationTime,
    body: info.body || textFromHtml(info.bodyHtml) || '',
    attachments: (info.attachments || []).map(attachment => {
      const file = reader.getAttachment(attachment);
      return { name: file.fileName || attachment.fileName || 'Anhang', type: attachment.mimeType || 'application/octet-stream', content: file.content };
    }),
  });
  const normalizeEml = mail => ({
    subject: mail.subject || '(ohne Betreff)',
    from: address(mail.from),
    to: addresses(mail.to),
    cc: addresses(mail.cc),
    date: mail.date,
    body: mail.text || textFromHtml(mail.html) || '',
    attachments: (mail.attachments || []).map(attachment => ({
      name: attachment.filename || 'Anhang', type: attachment.mimeType || 'application/octet-stream', content: attachment.content,
    })),
  });
  const ensureViewer = () => {
    let viewer = document.getElementById('vf-mail-viewer');
    if (viewer) return viewer;
    viewer = document.createElement('div');
    viewer.id = 'vf-mail-viewer';
    viewer.dataset.viewer = VIEWER_MARKER;
    viewer.hidden = true;
    viewer.innerHTML = `<style>
      #vf-mail-viewer{position:fixed;inset:0;z-index:10000;background:rgba(8,30,49,.65);padding:clamp(12px,3vw,42px);overflow:auto}
      #vf-mail-viewer[hidden]{display:none}
      .vf-mail-viewer-card{width:min(980px,100%);min-height:60vh;margin:auto;background:#fff;border-radius:8px;box-shadow:0 18px 55px rgba(0,0,0,.28);overflow:hidden;color:#17324b}
      .vf-mail-viewer-head{display:flex;gap:18px;align-items:flex-start;justify-content:space-between;padding:22px 26px;border-bottom:1px solid #d7e0e7}
      .vf-mail-viewer-head h2{margin:0;font-size:1.35rem;line-height:1.25}.vf-mail-viewer-close{border:1px solid #17324b;background:#fff;padding:8px 13px;cursor:pointer;font-weight:700}
      .vf-mail-viewer-meta{padding:16px 26px;background:#f3f7fa;border-bottom:1px solid #d7e0e7;display:grid;grid-template-columns:max-content 1fr;gap:5px 14px}.vf-mail-viewer-meta strong{font-size:.85rem}
      .vf-mail-viewer-body{white-space:pre-wrap;overflow-wrap:anywhere;padding:24px 26px;line-height:1.55;min-height:250px;font-family:inherit}
      .vf-mail-viewer-attachments{padding:0 26px 22px}.vf-mail-viewer-attachments a{display:inline-block;margin:6px 8px 0 0;padding:8px 11px;border:1px solid #a8bac8;text-decoration:none;color:#17324b}
      .vf-mail-viewer-actions{padding:18px 26px;border-top:1px solid #d7e0e7;display:flex;justify-content:flex-end}.vf-mail-viewer-save{padding:10px 14px;background:#17324b;color:#fff;text-decoration:none;font-weight:700}
    </style><section class="vf-mail-viewer-card" role="dialog" aria-modal="true" aria-labelledby="vf-mail-viewer-subject"><header class="vf-mail-viewer-head"><h2 id="vf-mail-viewer-subject">Mail wird geöffnet …</h2><button class="vf-mail-viewer-close" type="button">Schließen</button></header><div class="vf-mail-viewer-meta"></div><div class="vf-mail-viewer-body">Mail wird gelesen …</div><div class="vf-mail-viewer-attachments"></div><div class="vf-mail-viewer-actions"><a class="vf-mail-viewer-save" href="#">Original speichern</a></div></section>`;
    document.body.append(viewer);
    const close = () => {
      viewer.hidden = true;
      previewUrls.forEach(URL.revokeObjectURL);
      previewUrls = [];
    };
    viewer.querySelector('.vf-mail-viewer-close').addEventListener('click', close);
    viewer.addEventListener('click', event => { if (event.target === viewer) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !viewer.hidden) close(); });
    return viewer;
  };
  const showMail = (mail, downloadUrl) => {
    const viewer = ensureViewer();
    viewer.querySelector('#vf-mail-viewer-subject').textContent = mail.subject;
    const rows = [['Von', mail.from], ['An', mail.to], ['Cc', mail.cc], ['Datum', formatDate(mail.date)]].filter(([, value]) => value);
    const meta = viewer.querySelector('.vf-mail-viewer-meta');
    meta.replaceChildren(...rows.flatMap(([label, value]) => {
      const title = document.createElement('strong'); title.textContent = label;
      const content = document.createElement('span'); content.textContent = value;
      return [title, content];
    }));
    viewer.querySelector('.vf-mail-viewer-body').textContent = mail.body || 'Kein Nachrichtentext vorhanden.';
    const attachments = viewer.querySelector('.vf-mail-viewer-attachments');
    attachments.replaceChildren();
    if (mail.attachments.length) {
      const heading = document.createElement('strong'); heading.textContent = `Anhänge (${mail.attachments.length})`;
      attachments.append(heading, document.createElement('br'));
      for (const attachment of mail.attachments) {
        const url = URL.createObjectURL(new Blob([attachment.content], { type: attachment.type }));
        previewUrls.push(url);
        const link = document.createElement('a'); link.href = url; link.download = attachment.name; link.textContent = attachment.name;
        attachments.append(link);
      }
    }
    const save = viewer.querySelector('.vf-mail-viewer-save');
    save.href = downloadUrl;
    viewer.hidden = false;
    viewer.querySelector('.vf-mail-viewer-close').focus();
  };
  const openMessage = async (item, fileUrl, downloadUrl) => {
    const viewer = ensureViewer();
    viewer.querySelector('#vf-mail-viewer-subject').textContent = item.name || 'Mail';
    viewer.querySelector('.vf-mail-viewer-meta').replaceChildren();
    viewer.querySelector('.vf-mail-viewer-body').textContent = 'Mail wird gelesen …';
    viewer.querySelector('.vf-mail-viewer-attachments').replaceChildren();
    viewer.querySelector('.vf-mail-viewer-save').href = downloadUrl;
    viewer.hidden = false;
    try {
      const response = await fetch(fileUrl, { credentials: 'same-origin' });
      if (!response.ok) throw Error(`HTTP ${response.status}`);
      const buffer = await response.arrayBuffer();
      const mail = /\.eml$/i.test(item.name || '')
        ? normalizeEml(await new PostalMime().parse(buffer))
        : (() => { const reader = new MsgReader(buffer); return normalizeMsg(reader, reader.getFileData()); })();
      showMail(mail, downloadUrl);
    } catch (error) {
      viewer.querySelector('.vf-mail-viewer-body').textContent = `Die Mail konnte nicht angezeigt werden: ${error.message}`;
    }
  };
  const setEnabled = enabled => {
    refresh.disabled = !enabled;
    input.disabled = !enabled;
    drop.classList.toggle('disabled', !enabled);
    drop.setAttribute('aria-disabled', String(!enabled));
  };
  const render = items => {
    renderedItems = items;
    if (!items.length) {
      list.innerHTML = '<p class="vf-mail-history-empty">In diesem Fall sind noch keine Mails oder Korrespondenzen gespeichert.</p>';
      return;
    }
    list.innerHTML = items.map((item, index) => {
      const params = `folder_id=${encodeURIComponent(activeCase()?.folder_id || '')}&file_id=${encodeURIComponent(item.id)}`;
      const meta = [formatDate(item.modifiedTime), formatSize(item.size)].filter(Boolean).join(' · ');
      const fileUrl = `${BROWSER}?action=file&${params}`;
      const downloadUrl = `${BROWSER}?action=download&${params}`;
      const open = isMessage(item)
        ? `<a href="${fileUrl}" data-mail-open="${index}">Öffnen</a>`
        : `<a href="${fileUrl}" target="_blank" rel="noopener">Öffnen</a>`;
      return `<article class="vf-mail-history-item"><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(meta)}</small><div class="vf-mail-history-links">${open}<a href="${downloadUrl}">Speichern</a></div></article>`;
    }).join('');
    list.querySelectorAll('[data-mail-open]').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      const item = renderedItems[Number(link.dataset.mailOpen)];
      if (!item) return;
      const params = `folder_id=${encodeURIComponent(activeCase()?.folder_id || '')}&file_id=${encodeURIComponent(item.id)}`;
      openMessage(item, `${BROWSER}?action=file&${params}`, `${BROWSER}?action=download&${params}`);
    }));
  };
  const load = async () => {
    const active = activeCase();
    setEnabled(Boolean(active));
    if (!active) {
      state.textContent = 'Bitte zuerst einen Fall öffnen.';
      list.innerHTML = '';
      return;
    }
    state.textContent = 'Gespeicherter Mailverlauf wird geladen …';
    try {
      const data = await request(`${BROWSER}?folder_id=${encodeURIComponent(active.folder_id)}`);
      const items = flatten(data.items).sort((a, b) => String(b.modifiedTime || '').localeCompare(String(a.modifiedTime || '')));
      render(items);
      state.textContent = items.length === 1 ? '1 gespeicherte Mail/Korrespondenz.' : `${items.length} gespeicherte Mails/Korrespondenzen.`;
    } catch (error) {
      list.innerHTML = '';
      state.textContent = `Mailverlauf konnte nicht geladen werden: ${error.message}`;
    }
  };
  const receive = async fileList => {
    const active = activeCase();
    if (!active) {
      state.textContent = 'Bitte zuerst einen Fall öffnen.';
      return;
    }
    const files = [...fileList];
    if (!files.length) return;
    const invalid = files.filter(file => !/\.(?:msg|eml)$/i.test(file.name));
    if (invalid.length) {
      state.textContent = 'Bitte Outlook-Mails als .msg oder .eml ablegen.';
      return;
    }
    setEnabled(false);
    let uploaded = 0;
    let duplicates = 0;
    try {
      for (const [index, file] of files.entries()) {
        state.textContent = `Mail ${index + 1} von ${files.length} wird in der Fallakte gespeichert …`;
        const body = new FormData();
        body.append('folder_id', active.folder_id);
        body.append('last_modified', String(file.lastModified || 0));
        body.append('file', file);
        const result = await request(`${API}?action=upload_case_document`, { method: 'POST', body });
        result.duplicate ? duplicates++ : uploaded++;
      }
      input.value = '';
      await load();
      state.textContent = `${uploaded} Mail${uploaded === 1 ? '' : 's'} gespeichert${duplicates ? ` · ${duplicates} bereits vorhanden` : ''}.`;
    } catch (error) {
      state.textContent = `Mail konnte nicht gespeichert werden: ${error.message}`;
    } finally {
      setEnabled(Boolean(activeCase()));
    }
  };

  drop.addEventListener('click', () => { if (activeCase()) input.click(); });
  drop.addEventListener('keydown', event => {
    if ((event.key === 'Enter' || event.key === ' ') && activeCase()) {
      event.preventDefault();
      input.click();
    }
  });
  input.addEventListener('change', () => receive(input.files));
  ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => {
    event.preventDefault();
    if (activeCase()) drop.classList.add('drag');
  }));
  ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, event => {
    event.preventDefault();
    drop.classList.remove('drag');
  }));
  drop.addEventListener('drop', event => receive(event.dataTransfer?.files || []));
  refresh.addEventListener('click', load);
  new MutationObserver(load).observe(activePanel, { attributes: true, childList: true, subtree: true });
  window.addEventListener('svnet:cases-changed', load);
  load();
})();
