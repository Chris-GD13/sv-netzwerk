// Runs in the existing isolated Claims content-script world. Read-only, no saved field values returned.
(() => {
  function observe(doc, location, expected) {
    const route=String(location.href).match(/^https:\/\/web\.claimsforce\.com\/claims\/([^/]+)\/forms\/([^/?#]+)/);
    const result={outerLoaded:doc.readyState==='complete',viewerInitialized:false,fieldsReadable:false,fieldsEditable:false,identityVerified:false};
    if (!route || route[1]!==expected.caseId || route[2]!==expected.formId) {
      return {ok:true,observation:{...result,identityMismatch:true}};
    }
    result.identityVerified=true;
    // Apryse increments the iframe id on SPA navigation (live example: webviewer-3).
    // Bind to the expected native route, never to a fixed iframe number or another PDF.
    const frames=Array.from(doc.querySelectorAll('iframe')).filter(frame=>{
      try {
        const url=new URL(frame.getAttribute('src')||'',location.href);
        if(url.origin!=='https://web.claimsforce.com'||!/^\/apryse\/[^/]+\/(?:\.\/)?ui\/index\.html$/.test(url.pathname))return false;
        const parameters = new URLSearchParams(url.hash.slice(1));
        let base=parameters.get('basePath')||url.searchParams.get('basePath')||'';
        for(let n=0;n<2&&base.includes('%');n++)base=decodeURIComponent(base);
        return base===`/claims/${expected.caseId}/forms/${expected.formId}`;
      }catch{return false;}
    });
    if (frames.length!==1) return {ok:true,observation:{...result,viewerFrameCount:frames.length}};
    const frame=frames[0];
    result.viewerFrameFound=true;
    let inner;
    try { inner=frame.contentDocument; if(!inner) return {ok:true,observation:{...result,observationBlocked:true}}; }
    catch {return {ok:true,observation:{...result,observationBlocked:true}};}
    result.viewerInitialized=!!inner.body && inner.readyState==='complete';
    const names=expected.requiredFields;
    if (!Array.isArray(names)||!names.length||names.some(n=>typeof n!=='string'||!n.trim())) return {ok:true,observation:result};
    const controls=Array.from(inner.querySelectorAll('input,textarea,select'));
    const matched=names.map(name=>controls.filter(n=>[n.name,n.getAttribute('aria-label'),n.getAttribute('data-field-name')].includes(name)));
    result.fieldsReadable=matched.every(nodes=>nodes.length===1 && 'value' in nodes[0] && nodes[0].getClientRects().length>0);
    result.fieldsEditable=result.fieldsReadable && matched.every(([n])=>!n.disabled&&!n.readOnly);
    return {ok:true,observation:result};
  }
  window.SVNET_FORM_READINESS={observe};
})();
