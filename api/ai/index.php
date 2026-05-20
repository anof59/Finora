<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    http_response_code(405);
    exit;
}

// ── 1. Carregar variáveis de ambiente (Robusto) ───────────────────────────
$env = [];

// Tentar carregar do secrets.php (arquivo visível e seguro via PHP)
$secretsFile = __DIR__ . '/../../secrets.php';
if (file_exists($secretsFile)) {
    $secrets = include($secretsFile);
    if (is_array($secrets)) {
        $env = array_merge($env, $secrets);
    }
}

// Tentar carregar de arquivos .env.local e .env tradicional
$envPaths = [
    __DIR__ . '/../../.env.local',
    __DIR__ . '/../../.env',
    (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] . '/.env.local' : '',
    (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] . '/.env' : '',
];

foreach (array_unique(array_filter($envPaths)) as $file) {
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $val = trim($value);
                $val = preg_replace('/^[\'"]|[\'"]$/', '', $val); // Remove aspas
                $env[trim($name)] = $val;
            }
        }
    }
}

$openAiKey       = $env['OPENAI_API_KEY']             ?? getenv('OPENAI_API_KEY')             ?? $_ENV['OPENAI_API_KEY']             ?? $_SERVER['OPENAI_API_KEY']             ?? '';
$supabaseUrl     = $env['NEXT_PUBLIC_SUPABASE_URL']   ?? getenv('NEXT_PUBLIC_SUPABASE_URL')   ?? $_ENV['NEXT_PUBLIC_SUPABASE_URL']   ?? $_SERVER['NEXT_PUBLIC_SUPABASE_URL']   ?? '';
$supabaseAnon    = $env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? getenv('NEXT_PUBLIC_SUPABASE_ANON_KEY') ?? $_ENV['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? $_SERVER['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? '';
$supabaseService = $env['SUPABASE_SERVICE_ROLE_KEY']  ?? getenv('SUPABASE_SERVICE_ROLE_KEY')  ?? $_ENV['SUPABASE_SERVICE_ROLE_KEY']  ?? $_SERVER['SUPABASE_SERVICE_ROLE_KEY']  ?? '';

if (empty($supabaseUrl) || empty($supabaseAnon)) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase não configurado. Certifique-se de configurar o arquivo .env ou secrets.php na raiz do servidor.']);
    exit;
}

if (empty($openAiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI não configurado no servidor. Certifique-se de configurar o arquivo .env ou secrets.php na raiz do servidor.']);
    exit;
}

// ── 2. Extrair Bearer token do usuário ───────────────────────────────────────
$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? '';
}

if (empty($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
    echo json_encode(['error' => 'Não autorizado — token ausente']);
    http_response_code(401);
    exit;
}
$accessToken = trim(substr($authHeader, 7));

// ── 3. Validar usuário via Supabase Auth ─────────────────────────────────────
$ch = curl_init($supabaseUrl . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'apikey: ' . $supabaseAnon,
    ],
]);
$userBody = curl_exec($ch);
curl_close($ch);
$userResp = json_decode($userBody, true);

if (empty($userResp['id'])) {
    echo json_encode(['error' => 'Token inválido ou expirado']);
    http_response_code(401);
    exit;
}
$userId = $userResp['id'];

// ── 4. Verificar plano = ultra E subscription_status = active ────────────────
$ch = curl_init($supabaseUrl . '/rest/v1/profiles?id=eq.' . urlencode($userId) . '&select=plan,subscription_status&limit=1');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'apikey: ' . $supabaseAnon,
    ],
]);
$profileBody = curl_exec($ch);
curl_close($ch);
$profileResp = json_decode($profileBody, true);

$userPlan   = strtolower($profileResp[0]['plan']                ?? 'free');
$subStatus  = strtolower($profileResp[0]['subscription_status'] ?? 'inactive');

// Bloquear se não for ultra com assinatura ativa
if ($userPlan !== 'ultra' || $subStatus !== 'active') {
    echo json_encode([
        'error'   => 'upgrade_required',
        'plan'    => $userPlan,
        'status'  => $subStatus,
        'message' => 'Esta funcionalidade é exclusiva do plano Ultra com assinatura ativa.'
    ]);
    http_response_code(403);
    exit;
}

// ── 5. Ler body da requisição ────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages']) || !is_array($input['messages'])) {
    echo json_encode(['error' => 'Requisição inválida — campo messages obrigatório']);
    http_response_code(400);
    exit;
}

$featureName    = $input['feature']  ?? 'chat';
$userMessages   = $input['messages'];
$financialCtx   = $input['context']  ?? [];

// ── 6. Montar system prompt com contexto financeiro ──────────────────────────
$incomeMonth  = number_format(floatval($financialCtx['income_month']  ?? 0), 2, ',', '.');
$expenseMonth = number_format(floatval($financialCtx['expense_month'] ?? 0), 2, ',', '.');
$balance      = number_format(floatval($financialCtx['balance']       ?? 0), 2, ',', '.');
$topCats      = $financialCtx['top_categories'] ?? [];
$goals        = $financialCtx['goals']          ?? [];
$alerts       = $financialCtx['alerts']         ?? [];
$recentTxs    = $financialCtx['recent_txs']     ?? [];

