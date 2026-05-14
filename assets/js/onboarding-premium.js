document.addEventListener('DOMContentLoaded', async () => {
  const form = document.getElementById('onboarding-premium-form');
  const btn = document.getElementById('btn-ativar');
  const errorDiv = document.getElementById('onboarding-error');
  
  const urlParams = new URLSearchParams(window.location.search);
  const sessionId = urlParams.get('session_id');
  
  if (!sessionId) {
    showError("Sessão de pagamento não encontrada. Verifique o link recebido.");
    form.querySelector('button').disabled = true;
    return;
  }

  let signupEmail = '';

  // 1. Buscar dados da sessão no backend para pegar o e-mail
  try {
    const res = await fetch(`/api/stripe/session.php?session_id=${encodeURIComponent(sessionId)}`);
    if (!res.ok) throw new Error('Falha ao validar sessão');
    
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    
    signupEmail = data.email;
    if (!signupEmail) throw new Error('E-mail não encontrado na sessão');
    
  } catch (err) {
    console.error(err);
    showError("Erro ao validar pagamento. Por favor, contate o suporte.");
    form.querySelector('button').disabled = true;
    return;
  }

  // 2. Inicializar Supabase
  const supaUrl = window.process.env.NEXT_PUBLIC_SUPABASE_URL;
  const supaKey = window.process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY;
  const supabase = window.supabase.createClient(supaUrl, supaKey);

  // 3. Ao enviar o formulário, criar conta
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorDiv.style.display = 'none';
    
    const senha = document.getElementById('signup-senha').value;
    const confSenha = document.getElementById('signup-confirmar-senha').value;
    
    if (senha !== confSenha) {
      showError("As senhas não coincidem.");
      return;
    }
    
    if (senha.length < 6) {
      showError("A senha deve ter pelo menos 6 caracteres.");
      return;
    }

    setLoading(true);

    try {
      const { data, error } = await supabase.auth.signUp({
        email: signupEmail,
        password: senha
      });

      if (error) {
        if (error.message.includes('User already registered')) {
          throw new Error("Este e-mail já possui conta. Por favor, acesse a página de login.");
        }
        throw error;
      }

      // Conta criada com sucesso, redirecionar para o app
      window.location.href = '/app.html';
      
    } catch (err) {
      console.error(err);
      showError(err.message || "Erro ao ativar conta. Tente novamente.");
      setLoading(false);
    }
  });

  function showError(msg) {
    errorDiv.textContent = msg;
    errorDiv.style.display = 'block';
  }

  function setLoading(isLoading) {
    if (isLoading) {
      btn.disabled = true;
      btn.innerHTML = 'Ativando...';
      btn.style.opacity = '0.7';
    } else {
      btn.disabled = false;
      btn.innerHTML = `Ativar minha conta
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>`;
      btn.style.opacity = '1';
    }
  }
});
