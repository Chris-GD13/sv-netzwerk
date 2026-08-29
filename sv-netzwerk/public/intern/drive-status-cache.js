(()=>{
  let cached=null,expiresAt=0,pending=null;
  const load=async(force=false)=>{
    if(!force&&cached&&Date.now()<expiresAt)return cached;
    if(!force&&pending)return pending;
    pending=fetch('/intern/api/google-drive-sync.php?action=status',{credentials:'same-origin',cache:'no-store'})
      .then(async response=>{const data=await response.json().catch(()=>({}));if(!response.ok||!data.ok)throw Error(data.error||`HTTP ${response.status}`);cached=data;expiresAt=Date.now()+45000;return data})
      .finally(()=>{pending=null});
    return pending;
  };
  window.svnetDriveStatus=load;
  window.addEventListener('svnet:drive-status-invalidate',()=>{cached=null;expiresAt=0;pending=null});
})();