$ctxTopCats = '';
if (!empty($topCats)) {
    foreach ($topCats as $cat) {
        $ctxTopCats .= '- ' . ($cat['category'] ?? 'Geral') . ': R$' . number_format(floatval($cat['total'] ?? 0), 2, ',', '.') . "\n";
    }
}

$ctxGoals = '';
if (!empty($goals)) {
    foreach ($goals as $g) {
        $ctxGoals .= '- ' . ($g['nome'] ?? '') . ': R$' . number_format(floatval($g['guardado'] ?? 0), 2, ',', '.') . ' / R$' . number_format(floatval($g['alvo'] ?? 0), 2, ',', '.') . "\n";
    }
}

$ctxTxs = '';
if (!empty($recentTxs)) {
    $sliced = array_slice($recentTxs, 0, 15);
    foreach ($sliced as $t) {
        $tipo = strtolower($t['type'] ?? '') === 'income' ? 'Entrada' : 'Saída';
        $ctxTxs .= $tipo . ': R$' . number_format(floatval($t['amount'] ?? 0), 2, ',', '.') . ' — ' . ($t['description'] ?? '') . ' (' . ($t['category'] ?? 'Geral') . ")\n";
    }
}

$systemPrompt = "Você é o assistente financeiro inteligente do FFinora. Responda de forma clara, prática e segura. Ajude o usuário a entender gastos, economizar, planejar metas e melhorar sua organização financeira. Não dê promessa de lucro, não recomende investimentos arriscados e não substitua consultoria financeira profissional.

CONTEXTO FINANCEIRO ATUAL DO USUÁRIO:
- Ganhos do mês: R$ {$incomeMonth}
- Gastos do mês: R$ {$expenseMonth}
- Saldo atual: R$ {$balance}";

if ($ctxTopCats) {
    $systemPrompt .= "\n\nPrincipais categorias de gastos:\n{$ctxTopCats}";
}
if ($ctxGoals) {
    $systemPrompt .= "\n\nMetas financeiras:\n{$ctxGoals}";
}
if (!empty($alerts)) {
    $systemPrompt .= "\n\nAlertas ativos:\n- " . implode("\n- ", $alerts);
}
if ($ctxTxs) {
    $systemPrompt .= "\n\nTransações recentes:\n{$ctxTxs}";
}

$systemPrompt .= "\n\nResponda sempre em português brasileiro. Seja direto, empático e útil. Use emojis moderadamente para tornar a resposta mais amigável.";

// ── 7. Montar array de mensagens para OpenAI ─────────────────────────────────
$messages = [['role' => 'system', 'content' => $systemPrompt]];

foreach ($userMessages as $msg) {
    $role    = in_array($msg['role'] ?? '', ['user', 'assistant']) ? $msg['role'] : 'user';
    $content = trim($msg['content'] ?? '');
    if ($content !== '') {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}

// ── 8. Chamar OpenAI gpt-4o-mini ────────────────────────────────────────────
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => $messages,
        'max_tokens'  => 800,
        'temperature' => 0.6,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openAiKey,
    ],
]);
$aiResponse = curl_exec($ch);
$aiCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$aiData = json_decode($aiResponse, true);

if ($aiCode >= 400 || empty($aiData['choices'][0]['message']['content'])) {
    // Resposta de fallback em caso de erro
    $fallback = "Olá! No momento estou com dificuldade de processar sua solicitação. Por favor, tente novamente em instantes. Enquanto isso, você pode navegar pelo painel e verificar suas transações e metas. 💙";
    $aiContent = $fallback;
    $aiError   = $aiData['error']['message'] ?? 'Erro desconhecido';
} else {
    $aiContent = trim($aiData['choices'][0]['message']['content']);
    $aiError   = null;
}

// ── 9. Salvar histórico na tabela ai_history ─────────────────────────────────
// Usar service role para bypass de RLS ao salvar
$insertKey = !empty($supabaseService) ? $supabaseService : $supabaseAnon;

// Salvar mensagem do usuário
$lastUserMsg = '';
foreach (array_reverse($userMessages) as $m) {
    if (($m['role'] ?? '') === 'user') {
        $lastUserMsg = $m['content'] ?? '';
        break;
    }
}

if (!empty($lastUserMsg)) {
    $chHist = curl_init($supabaseUrl . '/rest/v1/ai_history');
    curl_setopt_array($chHist, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'user_id' => $userId,
            'role'    => 'user',
            'content' => $lastUserMsg,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $insertKey,
            'apikey: ' . $supabaseAnon,
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($chHist);
    curl_close($chHist);
}

// Salvar resposta da IA
$chHist2 = curl_init($supabaseUrl . '/rest/v1/ai_history');
curl_setopt_array($chHist2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'user_id' => $userId,
        'role'    => 'assistant',
        'content' => $aiContent,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $insertKey,
        'apikey: ' . $supabaseAnon,
        'Prefer: return=minimal',
    ],
]);
curl_exec($chHist2);
curl_close($chHist2);

// ── 10. Retornar resposta ao frontend ────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'choices' => [[
        'message' => [
            'role'    => 'assistant',
            'content' => $aiContent,
        ]
    ]],
    'feature' => $featureName,
    'error_detail' => $aiError,
]);
?>
