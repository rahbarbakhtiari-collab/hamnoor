(function(){
  function normalizeDigits(v){var fa='۰۱۲۳۴۵۶۷۸۹', ar='٠١٢٣٤٥٦٧٨٩'; return (v||'').replace(/[۰-۹]/g,function(d){return fa.indexOf(d)}).replace(/[٠-٩]/g,function(d){return ar.indexOf(d)});}
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('input[name="starts_at_jalali"], input[name="ends_at_jalali"], .hamnoor-jalali-input').forEach(function(input){
      input.setAttribute('inputmode','numeric');
      input.setAttribute('placeholder', input.getAttribute('placeholder') || '1405/01/01 21:30');
      input.addEventListener('input',function(){var v=normalizeDigits(input.value).replace(/[.\-]/g,'/').replace(/[^0-9/: ]/g,''); if(v.length>16){v=v.substring(0,16);} input.value=v;});
    });
    var search=document.querySelector('.hamnoor-text-search');
    if(search){search.addEventListener('input',function(){var q=(search.value||'').toLowerCase().trim();document.querySelectorAll('.hamnoor-text-item').forEach(function(item){var hay=(item.getAttribute('data-search')||item.textContent||'').toLowerCase();item.style.display=(!q||hay.indexOf(q)!==-1)?'':'none';});document.querySelectorAll('.hamnoor-text-group').forEach(function(group){var visible=Array.prototype.some.call(group.querySelectorAll('.hamnoor-text-item'),function(i){return i.style.display!=='none'});group.style.display=visible?'':'none';});});}
  });
})();
