(function(){
  function ready(fn){ if(document.readyState!='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function(){
    const provider = document.getElementById('aichat-wa-provider');
    const bot = document.getElementById('aichat-wa-bot');
    if(!provider || !bot) return;
    function loadBots(){
      const svc = provider.value;
      bot.innerHTML = '<option>Loading...</option>';
      fetch((window.ajaxurl || '') + '?action=aichat_connect_list_bots&service=' + encodeURIComponent(svc))
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
              bot.innerHTML='<option value="">(no bots)</option>';
            }
        })
        .catch(()=>{ bot.innerHTML='<option value="">Error</option>'; });
    }
    provider.addEventListener('change', ()=>{ bot.setAttribute('data-current',''); loadBots(); });
    // Auto load if editing and provider not aichat (ensures AI Engine bots appear)
    if(provider.value && bot.options.length<=1){ loadBots(); }

    // Channel-aware UI tweaks
    const channel = document.getElementById('aichat-wa-channel');
    const endpointInput = document.querySelector('input[name="phone"]');
    const tokenInput = document.querySelector('input[name="access_token"]');
    const formEl = document.querySelector('form[action*="aichat_connect_save_number"]');
    let endpointErrorEl = null;
    function ensureEndpointErrorEl(){
      if(endpointErrorEl) return endpointErrorEl;
      if(!endpointInput) return null;
      endpointErrorEl = document.createElement('div');
      endpointErrorEl.className = 'invalid-feedback d-block';
      endpointErrorEl.style.display = 'none';
      endpointInput.parentElement.appendChild(endpointErrorEl);
      return endpointErrorEl;
    }
    function validateEndpoint(){
      if(!endpointInput || !channel) return true;
      const chan = channel.value || 'whatsapp';
      const val = (endpointInput.value || '').trim();
      // For Telegram, enforce safe endpoint: letters, numbers, dash, underscore only; no slashes/spaces.
      if(chan === 'telegram'){
        const ok = /^[A-Za-z0-9_-]+$/.test(val);
        const err = ensureEndpointErrorEl();
        if(!ok){
          endpointInput.classList.add('is-invalid');
          if(err){ err.textContent = 'Usa solo letras, números, guion y guion bajo (sin espacios ni "/").'; err.style.display='block'; }
          return false;
        } else {
          endpointInput.classList.remove('is-invalid');
          if(err){ err.textContent=''; err.style.display='none'; }
        }
      } else {
        // Clear error for other channels
        endpointInput.classList.remove('is-invalid');
        if(endpointErrorEl){ endpointErrorEl.textContent=''; endpointErrorEl.style.display='none'; }
      }
      return true;
    }
    function updateChannelUI(){
      if(!channel || !endpointInput || !tokenInput) return;
      const colEndpoint = endpointInput.closest('.col-md-4, .col-md-3, .col-12');
      const colToken = tokenInput.closest('.col-md-8, .col-12');
      const epHelp = colEndpoint ? colEndpoint.querySelector('.form-text') : null;
      const tokHelp = colToken ? colToken.querySelector('.form-text') : null;
      const chan = channel.value || 'whatsapp';
      // Show/hide channel-specific rows
      document.querySelectorAll('[data-channel-only]')?.forEach(el=>{
        const only = el.getAttribute('data-channel-only');
        el.style.display = (only===chan) ? '' : 'none';
      });
      if(chan === 'telegram'){
        endpointInput.placeholder = 'telegram-bot (free text)';
        if(epHelp) epHelp.textContent = 'Free label for your Telegram bot; token goes below.';
        tokenInput.placeholder = 'Telegram Bot Token (e.g. 123456:ABC...)';
        if(tokHelp) tokHelp.textContent = 'Required: Telegram Bot Token for this mapping.';
        tokenInput.required = true;
        // Update Telegram webhook preview with endpoint
        const setW = document.getElementById('aichat-tg-setwebhook');
        if(setW){
          const apiBase = setW.getAttribute('data-tg-api-base') || 'https://api.telegram.org/bot';
          const tgBase = setW.getAttribute('data-tg-base') || '';
          const tok = (tokenInput.value || '').trim() || 'botTOKEN';
          const ep = (endpointInput.value || '').trim();
          const webhook = tgBase + encodeURIComponent(ep);
          setW.textContent = apiBase + tok + '/setWebhook?url=' + webhook;
        }
        validateEndpoint();
      } else {
        endpointInput.placeholder = '123456789012345 (phone_number_id)';
        if(epHelp) epHelp.textContent = 'Use your Business Phone Number ID (Meta Cloud API).';
        tokenInput.placeholder = 'EAAG...';
        if(tokHelp) tokHelp.textContent = 'Overrides global Graph access token for this mapping.';
        tokenInput.required = false;
      }
    }
    const ch = document.getElementById('aichat-wa-channel');
    if(ch){ ch.addEventListener('change', updateChannelUI); updateChannelUI(); }
    // Live update Telegram webhook as Endpoint ID is typed/changed
    if(endpointInput){
      ['input','change','blur'].forEach(evt=>{
        endpointInput.addEventListener(evt, ()=>{
          const chan = channel ? channel.value : 'whatsapp';
          if(chan !== 'telegram') return;
          const setW = document.getElementById('aichat-tg-setwebhook');
          if(!setW) return;
          const apiBase = setW.getAttribute('data-tg-api-base') || 'https://api.telegram.org/bot';
          const tgBase = setW.getAttribute('data-tg-base') || '';
          const tok = (tokenInput.value || '').trim() || 'botTOKEN';
          const ep = (endpointInput.value || '').trim();
          const webhook = tgBase + encodeURIComponent(ep);
          setW.textContent = apiBase + tok + '/setWebhook?url=' + webhook;
          validateEndpoint();
        });
      });
    }
    if(tokenInput){
      ['input','change','blur'].forEach(evt=>{
        tokenInput.addEventListener(evt, ()=>{
          const chan = channel ? channel.value : 'whatsapp';
          if(chan !== 'telegram') return;
          const setW = document.getElementById('aichat-tg-setwebhook');
          if(!setW) return;
          const apiBase = setW.getAttribute('data-tg-api-base') || 'https://api.telegram.org/bot';
          const tgBase = setW.getAttribute('data-tg-base') || '';
          const tok = (tokenInput.value || '').trim() || 'botTOKEN';
          const ep = (endpointInput.value || '').trim();
          const webhook = tgBase + encodeURIComponent(ep);
          setW.textContent = apiBase + tok + '/setWebhook?url=' + webhook;
        });
      });
    }
    if(formEl){
      formEl.addEventListener('submit', function(e){
        const chan = channel ? channel.value : 'whatsapp';
        if(chan === 'telegram' && !validateEndpoint()){
          e.preventDefault();
          e.stopPropagation();
        }
      });
    }

    // Copy buttons (for code blocks and inputs)
    document.querySelectorAll('[data-copy-target-id]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.getAttribute('data-copy-target-id');
        const el = document.getElementById(id);
        if(!el) return;
        const text = (el.textContent || '').trim();
        navigator.clipboard.writeText(text).then(()=>{
          btn.classList.add('btn-success');
          setTimeout(()=>btn.classList.remove('btn-success'), 800);
        }).catch(()=>{});
      });
    });
    document.querySelectorAll('[data-copy-input-name]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const name = btn.getAttribute('data-copy-input-name');
        const inp = document.querySelector('input[name="'+name+'"]');
        if(!inp) return;
        navigator.clipboard.writeText(inp.value || '').then(()=>{
          btn.classList.add('btn-success');
          setTimeout(()=>btn.classList.remove('btn-success'), 800);
        }).catch(()=>{});
      });
    });

    // Toggle token visibility
    const toggleTok = document.getElementById('aichat-toggle-token-visibility');
    if(toggleTok && tokenInput){
      toggleTok.addEventListener('click', ()=>{
        if(tokenInput.type === 'password'){
          tokenInput.type = 'text';
        } else {
          tokenInput.type = 'password';
        }
      });
      // default to password type to avoid mostrarse
      tokenInput.type = 'password';
    }

    // Generate verify token
    const genBtn = document.getElementById('aichat-generate-verify-token');
    if(genBtn){
      genBtn.addEventListener('click', ()=>{
        const v = Math.random().toString(36).slice(2,10) + '-' + Math.random().toString(36).slice(2,10);
        const inp = document.querySelector('input[name="verify_token"]');
        if(inp){ inp.value = v; }
      });
    }

    // Open setWebhook link when both token and endpoint present
    function refreshOpenSetWebhook(){
      const open = document.getElementById('aichat-tg-open-setwebhook');
      const code = document.getElementById('aichat-tg-setwebhook');
      const chan = channel ? channel.value : 'whatsapp';
      if(!open || !code || chan !== 'telegram'){ if(open) open.style.display='none'; return; }
      const txt = (code.textContent || '').trim();
      if(txt && tokenInput && (tokenInput.value||'').trim() && endpointInput && (endpointInput.value||'').trim()){
        open.href = txt; open.style.display='inline-block';
      } else {
        open.href = '#'; open.style.display='none';
      }
    }
    refreshOpenSetWebhook();
    ['input','change','blur'].forEach(evt=>{
      if(tokenInput) tokenInput.addEventListener(evt, refreshOpenSetWebhook);
      if(endpointInput) endpointInput.addEventListener(evt, refreshOpenSetWebhook);
      if(channel) channel.addEventListener(evt, refreshOpenSetWebhook);
    });
  });
})();