<?php
/**
 * FFinora - setup.php
 * --------------------------------------------------------------
 * Assistente de configuração segura das variáveis de produção.
 * Escreve as chaves digitadas no arquivo `secrets.php`.
 * --------------------------------------------------------------
 */

$secretsFile = __DIR__ . '/secrets.php';
$alreadyConfigured = file_exists($secretsFile);
$error = '';
$success = false;

// Tenta pré-carregar do .env.local para facilitar o teste local
$env = [];
$envFileLocal = __DIR__ . '/.env.local';
if (file_exists($envFileLocal)) {
    $lines = file($envFileLocal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $val = trim($value);
            $val = preg_replace('/^[\'"]|[\'"]$/', '', $val);
            $env[trim($name)] = $val;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($alreadyConfigured) {
        $error = 'O sistema já está configurado. Para reconfigurar, exclua o arquivo `secrets.php` no servidor.';
    } else {
        $stripeSecret   = trim($_POST['stripe_secret'] ?? '');
        $openaiKey      = trim($_POST['openai_key'] ?? '');
        $supabaseUrl    = trim($_POST['supabase_url'] ?? '');
        $supabaseAnon   = trim($_POST['supabase_anon'] ?? '');
        $supabaseService= trim($_POST['supabase_service'] ?? '');
        $appUrl         = trim($_POST['app_url'] ?? 'https://ffinora.com.br');

        if (empty($stripeSecret) || empty($openaiKey) || empty($supabaseUrl) || empty($supabaseAnon)) {
            $error = 'Por favor, preencha todos os campos obrigatórios.';
        } else {
            $content = "<?php\n"
                     . "/**\n"
                     . " * FFinora - Credenciais de Produção\n"
                     . " * Gerado automaticamente via setup.php\n"
                     . " */\n\n"
                     . "return [\n"
                     . "    'STRIPE_SECRET_KEY' => '" . addslashes($stripeSecret) . "',\n"
                     . "    'OPENAI_API_KEY' => '" . addslashes($openaiKey) . "',\n"
                     . "    'NEXT_PUBLIC_SUPABASE_URL' => '" . addslashes($supabaseUrl) . "',\n"
                     . "    'NEXT_PUBLIC_SUPABASE_ANON_KEY' => '" . addslashes($supabaseAnon) . "',\n"
                     . "    'SUPABASE_SERVICE_ROLE_KEY' => '" . addslashes($supabaseService) . "',\n"
                     . "    'NEXT_PUBLIC_APP_URL' => '" . addslashes($appUrl) . "',\n"
                     . "];\n";

            if (file_put_contents($secretsFile, $content)) {
                $success = true;
                $alreadyConfigured = true;
            } else {
                $error = 'Erro ao criar o arquivo secrets.php. Verifique as permissões de gravação da pasta raiz.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuração do Servidor - FFinora</title>
    <link rel="icon" type="image/jpeg" href="/assets/images/favicon.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b1329;
            --card-bg: rgba(28, 37, 65, 0.65);
            --border: rgba(255, 255, 255, 0.08);
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #4f46e5;
            --primary-glow: rgba(79, 70, 229, 0.4);
            --green: #10b981;
            --red: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
            overflow-x: hidden;
            position: relative;
        }

        /* Efeitos de fundo */
        body::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%);
            top: -100px;
            right: -100px;
            z-index: 0;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.2) 0%, transparent 70%);
            bottom: -150px;
            left: -150px;
            z-index: 0;
            pointer-events: none;
        }

        .container {
            width: 100%;
            max-width: 580px;
            z-index: 10;
            position: relative;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            backdrop-filter: blur(16px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .logo-area {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo-img {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            object-fit: cover;
            margin-bottom: 16px;
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.4);
        }

        h1 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .subtitle {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            line-height: 1.5;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #a7f3d0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input {
            width: 100%;
            padding: 12px 16px;
            background: rgba(11, 19, 41, 0.5);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 14px;
            outline: none;
            transition: all 0.25s ease;
        }

        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), #6366f1);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            margin-top: 10px;
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--primary-glow);
            filter: brightness(1.1);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .configured-box {
            text-align: center;
            padding: 20px 0 10px;
        }

        .configured-box p {
            color: var(--text-muted);
            font-size: 15px;
            margin-bottom: 24px;
        }

        .btn-home {
            display: inline-block;
            padding: 12px 28px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .btn-home:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
        }

        .helper-text {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 5px;
            display: block;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="logo-area">
            <img src="/assets/images/favicon.jpg" alt="FFinora" class="logo-img">
            <h1>Configuração do Servidor</h1>
            <p class="subtitle">Insira as credenciais de produção para ativar o Stripe e a Inteligência Artificial.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <span>✅</span> Configuração salva com sucesso! O arquivo <strong>secrets.php</strong> foi gerado na raiz.
            </div>
        <?php endif; ?>

        <?php if ($alreadyConfigured && !$success): ?>
            <div class="configured-box">
                <div class="alert alert-success" style="justify-content: center; font-weight: 600;">
                    <span>🛡️</span> FFinora já está configurado!
                </div>
                <p>As chaves estão salvas e seguras em <code>secrets.php</code>.</p>
                <a href="/index.html" class="btn-home">Ir para o Site Inicial</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="stripe_secret">Stripe Secret Key (sk_live... ou sk_test...)</label>
                    <input type="password" id="stripe_secret" name="stripe_secret" required 
                           value="<?php echo htmlspecialchars($env['STRIPE_SECRET_KEY'] ?? ''); ?>" placeholder="sk_live_...">
                    <span class="helper-text">Usada para criar as sessões de checkout e receber pagamentos.</span>
                </div>

                <div class="form-group">
                    <label for="openai_key">OpenAI API Key (sk-proj-...)</label>
                    <input type="password" id="openai_key" name="openai_key" required 
                           value="<?php echo htmlspecialchars($env['OPENAI_API_KEY'] ?? ''); ?>" placeholder="sk-proj-...">
                    <span class="helper-text">Usada pelo assistente financeiro IA Ultra.</span>
                </div>

                <div class="form-group">
                    <label for="supabase_url">Supabase URL</label>
                    <input type="text" id="supabase_url" name="supabase_url" required 
                           value="<?php echo htmlspecialchars($env['NEXT_PUBLIC_SUPABASE_URL'] ?? ''); ?>" placeholder="https://xxxx.supabase.co">
                </div>

                <div class="form-group">
                    <label for="supabase_anon">Supabase Anon Key</label>
                    <input type="password" id="supabase_anon" name="supabase_anon" required 
                           value="<?php echo htmlspecialchars($env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? ''); ?>" placeholder="eyJhbGci...">
                </div>

                <div class="form-group">
                    <label for="supabase_service">Supabase Service Role Key (Opcional)</label>
                    <input type="password" id="supabase_service" name="supabase_service" 
                           value="<?php echo htmlspecialchars($env['SUPABASE_SERVICE_ROLE_KEY'] ?? ''); ?>" placeholder="eyJhbGci...">
                    <span class="helper-text">Necessário apenas para rotinas administrativas backend.</span>
                </div>

                <div class="form-group">
                    <label for="app_url">URL do Aplicativo</label>
                    <input type="text" id="app_url" name="app_url" required 
                           value="<?php echo htmlspecialchars($env['NEXT_PUBLIC_APP_URL'] ?? 'https://ffinora.com.br'); ?>">
                    <span class="helper-text">URL base do seu site de produção.</span>
                </div>

                <button type="submit" class="btn-submit">Salvar Configurações</button>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
