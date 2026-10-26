// Isolated content-script world. Uses rendered original widgets and native save.
// No SDK internals, guessed write endpoint, export, send or QS action.
(() => {
  const protectedIds=new Set(['insurerClaimId','insurerPolicyId','Gesamt','Gesamt Freigabe','Gesamt Restreserve','Endergebnis']);
  const visible=n=>n.getClientRects().length>0;
  const pause=ms=>new Promise(r=>setTimeout(r,ms));
  const saveButton=()=>{
    const nodes=[...document.querySelectorAll('button')].filter(n=>visible(n)&&n.textContent.trim()==='Entwurf speichern');
    if(nodes.length!==1)throw Error('NATIVE_SAVE_CONTROL_MISSING');return nodes[0];
  };
  function assertRoute(expected){
    const path=`/claims/${expected.caseId}/forms/${expected.formId}`;
    if(location.origin!=='https://web.claimsforce.com'||location.pathname!==path)throw Error('NATIVE_ROUTE_MISMATCH');
    return path;
  }
  function context(expected){
    const path=assertRoute(expected);
    const frames=[...document.querySelectorAll('iframe')].filter(f=>{
      try{const u=new URL(f.src,location.href);let base=new URLSearchParams(u.hash.slice(1)).get('basePath')||u.searchParams.get('basePath')||'';
        for(let i=0;i<2&&base.includes('%');i++)base=decodeURIComponent(base);
        return u.origin===location.origin&&/^\/apryse\/[^/]+\/(?:\.\/)?ui\/index\.html$/.test(u.pathname)&&base===path;
      }catch{return false;}
    });
    if(frames.length!==1||!frames[0].contentDocument)throw Error('NATIVE_FRAME_NOT_READY');
    const doc=frames[0].contentDocument;
    const controls=[...doc.querySelectorAll('.text[id] input,.text[id] textarea,.choice[id] select')].filter(visible);
    if(!controls.length)throw Error('NATIVE_WIDGETS_NOT_READY');
    return{doc,controls};
  }
  async function snapshot(expected){
    const {controls}=context(expected),groups=new Map();
    for(const node of controls){const id=node.closest('.text[id],.choice[id]').id;const group=groups.get(id)||[];group.push(node);groups.set(id,group)}
    const fields=[...groups].map(([id,nodes])=>{
      if(new Set(nodes.map(n=>n.value)).size!==1)throw Error('NATIVE_MIRROR_VALUES_DIFFER');
      const n=nodes[0];return{id,value:n.value,type:n.tagName.toLowerCase(),count:nodes.length,calculated:protectedIds.has(id)&&!id.startsWith('insurer'),writable:nodes.length===1&&!protectedIds.has(id)&&!n.disabled&&!n.readOnly,...(n.tagName==='SELECT'?{options:[...n.options].map(o=>o.value)}:{})};
    }).sort((a,b)=>a.id.localeCompare(b.id));
    const number=fields.find(f=>f.id==='insurerClaimId');
    if(!number||number.value!==expected.claimNumber)throw Error('NATIVE_CLAIM_NUMBER_MISMATCH: '+JSON.stringify({expected:expected.claimNumber,observed:number?.value??null})+'; no fields changed');
    const hash=[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(fields))))].map(v=>v.toString(16).padStart(2,'0')).join('');
    return{caseId:expected.caseId,formId:expected.formId,profile:expected.profile,claimNumber:number.value,fields,hash,dirty:!saveButton().disabled};
  }
  async function apply(expected){
    const before=await snapshot(expected);
    if(before.hash!==expected.expectedHash||before.dirty)throw Error('NATIVE_STALE_OR_DIRTY');
    const {controls}=context(expected),plan=[];
    // Validate the entire patch before entering even its first character.
    for(const c of expected.changes||[]){
      const f=before.fields.find(f=>f.id===c.field);
      if(!f?.writable||f.value!==c.before||typeof c.after!=='string'||c.after.length>10000||(f.options&&!f.options.includes(c.after)))throw Error('NATIVE_PATCH_REJECTED');
      if(plan.some(p=>p.field===c.field))throw Error('NATIVE_DUPLICATE_PATCH');
      plan.push({...c,node:controls.find(n=>n.closest('.text[id],.choice[id]').id===c.field)});
    }
    if(!plan.length||plan.length>100)throw Error('NATIVE_PATCH_EMPTY_OR_LARGE');
    for(const c of plan){
      context(expected);
      const n=c.node,w=n.ownerDocument.defaultView;
      const proto=n.tagName==='SELECT'?w.HTMLSelectElement.prototype:n.tagName==='TEXTAREA'?w.HTMLTextAreaElement.prototype:w.HTMLInputElement.prototype;
      const setter=Object.getOwnPropertyDescriptor(proto,'value')?.set;
      if(!setter)throw Error('NATIVE_SETTER_UNAVAILABLE');
      // Apryse commits the widget value on focusout. In a background tab,
      // focus()/blur() need not emit that event even though the DOM value changes.
      // Supply only the missing DOM event; never dispatch a second commit.
      let lostFocus=false;
      const sawFocusOut=()=>{lostFocus=true};
      n.addEventListener('focusout',sawFocusOut);
      try {
        n.focus();setter.call(n,c.after);
        n.dispatchEvent(new w.Event('input',{bubbles:true}));
        n.dispatchEvent(new w.Event('change',{bubbles:true}));
        n.blur();
        if(!lostFocus)n.dispatchEvent(new w.FocusEvent('focusout',{bubbles:true}));
      } finally { n.removeEventListener('focusout',sawFocusOut); }
      await pause(0);
    }
    return snapshot(expected);
  }
  async function save(expected){
    context(expected);const button=saveButton();
    if(button.disabled)throw Error('NATIVE_SAVE_NOT_ENABLED');button.click();
    const deadline=Date.now()+20000;
    while(Date.now()<deadline){
      await pause(200);
      // Claims may replace the editable widgets with its saved preview.
      // The disabled button acknowledges only the request; the caller must
      // reopen the same native form and compare every field before verification.
      assertRoute(expected);
      if(saveButton().disabled)return{acknowledged:true,verified:false};
    }
    throw Error('NATIVE_SAVE_ACK_TIMEOUT');
  }
  window.SVNET_NATIVE_FORM_DOM={snapshot,apply,save};
})();
