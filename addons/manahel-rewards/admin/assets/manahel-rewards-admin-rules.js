(function(){
  'use strict';
  function $(sel, root){ return (root || document).querySelector(sel); }
  function n(id){ var el=$(id); return el ? Math.max(0, parseInt(el.value || '0',10) || 0) : 0; }
  function faNum(value){ try { return new Intl.NumberFormat('fa-IR').format(value); } catch(e){ return String(value); } }
  function cooldownLabel(form){
    var preset=$('#mr_edit_cooldown_preset', form); if(!preset) return '';
    var labels={none:'بدون فاصله',hour:'۱ ساعت',day:'۱ روز',week:'۷ روز',month:'۳۰ روز'};
    if(preset.value!=='custom') return labels[preset.value] || '';
    var value=parseInt(($('[name="cooldown_custom_value"]',form)||{}).value||'1',10)||1;
    var unit=($('[name="cooldown_custom_unit"]',form)||{}).value||'hours';
    var names={seconds:'ثانیه',minutes:'دقیقه',hours:'ساعت',days:'روز'};
    return faNum(value)+' '+(names[unit]||'ساعت');
  }
  function refreshSummary(form){
    var box=$('#mr-live-summary'); if(!box) return;
    var action=box.getAttribute('data-action-label')||'انجام فعالیت';
    var points=n('#mr_edit_points');
    var da=n('#mr_edit_daily_awards'), dp=n('#mr_edit_daily_points'), ma=n('#mr_edit_monthly_awards'), mp=n('#mr_edit_monthly_points');
    var parts=['هر بار '+action+': '+faNum(points)+' امتیاز'];
    if(da) parts.push('حداکثر '+faNum(da)+' بار در روز');
    if(dp) parts.push('حداکثر '+faNum(dp)+' امتیاز در روز');
    if(ma) parts.push('حداکثر '+faNum(ma)+' بار در ماه');
    if(mp) parts.push('حداکثر '+faNum(mp)+' امتیاز در ماه');
    var cd=cooldownLabel(form); if(cd && cd!=='بدون فاصله') parts.push('فاصله دریافت مجدد: '+cd);
    if(parts.length===1) parts.push('بدون سقف روزانه یا ماهانه');
    box.textContent=parts.join('؛ ')+'.';
  }
  function refreshCooldown(form){
    var preset=$('#mr_edit_cooldown_preset',form), custom=$('#mr-custom-cooldown',form); if(!preset||!custom)return;
    custom.hidden=preset.value!=='custom';
  }
  document.addEventListener('DOMContentLoaded',function(){
    var form=$('.mr-simple-form'); if(!form)return;
    refreshCooldown(form); refreshSummary(form);
    form.addEventListener('input',function(){ refreshCooldown(form); refreshSummary(form); });
    form.addEventListener('change',function(){ refreshCooldown(form); refreshSummary(form); });
  });
})();
