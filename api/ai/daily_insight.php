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

// Load env
$envFile = __DIR__ . '/../../.env.local';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $env[trim($name)] = trim($value);
        }
    }
}

$openAiKey     = $env['OPENAI_API_KEY'] ?? '';
$supabaseUrl   = $env['NEXT_PUBLIC_SUPABASE_URL'] ?? '';
$supabaseAnon  = $env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? '';

if (empty($supabaseUrl) || empty($supabaseAnon)) {
    echo json_encode(['error' => 'Supabase not configured']);
    http_response_code(500);
    exit;
}

// Extract Bearer token
$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? '';
}

if (empty($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
    echo json_encode(['error' => 'Unauthorized — no token']);
    http_response_code(401);
    exit;
}
$accessToken = trim(substr($authHeader, 7));

// Validate user via Supabase Auth
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
    echo json_encode(['error' => 'Invalid or expired token']);
    http_response_code(401);
    exit;
}
$userId = $userResp['id'];

// Get plan from profiles
$ch = curl_init($supabaseUrl . '/rest/v1/profiles?id=eq.' . urlencode($userId) . '&select=plan&limit=1');
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
$userPlan = strtolower($profileResp[0]['plan'] ?? 'free');

if (!in_array($userPlan, ['pro', 'ultra'])) {
    echo json_encode(['error' => 'Plan not eligible', 'plan' => $userPlan]);
    http_response_code(403);
    exit;
}

// Check for existing insight today
$today = date('Y-m-d');
$filterUrl = $supabaseUrl . '/rest/v1/ai_daily_insights'
    . '?user_id=eq.' . urlencode($userId)
    . '&created_at=gte.' . $today . 'T00:00:00'
    . '&created_at=lt.' . date('Y-m-d', strtotime('+1 day')) . 'T00:00:00'
    . '&select=*&limit=1';

$ch = curl_init($filterUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'apikey: ' . $supabaseAnon,
    ],
]);
$existingBody = curl_exec($ch);
curl_close($ch);
$existing = json_decode($existingBody, true);

if (!empty($existing) && is_array($existing) && count($existing) > 0) {
    $cached = $existing[0];
    // Decode jsonb fields if returned as strings
    if (is_string($cached['recommendations'])) {
        $cached['recommendations'] = json_decode($cached['recommendations'], true) ?? [];
    }
    if (is_string($cached['alerts'])) {
        $cached['alerts'] = json_decode($cached['alerts'], true) ?? [];
    }
    echo json_encode(['cached' => true, 'insight' => $cached]);
    exit;
}

// No cached insight — call OpenAI
if (empty($openAiKey)) {
    echo json_encode(['error' => 'OpenAI not configured on server']);
    http_response_code(500);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$transactions = array_slice($input['transactions'] ?? [], 0, 30);
$summaryData  = $input['summary'] ?? [];
$income  = floatval($summaryData['income']  ?? 0);
$expense = floatval($summaryData['expense'] ?? 0);
$balance = $income - $expense;

$txLines = [];
foreach ($transactions as $t) {
    $type = (isset($t['type']) && strtolower($t['type']) === 'income') ? 'Entrada' : 'Saída';
    $txLines[] = $type . ': R$' . number_format(floatval($t['amount'] ?? 0), 2, ',', '.') . ' — ' . ($t['description'] ?? '') . ' (' . ($t['category'] ?? 'Geral') . ')';
}
$txText = implode("\n", $txLines) ?: 'Nenhuma transação registrada.';

$prompt = "Analise os dados financeiros do usuário e retorne APENAS um JSON válido (sem markdown, sem texto antes ou depois), com estes campos:\n"
    . "- summary: string com resumo em 2-3 frases diretas e motivadoras\n"
    . "- recommendations: array com exatamente 3 recomendações práticas (strings curtas)\n"
    . "- alerts: array com até 3 alertas importantes (strings curtas, pode ser vazio [])\n\n"
    . "Dados:\n"
    . "Entradas totais: R$" . number_format($income, 2, ',', '.') . "\n"
    . "Saídas totais: R$" . number_format($expense, 2, ',', '.') . "\n"
    . "Saldo livre: R$" . number_format($balance, 2, ',', '.') . "\n\n"
    . "Transações recentes:\n" . $txText;

$messages = [
    ['role' => 'system', 'content' => 'Você é um consultor financeiro pessoal especializado. Responda sempre em português brasileiro. Retorne APENAS JSON válido, sem nenhum texto adicional.'],
    ['role' => 'user',   'content' => $prompt],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => 'gpt-3.5-turbo',
        'messages'    => $messages,
        'max_tokens'  => 500,
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
    // Fallback mock — still save so user doesn't retry endlessly
    $parsed = [
        'summary'         => 'Análise baseada nos seus dados financeiros. Continue registrando suas transações para obter insights cada vez mais precisos.',
        'recommendations' => [
            'Mantenha um registro diário das suas despesas',
            'Separe pelo menos 20% da renda para poupança',
            'Revise assinaturas e gastos recorrentes mensalmente',
        ],
        'alerts' => $balance < 0 ? ['Seu saldo está negativo — revise seus gastos urgentemente'] : [],
    ];
} else {
    $content = trim($aiData['choices'][0]['message']['content']);
    // Strip markdown code fences if present
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);
    $parsed  = json_decode($content, true);

    if (!$parsed || !isset($parsed['summary'])) {
        // Try extracting JSON block
        preg_match('/\{.*\}/s', $content, $m);
        $parsed = isset($m[0]) ? json_decode($m[0], true) : null;
    }

    if (!$parsed) {
        $parsed = [
            'summary'         => $content,
            'recommendations' => [],
            'alerts'          => [],
        ];
    }
}

// Ensure array types
$parsed['recommendations'] = array_values(array_filter((array)($parsed['recommendations'] ?? [])));
$parsed['alerts']          = array_values(array_filter((array)($parsed['alerts'] ?? [])));

// Save to Supabase
$insertPayload = [
    'user_id'         => $userId,
    'plan'            => $userPlan,
    'summary'         => $parsed['summary'] ?? '',
    'recommendations' => $parsed['recommendations'],
    'alerts'          => $parsed['alerts'],
];

$ch = curl_init($supabaseUrl . '/rest/v1/ai_daily_insights');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($insertPayload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'apikey: ' . $supabaseAnon,
        'Prefer: return=representation',
    ],
]);
$savedBody = curl_exec($ch);
$savedCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$savedArr = json_decode($savedBody, true);
$insight  = (!empty($savedArr[0])) ? $savedArr[0] : array_merge($insertPayload, ['id' => null, 'created_at' => date('c')]);

// Decode jsonb if returned as string
if (is_string($insight['recommendations'])) $insight['recommendations'] = json_decode($insight['recommendations'], true) ?? [];
if (is_string($insight['alerts']))          $insight['alerts']          = json_decode($insight['alerts'], true)          ?? [];

echo json_encode(['cached' => false, 'insight' => $insight]);
