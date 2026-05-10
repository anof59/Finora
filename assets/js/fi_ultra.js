/* ===== FIN. INTELIGENTE — OpenAI Ultra Layer ===== */
/* Carregado após o script principal do index.html    */

// Override da função pFinInteligente para usar o endpoint seguro
window.pFinInteligente = function(){
  var plan = (currentProfile && currentProfile.plan) || 'free';
  var subStatus = (currentProfile && currentProfile.subscription_status) || 'inactive';

  if (plan !== 'ultra' || subStatus !== 'active') {
    document.getElementById('main-content').innerHTML = [
      '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:48px;text-align:center;max-width:480px;margin:0 auto">',
        '<div style="font-size:52px;margin-bottom:16px">🤖✨</div>',
        '<div style="font-size:22px;font-weight:800;color:#0d1b2e;margin-bottom:8px">FIN. INTELIGENTE</div>',
        '<div style="display:inline-block;background:linear-gradient(135deg,#581c87,#7c3aed);color:#fff;font-size:11px;font-weight:800;padding:4px 12px;border-radius:20px;margin-bottom:16px;letter-spacing:.5px">EXCLUSIVO ULTRA</div>',
        '<div style="font-size:14px;color:#6b7280;margin-bottom:24px;line-height:1.7">',
          'Todas as ferramentas avançadas de IA — Chat, Análise, Planejamento,',
          ' Sugestões, Metas, Previsão e mais — estão disponíveis apenas no plano',
          ' <strong>Ultra com assinatura ativa</strong>.',
        '</div>',
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px;text-align:left">',
          '<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px">✨ Chat com IA</div>',
          '<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px">📊 Análise do Mês</div>',
          '<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px">💡 Sugestões de Economia</div>',
          '<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px">🎯 Metas Inteligentes</div>',
          '<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px">📈 Previsão de Saldo</div>',
          '<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px">🔔 Alertas Inteligentes</div>',
        '</div>',
        '<button onclick="navigate(\'planos\', null)" style="padding:12px 28px;background:linear-gradient(135deg,#581c87,#7c3aed);color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:14px;box-shadow:0 4px 14px rgba(124,58,237,.4)">',
          '🚀 Fazer Upgrade para Ultra',
        '</button>',
      '</div>'
    ].join('');
    return;
  }

  // Ultra ativo — renderizar sub-página
  var page = window._currentFiPage || 'fi-chat';
  window._renderFiPage(page);
};

// Variável de controle da sub-página ativa
window._currentFiPage = 'fi-chat';
window._chatHistory   = [];

// ── Interceptar navigate para sub-páginas fi-* ─────────────────────────────
(function(){
  var _orig = window.navigate;
  window.navigate = function(pageId, el){
    if (pageId && pageId.startsWith('fi-')) {
      window._currentFiPage = pageId;
      document.querySelectorAll('.nav-item').forEach(function(n){ n.classList.remove('active'); });
      if (el) el.classList.add('active');
      closeSidebar();
      allCharts.forEach(function(c){ try{ c.destroy(); }catch(e){} });
      allCharts = [];
      document.getElementById('topbar-title').textContent = pageTitles[pageId] || pageId;
      setLoading();
      window.pFinInteligente();
    } else {
      _orig(pageId, el);
    }
  };
})();

// ── Coletar contexto financeiro do usuário ─────────────────────────────────
async function _getFinancialContext(){
  try {
    var r  = await sb.from('transactions').select('*').eq('user_id', currentUser.id);
    var all = r.data || [];
    var now = new Date();
    var ym  = now.getFullYear() + '-' + (String(now.getMonth()+1).padStart(2,'0'));
    var mes = all.filter(function(t){ return String(t.date||'').startsWith(ym); });

    var incomeMonth  = mes.filter(function(t){ return isIncome(t.type);   }).reduce(function(s,t){ return s+parseFloat(t.amount); }, 0);
    var expenseMonth = mes.filter(function(t){ return isExpense(t.type);  }).reduce(function(s,t){ return s+parseFloat(t.amount); }, 0);
    var totalInc     = all.filter(function(t){ return isIncome(t.type);   }).reduce(function(s,t){ return s+parseFloat(t.amount); }, 0);
    var totalExp     = all.filter(function(t){ return isExpense(t.type);  }).reduce(function(s,t){ return s+parseFloat(t.amount); }, 0);
    var balance      = totalInc - totalExp;

    var byCat = {};
    mes.filter(function(t){ return isExpense(t.type); }).forEach(function(t){
      var c = t.category || 'Geral';
      byCat[c] = (byCat[c]||0) + parseFloat(t.amount);
    });
    var topCats = Object.entries(byCat).sort(function(a,b){ return b[1]-a[1]; }).slice(0,5)
      .map(function(e){ return { category: e[0], total: e[1] }; });

    var alerts = [];
    if (expenseMonth > incomeMonth && incomeMonth > 0) alerts.push('Gastos do mês superaram a renda');
    if (balance < 0) alerts.push('Saldo total negativo');

    var metas = [];
    try { metas = JSON.parse(localStorage.getItem('finora_metas_v2') || '[]'); } catch(e){}

    var recent = [].concat(all).sort(function(a,b){ return String(b.date||'').localeCompare(String(a.date||'')); }).slice(0,20);

    return { income_month: incomeMonth, expense_month: expenseMonth, balance: balance,
             top_categories: topCats, goals: metas, alerts: alerts, recent_txs: recent };
  } catch(e) { return {}; }
}

