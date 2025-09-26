(function(){
  function ready(fn){ if(document.readyState!='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function(){
    const provider = document.getElementById('aichat-wa-provider');
    const bot = document.getElementById('aichat-wa-bot');
    if(!provider || !bot) return;
    function loadBots(){
      const svc = provider.value;
      bot.innerHTML = '<option>Cargando...</option>';
      fetch((window.ajaxurl || '') + '?action=aichat_wa_list_bots&service=' + encodeURIComponent(svc))
        .then(r=>r.json())
        .then(j=>{
          bot.innerHTML='';
          const cur = bot.getAttribute('data-current') || '';
            if(j.success && Array.isArray(j.data)){
              j.data.forEach(it=>{
                const o=document.createElement('option');
                o.value=it.value; o.textContent=it.label;
                if(cur && cur===it.value) o.selected=true;
                bot.appendChild(o);
              });
              if(!bot.value && bot.options.length) bot.selectedIndex=0;
            } else {
              bot.innerHTML='<option value="">(sin bots)</option>';
            }
        })
        .catch(()=>{ bot.innerHTML='<option value="">Error</option>'; });
    }
    provider.addEventListener('change', ()=>{ bot.setAttribute('data-current',''); loadBots(); });
    // Auto load if editing and provider not aichat (ensures AI Engine bots appear)
    if(provider.value && bot.options.length<=1){ loadBots(); }
  });
})();