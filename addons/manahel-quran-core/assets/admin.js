jQuery(function($){
  const $btn=$('#mqc-import'), $msg=$('#mqc-message'), $bar=$('#mqc-bar'), $count=$('#mqc-verses');
  function batch(){
    $btn.prop('disabled',true); $msg.text('در حال واردسازی...');
    $.post(MQCAdmin.ajax,{action:'mqc_import_batch',nonce:MQCAdmin.nonce,limit:MQCAdmin.batch}).done(function(r){
      if(!r.success){$msg.text(r.data&&r.data.message?r.data.message:'خطا در واردسازی');$btn.prop('disabled',false);return;}
      const d=r.data, pct=Math.min(100,(d.offset/d.total)*100);
      $count.text(d.offset); $bar.css('width',pct+'%'); $msg.text(d.done?'واردسازی ۶۲۳۶ آیه کامل شد.':('واردسازی '+d.offset+' از '+d.total));
      if(!d.done){batch();} else {$btn.prop('disabled',true);}
    }).fail(function(xhr){$msg.text('ارتباط با سرور قطع شد. '+(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:''));$btn.prop('disabled',false);});
  }
  $btn.on('click',batch);
  $('#mqc-reset').on('click',function(){if(!confirm('تمام داده‌های واردشده آزمایشی پاک شوند؟'))return;$.post(MQCAdmin.ajax,{action:'mqc_reset_import',nonce:MQCAdmin.nonce}).done(function(r){if(r.success){location.reload();}else alert(r.data.message);});});
  $('#mqc-test').on('click',function(){
    $('#mqc-test-result').text('در حال دریافت...');
    $.post(MQCAdmin.ajax,{action:'mqc_test_verse',nonce:MQCAdmin.nonce,surah:$('#mqc-test-surah').val(),ayah:$('#mqc-test-ayah').val()}).done(function(r){
      if(!r.success){$('#mqc-test-result').text(r.data.message);return;}
      $('#mqc-test-result').html('<p class="mqc-ar">'+$('<div>').text(r.data.text_arabic).html()+'</p><p>'+$('<div>').text(r.data.translation_makarem).html()+'</p><small>'+r.data.verse_key+' | شناسه '+r.data.global_index+'</small>');
    });
  });
});