// ── Chamada segura ao backend /api/ai ──────────────────────────────────────
async function _callAI(feature, userPrompt, extraMessages){
  var session = await sb.auth.getSession();
  var token   = session && session.data && session.data.session ? session.data.session.access_token : '';
  var ctx     = await _getFinancialContext();
  var messages = extraMessages || [{ role: 'user', content: userPrompt }];

  try {
    var resp = await fetch('/api/ai/index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ feature: feature, messages: messages, context: ctx })
    });
    if (resp.status === 403) return { error: 'upgrade_required' };
    if (!resp.ok) return { error: 'Erro no servidor (' + resp.status + '). Tente novamente.' };
    var data = await resp.json();
    if (data.error === 'upgrade_required') return { error: 'upgrade_required' };
    return data;
  } catch(e) {
    return { error: 'Falha de conexão. Verifique sua internet.' };
  }
}

// ── Helpers de UI ──────────────────────────────────────────────────────────
function _fiHeader(icon, title, sub){
  return '<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">'
    + '<div style="width:44px;height:44px;background:linear-gradient(135deg,#581c87,#7c3aed);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">' + icon + '</div>'
    + '<div style="flex:1"><div style="font-size:18px;font-weight:800;color:#0d1b2e">' + title + '</div>'
    + '<div style="font-size:11px;color:#7c3aed;font-weight:600;margin-top:2px">✨ Ultra · OpenAI gpt-4o-mini</div></div>'
    + '<div style="background:#f3e8ff;color:#7c3aed;font-size:10px;font-weight:800;padding:4px 10px;border-radius:20px;text-transform:uppercase;white-space:nowrap">' + sub + '</div>'
    + '</div>';
}

function _fiLoading(){
  return '<div style="background:linear-gradient(135deg,#581c87,#7c3aed);border-radius:14px;padding:28px;text-align:center;color:#fff">'
    + '<div style="font-size:32px;margin-bottom:10px">🤖</div>'
    + '<div style="font-size:14px;font-weight:600">Processando com IA...</div>'
    + '<div style="font-size:12px;opacity:.75;margin-top:4px">gpt-4o-mini analisando seus dados financeiros</div>'
    + '</div>';
}

function _fiAiBox(content){
  return '<div style="background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:14px;padding:20px;color:#f8fafc;line-height:1.8;font-size:13px;white-space:pre-wrap">'
    + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;border-bottom:1px solid rgba(255,255,255,.1);padding-bottom:10px">'
    + '<span style="font-size:18px">🤖</span>'
    + '<span style="font-size:12px;font-weight:700;color:#a78bfa">Assistente FFinora Ultra</span>'
    + '</div>'
    + content
    + '</div>';
}

function _fiRefreshBtn(fn){
  return '<button onclick="' + fn + '()" style="margin-top:12px;padding:8px 16px;background:#f3e8ff;color:#7c3aed;border:1px solid #d8b4fe;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer">🔄 Atualizar</button>';
}

// ── Router das sub-páginas ─────────────────────────────────────────────────
window._renderFiPage = function(pageId){
  var map = {
    'fi-chat':          _renderFiChat,
    'fi-analise':       _renderFiAnalise,
    'fi-planejamento':  _renderFiPlanejamento,
    'fi-sugestoes':     _renderFiSugestoes,
    'fi-metas':         _renderFiMetasIA,
    'fi-previsao':      _renderFiPrevisao,
    'fi-alertas':       _renderFiAlertas,
    'fi-investimentos': _renderFiInvestimentos,
    'fi-perfil':        _renderFiPerfil
  };
  var fn = map[pageId] || _renderFiChat;
  fn();
};

