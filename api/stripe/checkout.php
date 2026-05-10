<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Carregar .env.local ─────────────────────────────────────────────────────
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

$stripeSecret = $env['STRIPE_SECRET_KEY']    ?? '';
$appUrl       = $env['NEXT_PUBLIC_APP_URL']  ?? 'https://ffinora.com.br';

if (empty($stripeSecret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe não configurado no servidor.']);
    exit;
}

// ── Mapeamento plano → price_id (IDs Live da Stripe) ──────────────────────
$priceMap = [
    'pro'   => 'price_1TVHThILykQlxpCuY4bT6jVA',
    'ultra' => 'price_1TVHTrILykQlxpCutQwYwX2K',
];

// ── Ler e validar body ─────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

// Suporte a landing page (sem user_id) e app (com user_id)
$plan    = strtolower(trim($input['plan']     ?? ''));
$priceId = trim($input['price_id']            ?? '');
$userId  = trim($input['user_id']             ?? '');
$email   = trim($input['email']               ?? '');
$source  = trim($input['source']              ?? 'app'); // 'app' ou 'landing'

// Resolver price_id
if (!empty($plan) && isset($priceMap[$plan])) {
    $priceId = $priceMap[$plan];
} elseif (empty($priceId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Plano inválido. Use "pro" ou "ultra".']);
    exit;
}

// Validações por origem
if ($source === 'landing') {
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'email é obrigatório para checkout pela landing.']);
        exit;
    }
} else {
    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id é obrigatório.']);
        exit;
    }
}

$planFinal = $plan ?: 'pro';

// success_url depende da origem
if ($source === 'landing') {
    $successUrl = 'https://ffinora.com.br/login?payment=success&plan=' . urlencode($planFinal);
} else {
    $successUrl = $appUrl . '?session_id={CHECKOUT_SESSION_ID}&plan=' . urlencode($planFinal);
}

// ── Montar payload para Stripe Checkout ────────────────────────────────────
$postData = [
    'mode'                              => 'subscription',
    'success_url'                       => $successUrl,
    'cancel_url'                        => $appUrl . '?checkout=cancelado',
    'subscription_data[metadata][plan]' => $planFinal,
    'line_items[0][price]'              => $priceId,
    'line_items[0][quantity]'           => 1,
    'payment_method_types[0]'           => 'card',
];

// client_reference_id e metadata user_id apenas quando existir userId
if (!empty($userId)) {
    $postData['client_reference_id']             = $userId;
    $postData['subscription_data[metadata][user_id]'] = $userId;
}

if ($source === 'landing') {
    $postData['subscription_data[metadata][source]'] = 'landing';
    $postData['subscription_data[metadata][email]']  = $email;
}

if (!empty($email)) {
    $postData['customer_email'] = $email;
}

// ── Chamar Stripe API ───────────────────────────────────────────────────────
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $stripeSecret,
        'Content-Type: application/x-www-form-urlencoded',
        'Stripe-Version: 2023-10-16',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha de comunicação com a Stripe: ' . $curlErr]);
    exit;
}

$resData = json_decode($response, true);

if ($httpCode === 200 && isset($resData['url'])) {
    echo json_encode(['url' => $resData['url']]);
} else {
    http_response_code($httpCode >= 400 ? $httpCode : 500);
    $errMsg = $resData['error']['message'] ?? 'Erro desconhecido da Stripe.';
    echo json_encode([
        'error'   => $errMsg,
        'details' => $resData,
    ]);
}
?>
