<?php
/**
 * FFinora - api/stripe/session.php
 * --------------------------------------------------------------
 * Retorna os dados minimos de uma Checkout Session da Stripe,
 * necessarios para o onboarding premium em /obrigado.
 *
 * Entrada (GET):  ?session_id=cs_live_... | cs_test_...
 *
 * Saida (JSON 200):
 *   {
 *     "email":           "user@example.com",
 *     "plan":            "pro" | "ultra" | null,
 *     "customer_id":     "cus_..." | null,
 *     "subscription_id": "sub_..." | null
 *   }
 *
 * Erros sempre retornam JSON { error: "mensagem amigavel" }.
 *
 * Validacoes:
 *   - session_id no formato cs_(test|live)_xxx
 *   - metadata.source === 'landing' (so libera fluxo premium da landing)
 *   - payment_status === 'paid' OU status === 'complete'
 *
 * Mesmo padrao do checkout.php: carrega .env.local e chama Stripe via cURL.
 * -------------------------------------------------------------- */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo nao permitido.']);
    exit;
}

// --- Carregar Variáveis de Ambiente (Robusto) -------------------------------
$env = [];

// 1. Tentar carregar do secrets.php (arquivo visível e seguro via PHP)
$secretsFile = __DIR__ . '/../../secrets.php';
if (file_exists($secretsFile)) {
    $secrets = include($secretsFile);
    if (is_array($secrets)) {
        $env = array_merge($env, $secrets);
    }
}

// 2. Tentar carregar de arquivos .env.local e .env tradicional
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

$stripeSecret = $env['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?? $_ENV['STRIPE_SECRET_KEY'] ?? $_SERVER['STRIPE_SECRET_KEY'] ?? '';
if (empty($stripeSecret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe não configurado no servidor. Certifique-se de que o arquivo .env ou secrets.php está configurado na raiz do servidor.']);
    exit;
}

// --- Validar session_id ------------------------------------------------
$sessionId = isset($_GET['session_id']) ? trim($_GET['session_id']) : '';
if ($sessionId === '' || !preg_match('/^cs_(test|live)_[A-Za-z0-9_-]+$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id invalido ou ausente.']);
    exit;
}

// --- Consultar Stripe API ---------------------------------------------
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $stripeSecret,
        'Stripe-Version: 2023-10-16',
    ],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha de comunicacao com a Stripe.']);
    exit;
}

$data = json_decode($response, true);
if ($httpCode === 404) {
    http_response_code(404);
    echo json_encode(['error' => 'Sessao nao encontrada.']);
    exit;
}
if ($httpCode !== 200 || !is_array($data)) {
    http_response_code($httpCode >= 400 ? $httpCode : 500);
    $errMsg = $data['error']['message'] ?? 'Erro desconhecido da Stripe.';
    echo json_encode(['error' => $errMsg]);
    exit;
}

// --- Validar metadata.source === 'landing' ----------------------------
$source = '';
if (!empty($data['metadata']['source'])) {
    $source = strtolower(trim($data['metadata']['source']));
} elseif (!empty($data['subscription_data']['metadata']['source'])) {
    $source = strtolower(trim($data['subscription_data']['metadata']['source']));
}
if ($source !== 'landing') {
    http_response_code(403);
    echo json_encode(['error' => 'Sessao nao pertence ao fluxo premium da landing.']);
    exit;
}

// --- Validar pagamento ------------------------------------------------
$paymentStatus = $data['payment_status'] ?? '';
$status        = $data['status'] ?? '';
$paid = ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required' || $status === 'complete');
if (!$paid) {
    http_response_code(402);
    echo json_encode(['error' => 'Pagamento ainda nao confirmado.']);
    exit;
}

// --- Extrair dados minimos --------------------------------------------
$email = null;
if (!empty($data['customer_details']['email'])) {
    $email = $data['customer_details']['email'];
} elseif (!empty($data['customer_email'])) {
    $email = $data['customer_email'];
}
if (empty($email)) {
    http_response_code(404);
    echo json_encode(['error' => 'Email do cliente nao encontrado na sessao.']);
    exit;
}

$plan = null;
if (!empty($data['metadata']['plan'])) {
    $plan = strtolower(trim($data['metadata']['plan']));
} elseif (!empty($data['subscription_data']['metadata']['plan'])) {
    $plan = strtolower(trim($data['subscription_data']['metadata']['plan']));
}

$customerId = null;
if (!empty($data['customer'])) {
    $customerId = is_array($data['customer']) ? ($data['customer']['id'] ?? null) : $data['customer'];
}

$subscriptionId = null;
if (!empty($data['subscription'])) {
    $subscriptionId = is_array($data['subscription']) ? ($data['subscription']['id'] ?? null) : $data['subscription'];
}

echo json_encode([
    'email'           => strtolower(trim($email)),
    'plan'            => $plan,
    'customer_id'     => $customerId,
    'subscription_id' => $subscriptionId,
]);
