/*!
 * FFinora - Onboarding Premium
 * --------------------------------------------------------------
 * Fluxo: usuario PRO/ULTRA chega em /obrigado?payment=success&session_id=...
 * Aqui:
 *   1. Lemos session_id da URL (nunca colocamos no DOM).
 *   2. Buscamos o email do cliente na Stripe via /api/stripe/session.php
 *      (o email fica APENAS em variavel JS, nunca renderizado no DOM,
 *       nunca em input hidden).
 *   3. Mostramos somente: Senha + Confirmar senha + "Ativar minha conta".
 *   4. Criamos a conta no Supabase usando esse email + a senha digitada.
 *
 * Este arquivo NAO altera:
 *   - app.html
 *   - /login (fluxo Free)
 *   - dashboard / sidebar
 *   - Supabase RLS
 *   - webhook
 * -------------------------------------------------------------- */
(function () {
  'use strict';

  // ---- Helpers de UI ---------------------------------------------------
  function $(id) { return document.getElementById(id); }
  function show(el) { if (el) el.classList.remove('hidden'); }
  function hide(el) { if (el) el.classList.add('hidden'); }
  function setMsg(kind, text) {
    var box = $('premium-msg');
    if (!box) return;
    box.className = 'activation-msg ' + (kind || '');
    box.textContent = text || '';
  }

  // ---- 1. Detectar fluxo premium pela URL ------------------------------
  var params  = new URLSearchParams(window.location.search);
  var payment = (params.get('payment') || '').toLowerCase();
  var sessId  = params.get('session_id') || '';

  // Se nao for fluxo premium pos-pagamento, nao faz nada: a pagina
  // continua exibindo o bloco padrao (botoes "Entrar no app" / "Criar conta").
  if (payment !== 'success' || !sessId) {
    return;
  }

  // ---- 2. Trocar o layout para "definir senha" -------------------------
  hide($('default-block'));
  hide($('subtitle-default'));
  show($('subtitle-premium'));
  show($('premium-form'));

  // ---- 3. Email da Stripe vive APENAS aqui dentro ----------------------
  var signupEmail = null;       // <- nao vai pro DOM, nao vai pro hidden
  var supabaseClient = null;    // cliente Supabase carregado on-demand

  // Carrega o SDK do Supabase via CDN (mesmo padrao do /login)
  function loadSupabaseSdk() {
    return new Promise(function (resolve, reject) {
      if (window.supabase && window.supabase.createClient) return resolve();
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2';
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Falha ao carregar Supabase SDK')); };
      document.head.appendChild(s);
    });
  }

  // Reaproveita SB_URL e SB_KEY publicos definidos no /login (anon key,
  // segura por design do Supabase). Assim nao precisamos duplicar nada
  // nem mexer no /login.
  function getSupabaseConfig() {
    return fetch('/login', { credentials: 'omit' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var mUrl = html.match(/SB_URL\s*=\s*['"]([^'"]+)['"]/);
        var mKey = html.match(/SB_KEY\s*=\s*['"]([^'"]+)['"]/);
        if (!mUrl || !mKey) throw new Error('Config Supabase nao encontrada');
        return { url: mUrl[1], key: mKey[1] };
      });
  }

  function getSupabaseClient() {
    if (supabaseClient) return Promise.resolve(supabaseClient);
    return loadSupabaseSdk()
      .then(getSupabaseConfig)
      .then(function (cfg) {
        supabaseClient = window.supabase.createClient(cfg.url, cfg.key, {
          auth: { persistSession: true, autoRefreshToken: true, storage: window.localStorage }
        });
        return supabaseClient;
      });
  }

  // ---- 4. Buscar email do cliente na Stripe ----------------------------
  function fetchStripeEmail() {
    return fetch('/api/stripe/session.php?session_id=' + encodeURIComponent(sessId), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'omit'
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.email) throw new Error('Sessao Stripe sem email');
        signupEmail = String(data.email).trim().toLowerCase();
        return signupEmail;
      });
  }

  // ---- 5. Submit do formulario -----------------------------------------
  var form    = $('premium-form');
  var pass1El = $('premium-pass');
  var pass2El = $('premium-pass2');
  var submit  = $('premium-submit');

  if (!form || !pass1El || !pass2El || !submit) return;

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    setMsg('', '');

    var pass1 = pass1El.value || '';
    var pass2 = pass2El.value || '';

    if (pass1.length < 6) {
      return setMsg('error', 'A senha deve ter pelo menos 6 caracteres.');
    }
    if (pass1 !== pass2) {
      return setMsg('error', 'As senhas nao conferem.');
    }

    submit.disabled = true;
    setMsg('info', 'Ativando sua conta...');

    // Garante que ja temos o email da Stripe (se ainda nao buscamos).
    var emailPromise = signupEmail
      ? Promise.resolve(signupEmail)
      : fetchStripeEmail();

    emailPromise
      .then(function (email) {
        return getSupabaseClient().then(function (sb) {
          return sb.auth.signUp({
            email: email,
            password: pass1,
            options: {
              emailRedirectTo: window.location.origin + '/app.html'
            }
          });
        });
      })
      .then(function (res) {
        if (res && res.error) {
          var msg = String(res.error.message || '');
          // Conta ja existe (ex: usuario abriu /obrigado duas vezes) -> tenta logar
          if (/already|registered|exists/i.test(msg)) {
            return getSupabaseClient().then(function (sb) {
              return sb.auth.signInWithPassword({ email: signupEmail, password: pass1 });
            }).then(function (login) {
              if (login.error) throw new Error(login.error.message);
              return { _logged: true };
            });
          }
          throw new Error(msg);
        }
        return res;
      })
      .then(function () {
        setMsg('success', 'Conta ativada! Redirecionando...');
        setTimeout(function () { window.location.href = '/app.html'; }, 800);
      })
      .catch(function (err) {
        submit.disabled = false;
        setMsg('error', 'Nao foi possivel ativar: ' + (err && err.message ? err.message : err));
      });
  });

  // Pre-busca o email assim que a pagina carrega, para acelerar o submit.
  fetchStripeEmail().catch(function (err) {
    setMsg('error', 'Nao conseguimos confirmar seu pagamento agora. Tente novamente ou fale com contato@ffinora.com.br');
    if (submit) submit.disabled = true;
    console.error('[onboarding-premium] fetchStripeEmail:', err);
  });
})();
