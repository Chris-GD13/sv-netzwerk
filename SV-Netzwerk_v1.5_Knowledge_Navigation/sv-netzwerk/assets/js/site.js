document.documentElement.classList.add('js');

(function(){
  var box=document.getElementById('form-status');
  if(!box) return;
  var p=new URLSearchParams(window.location.search);
  if(p.get('gesendet')==='1'){
    box.hidden=false;
    box.className='form-status ok';
    box.textContent='Vielen Dank. Ihre Anfrage wurde uebermittelt.';
  } else if(p.get('fehler')){
    box.hidden=false;
    box.className='form-status error';
    box.textContent='Die Anfrage konnte nicht uebermittelt werden. Bitte pruefen Sie die Pflichtfelder oder kontaktieren Sie uns per E-Mail.';
  }
})();