/* ───────────────────── CHAT COM IA ──────────────────────────────────────── */
function _renderFiChat(){
  var mc = document.getElementById('main-content');
  var msgs = window._chatHistory.map(_fiChatBubble).join('');
  var empty = window._chatHistory.length === 0
    ? '<div style="text-align:center;color:#9ca3af;font-size:13px;padding:40px 0">👋 Olá! Sou seu assistente financeiro Ultra.<br>Como posso ajudar hoje?</div>'
    : '';

  mc.innerHTML = _fiHeader('✨','Chat com IA','Ativo')
    + '<div id="fi-chat-msgs" style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;min-height:300px;max-height:400px;overflow-y:auto;margin-bottom:12px;display:flex;flex-direction:column;gap:10px">'
    + empty + msgs
    + '</div>'
    + '<div style="display:flex;gap:8px">'
    + '<input id="fi-chat-input" placeholder="Pergunte sobre gastos, metas, orçamento..." style="flex:1;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;outline:none;transition:border-color .2s" onfocus="this.style.borderColor=\'#7c3aed\'" onblur="this.style.borderColor=\'#e5e7eb\'" onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();_fiChatSend();}" />'
    + '<button onclick="_fiChatSend()" style="padding:11px 20px;background:linear-gradient(135deg,#581c87,#7c3aed);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap">Enviar</button>'
    + '</div>'
    + '<button onclick="window._chatHistory=[];_renderFiChat()" style="margin-top:6px;font-size:11px;color:#9ca3af;background:none;border:none;cursor:pointer">🗑️ Limpar conversa</button>';

  var el = document.getElementById('fi-chat-msgs');
  if (el) el.scrollTop = el.scrollHeight;
}

function _fiChatBubble(m){
  var u = m.role === 'user';
  return '<div style="display:flex;flex-direction:' + (u?'row-reverse':'row') + ';gap:8px;align-items:flex-end">'
    + '<div style="width:30px;height:30px;border-radius:50%;background:' + (u?'linear-gradient(135deg,#1565c0,#1e88e5)':'linear-gradient(135deg,#581c87,#7c3aed)') + ';display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">' + (u?'👤':'🤖') + '</div>'
    + '<div style="max-width:75%;background:' + (u?'linear-gradient(135deg,#1565c0,#1e88e5)':'#f8fafc') + ';color:' + (u?'#fff':'#0d1b2e') + ';border:1px solid ' + (u?'transparent':'#e5e7eb') + ';border-radius:' + (u?'14px 14px 4px 14px':'14px 14px 14px 4px') + ';padding:10px 14px;font-size:13px;line-height:1.6;white-space:pre-wrap">' + m.content + '</div>'
    + '</div>';
}

async function _fiChatSend(){
  var inp = document.getElementById('fi-chat-input');
  if (!inp) return;
  var txt = (inp.value || '').trim();
  if (!txt) return;
  inp.value = '';
  inp.disabled = true;

  window._chatHistory.push({ role: 'user', content: txt });
  _renderFiChat();

  var mc = document.getElementById('fi-chat-msgs');
  if (mc) {
    var typing = document.createElement('div');
    typing.id = 'fi-typing';
    typing.innerHTML = '<div style="display:flex;gap:8px;align-items:center">'
      + '<div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#581c87,#7c3aed);display:flex;align-items:center;justify-content:center">🤖</div>'
      + '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px 14px 14px 4px;padding:10px 14px;color:#6b7280;font-size:13px">⏳ Digitando...</div>'
      + '</div>';
    mc.appendChild(typing);
    mc.scrollTop = mc.scrollHeight;
  }

  var result = await _callAI('chat', txt, window._chatHistory.slice(-10));

  var typing = document.getElementById('fi-typing');
  if (typing) typing.remove();
  inp.disabled = false;

  if (result && result.error === 'upgrade_required') {
    alert('Acesso negado. Verifique se sua assinatura Ultra está ativa.');
    return;
  }

  var reply = (result && result.choices && result.choices[0] && result.choices[0].message && result.choices[0].message.content)
    ? result.choices[0].message.content
    : (result && result.error) || 'Não consegui processar. Tente novamente.';

  window._chatHistory.push({ role: 'assistant', content: reply });
  _renderFiChat();
  var el = document.getElementById('fi-chat-msgs');
  if (el) el.scrollTop = el.scrollHeight;
  var inp2 = document.getElementById('fi-chat-input');
  if (inp2) inp2.focus();
}

