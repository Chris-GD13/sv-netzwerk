(()=>{
  const template=`Abgeltungsvereinbarung – optischer Schaden

Für die Positionen [Positionen eintragen] wird auf ausdrücklichen Wunsch und mit Zustimmung des VN anstelle der tatsächlichen Wiederherstellung eine pauschale Abgeltung vereinbart.

Der kalkulierte Wiederherstellungsbetrag dieser Positionen beträgt [Betrag] € brutto. Der vereinbarte Abgeltungssatz beträgt [Prozentsatz] %. Daraus ergibt sich ein Abgeltungsbetrag von [Betrag] €.

Mit Auszahlung des vereinbarten Abgeltungsbetrages sind ausschließlich die in dieser Kalkulation ausdrücklich als pauschal abgegolten bezeichneten Schadenpositionen abschließend reguliert. Aus diesen Positionen werden keine weiteren Ansprüche geltend gemacht.`;

  function grow(field){
    if(!(field instanceof HTMLTextAreaElement))return;
    field.style.height='auto';
    field.style.height=`${Math.max(96,field.scrollHeight+2)}px`;
  }

  function insertTemplate(button){
    const field=document.getElementById(button.dataset.noteTarget||'');
    if(!(field instanceof HTMLTextAreaElement))return;
    if(!field.value.includes('Abgeltungsvereinbarung – optischer Schaden')){
      field.value=[field.value.trim(),template].filter(Boolean).join('\n\n');
      field.dispatchEvent(new Event('input',{bubbles:true}));
    }
    grow(field);
    field.focus();
    field.setSelectionRange(field.value.length,field.value.length);
  }

  document.querySelectorAll('textarea[data-auto-grow]').forEach(field=>{
    grow(field);
    field.addEventListener('input',()=>grow(field));
  });
  document.querySelectorAll('[data-optical-settlement-template]').forEach(button=>{
    button.addEventListener('click',()=>insertTemplate(button));
  });
  document.addEventListener('click',event=>{
    if(event.target.closest?.('[data-edit-calc], [data-draft], #bk-new-free-draft')){
      setTimeout(()=>document.querySelectorAll('textarea[data-auto-grow]').forEach(grow),0);
    }
  },true);
  setTimeout(()=>document.querySelectorAll('textarea[data-auto-grow]').forEach(grow),250);
  setTimeout(()=>document.querySelectorAll('textarea[data-auto-grow]').forEach(grow),1000);
  addEventListener('beforeprint',()=>document.querySelectorAll('textarea[data-auto-grow]').forEach(grow));
})();
