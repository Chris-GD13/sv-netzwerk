(() => {
  document.querySelectorAll('[data-signature-section]').forEach((section) => {
    const toggle = section.querySelector('[data-signature-toggle]');
    const capture = section.querySelector('[data-signature-capture]');
    const canvas = section.querySelector('[data-signature-canvas]');
    const clearButton = section.querySelector('[data-signature-clear]');
    const printBlock = section.querySelector('[data-signature-print]');
    const alternatives = [...section.querySelectorAll('[data-signature-alternative]')];
    if (!(toggle instanceof HTMLInputElement) || !(capture instanceof HTMLElement) || !(canvas instanceof HTMLCanvasElement)) return;

    const context = canvas.getContext('2d');
    if (!context) return;
    let drawing = false;
    let signed = false;

    const configure = () => {
      const image = signed ? canvas.toDataURL('image/png') : '';
      const rect = canvas.getBoundingClientRect();
      const ratio = Math.max(1, window.devicePixelRatio || 1);
      canvas.width = Math.max(1, Math.round(rect.width * ratio));
      canvas.height = Math.max(1, Math.round(rect.height * ratio));
      context.setTransform(ratio, 0, 0, ratio, 0, 0);
      context.lineWidth = 2.2;
      context.lineCap = 'round';
      context.lineJoin = 'round';
      context.strokeStyle = '#102c45';
      if (image) {
        const restored = new Image();
        restored.onload = () => context.drawImage(restored, 0, 0, rect.width, rect.height);
        restored.src = image;
      }
    };
    const clear = () => {
      context.clearRect(0, 0, canvas.width, canvas.height);
      signed = false;
      printBlock?.classList.remove('has-signature');
    };
    const point = (event) => {
      const rect = canvas.getBoundingClientRect();
      return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };
    const begin = (event) => {
      if (!toggle.checked) return;
      drawing = true;
      canvas.setPointerCapture?.(event.pointerId);
      const p = point(event);
      context.beginPath();
      context.moveTo(p.x, p.y);
      event.preventDefault();
    };
    const draw = (event) => {
      if (!drawing) return;
      const p = point(event);
      context.lineTo(p.x, p.y);
      context.stroke();
      signed = true;
      printBlock?.classList.add('has-signature');
      event.preventDefault();
    };
    const end = (event) => {
      if (!drawing) return;
      draw(event);
      drawing = false;
      canvas.releasePointerCapture?.(event.pointerId);
    };
    const update = () => {
      capture.hidden = !toggle.checked;
      if (toggle.checked) {
        alternatives.forEach((input) => { input.checked = false; });
        requestAnimationFrame(configure);
      } else {
        clear();
      }
    };

    toggle.addEventListener('change', update);
    alternatives.forEach((input) => input.addEventListener('change', () => {
      if (input.checked) {
        alternatives.forEach((other) => { if (other !== input) other.checked = false; });
        toggle.checked = false;
        update();
      }
    }));
    clearButton?.addEventListener('click', clear);
    canvas.addEventListener('pointerdown', begin);
    canvas.addEventListener('pointermove', draw);
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
    window.addEventListener('resize', () => { if (toggle.checked) configure(); });
  });
})();
