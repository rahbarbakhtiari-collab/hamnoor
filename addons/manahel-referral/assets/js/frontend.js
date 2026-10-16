(function(){
'use strict';
function target(button){return document.getElementById(button.getAttribute('data-target')||'');}
function status(button){var card=button.closest('.manahel-referral-invite');return card?card.querySelector('.mrf-referral-copy-status'):null;}
function announce(button,text){var el=status(button);if(el)el.textContent=text;}
function copy(button){var el=target(button);if(!el)return Promise.resolve(false);var text=el.value||'';if(navigator.clipboard&&navigator.clipboard.writeText){return navigator.clipboard.writeText(text).then(function(){return true;},function(){return false;});}el.select();try{return Promise.resolve(!!document.execCommand('copy'));}catch(e){return Promise.resolve(false);}}
document.addEventListener('click',function(e){
 var copyButton=e.target.closest('.mrf-referral-copy');
 if(copyButton){copy(copyButton).then(function(ok){announce(copyButton,ok?'لینک دعوت کپی شد.':'کپی خودکار انجام نشد؛ لینک را دستی کپی کنید.');});return;}
 var shareButton=e.target.closest('.mrf-referral-share');
 if(!shareButton)return;
 var el=target(shareButton);if(!el)return;
 var url=el.value||'';var title=shareButton.getAttribute('data-share-title')||'هم‌نور';var text=shareButton.getAttribute('data-share-text')||'';
 if(navigator.share){navigator.share({title:title,text:text,url:url}).then(function(){announce(shareButton,'پنجره اشتراک‌گذاری باز شد.');},function(err){if(err&&err.name==='AbortError'){announce(shareButton,'اشتراک‌گذاری لغو شد.');return;}copy(shareButton).then(function(ok){announce(shareButton,ok?'اشتراک‌گذاری در دسترس نبود؛ لینک کپی شد.':'اشتراک‌گذاری در دسترس نیست؛ لینک را دستی کپی کنید.');});});}
 else{copy(shareButton).then(function(ok){announce(shareButton,ok?'اشتراک‌گذاری مستقیم در دسترس نیست؛ لینک کپی شد.':'اشتراک‌گذاری در دسترس نیست؛ لینک را دستی کپی کنید.');});}
});
})();