/* ───────────── FUNÇÕES DE ANÁLISE (padrão: chamar IA + exibir) ─────────── */
async function _renderFiAnalise(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('📊','Análise do Mês','IA Ativa') + _fiLoading();
  var r = await _callAI('analise_mes','Analise detalhadamente os gastos deste mês. Aponte os pontos críticos, o que está bem, oportunidades de melhoria e um score de saúde financeira de 0 a 100. Seja específico com os valores.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('📊','Análise do Mês','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro: ' + (r && r.error !== 'upgrade_required' ? r.error : 'Acesso negado — plano Ultra necessário.') + '</div>'; return; }
  var txt = r.choices[0].message.content;
  mc.innerHTML = _fiHeader('📊','Análise do Mês','IA Ativa') + _fiAiBox(txt) + _fiRefreshBtn('_renderFiAnalise');
}

async function _renderFiPlanejamento(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('🧭','Planejamento Inteligente','IA Ativa') + _fiLoading();
  var r = await _callAI('planejamento','Crie um plano financeiro personalizado para os próximos 3 meses. Inclua metas de economia mensais, estratégias práticas divididas por semana e ações prioritárias para o próximo mês.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('🧭','Planejamento Inteligente','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('🧭','Planejamento Inteligente','IA Ativa') + _fiAiBox(r.choices[0].message.content) + _fiRefreshBtn('_renderFiPlanejamento');
}

async function _renderFiSugestoes(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('💡','Sugestões de Economia','IA Ativa') + _fiLoading();
  var r = await _callAI('sugestoes','Liste 6 sugestões práticas de economia baseadas nos padrões de gasto. Para cada uma, estime a economia mensal em reais e classifique a dificuldade (Fácil/Médio/Difícil).');
  if (!r || r.error) { mc.innerHTML = _fiHeader('💡','Sugestões de Economia','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('💡','Sugestões de Economia','IA Ativa') + _fiAiBox(r.choices[0].message.content) + _fiRefreshBtn('_renderFiSugestoes');
}

async function _renderFiMetasIA(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('🎯','Metas Inteligentes','IA Ativa') + _fiLoading();
  var r = await _callAI('metas','Analise as metas financeiras cadastradas. Para cada meta avalie viabilidade, sugira aporte mensal ideal e indique se o prazo é realista. Proponha também 2 novas metas adequadas ao perfil.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('🎯','Metas Inteligentes','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('🎯','Metas Inteligentes','IA Ativa') + _fiAiBox(r.choices[0].message.content) + _fiRefreshBtn('_renderFiMetasIA');
}

async function _renderFiPrevisao(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('📈','Previsão de Saldo','IA Ativa') + _fiLoading();
  var r = await _callAI('previsao','Com base no histórico financeiro, faça uma previsão detalhada do saldo para 30, 60 e 90 dias. Apresente cenários otimista, realista e pessimista com valores estimados e riscos de cada.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('📈','Previsão de Saldo','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('📈','Previsão de Saldo','IA Ativa') + _fiAiBox(r.choices[0].message.content) + _fiRefreshBtn('_renderFiPrevisao');
}

async function _renderFiAlertas(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('🔔','Alertas Inteligentes','IA Ativa') + _fiLoading();
  var r = await _callAI('alertas','Identifique todos os alertas financeiros importantes. Categorize em Urgente 🔴, Atenção 🟡 e Monitorar 🟢. Para cada alerta dê uma ação corretiva específica com prazo sugerido.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('🔔','Alertas Inteligentes','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('🔔','Alertas Inteligentes','IA Ativa') + _fiAiBox(r.choices[0].message.content) + _fiRefreshBtn('_renderFiAlertas');
}

async function _renderFiInvestimentos(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('💼','Investimentos IA','IA Ativa') + _fiLoading();
  var r = await _callAI('investimentos','Analise a situação patrimonial: 1) Avalie a carteira atual; 2) Sugira diversificação adequada ao perfil; 3) Quanto do saldo livre deveria ser investido e em quê; 4) Alertas de risco de concentração. Não prometa retornos garantidos.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('💼','Investimentos IA','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('💼','Investimentos IA','IA Ativa')
    + _fiAiBox(r.choices[0].message.content)
    + '<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:10px 14px;font-size:11px;color:#92400e;margin-top:12px">⚠️ Conteúdo educativo. Consulte um profissional certificado antes de investir.</div>'
    + _fiRefreshBtn('_renderFiInvestimentos');
}

async function _renderFiPerfil(){
  var mc = document.getElementById('main-content');
  mc.innerHTML = _fiHeader('👤','Perfil Financeiro','IA Ativa') + _fiLoading();
  var r = await _callAI('perfil','Crie um perfil financeiro completo: 1) Tipo de perfil (nível 1-5 de saúde financeira); 2) Pontos fortes; 3) Vulnerabilidades detectadas; 4) Padrões de comportamento; 5) Plano de desenvolvimento financeiro com 90 dias de ações.');
  if (!r || r.error) { mc.innerHTML = _fiHeader('👤','Perfil Financeiro','IA Ativa') + '<div style="color:#dc2626;padding:20px">Erro ao processar.</div>'; return; }
  mc.innerHTML = _fiHeader('👤','Perfil Financeiro','IA Ativa') + _fiAiBox(r.choices[0].message.content) + _fiRefreshBtn('_renderFiPerfil');
}
