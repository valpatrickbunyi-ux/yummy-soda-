document.querySelectorAll('[data-confirm]').forEach(btn=>{
  btn.addEventListener('click',e=>{
    const msg=btn.getAttribute('data-confirm')||'Are you sure?';
    if(!confirm(msg)) e.preventDefault();
  });
});