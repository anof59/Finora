<?php
// --- Carregar .env.local ou .env ─────────────────────────────────────────────
$envFileLocal = __DIR__ . '/../../.env.local';
$envFileProd  = __DIR__ . '/../../.env';
$env = [];

foreach ([$envFileLocal, $envFileProd] as $file) {
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $env[trim($name)] = trim($value);
            }
        }
    }
}

$stripe_webhook_secret = $env['STRIPE_WEBHOOK_SECRET']     ?? getenv('STRIPE_WEBHOOK_SECRET')     ?? $_ENV['STRIPE_WEBHOOK_SECRET']     ?? '';
$supabaseUrl           = $env['NEXT_PUBLIC_SUPABASE_URL']   ?? getenv('NEXT_PUBLIC_SUPABASE_URL')   ?? $_ENV['NEXT_PUBLIC_SUPABASE_URL']   ?? '';
$supabaseServiceKey    = $env['SUPABASE_SERVICE_ROLE_KEY']  ?? getenv('SUPABASE_SERVICE_ROLE_KEY']  ?? $_ENV['SUPABASE_SERVICE_ROLE_KEY']  ?? '';

$payload = @file_get_contents('php://input');
$sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if (!$stripe_webhook_secret || empty($sig_header)) {
    http_response_code(400);
    echo 'Missing config or signature.';
    exit;
}

$timestamp = '';
$signatures = [];

$parts = explode(',', $sig_header);
foreach ($parts as $part) {
    list($key, $val) = explode('=', trim($part), 2);
    if ($key === 't') $timestamp = $val;
    if ($key === 'v1') $signatures[] = $val;
}

if (!$timestamp || empty($signatures)) {
    http_response_code(400);
    echo 'Invalid signature header';
    exit();
}

$signed_payload = $timestamp . '.' . $payload;
$expected_signature = hash_hmac('sha256', $signed_payload, $stripe_webhook_secret);

$valid = false;
foreach ($signatures as $sig) {
    if (hash_equals($expected_signature, $sig)) {
        $valid = true;
        break;
    }
}

if (!$valid) {
    http_response_code(400);
    echo 'Signature invalid';
    exit();
}

$event = json_decode($payload, true);
$type = $event['type'];
$data = $event['data']['object'];

function updateSupabaseProfile($user_id, $updates) {
    global $supabaseUrl, $supabaseServiceKey;
    if (!$supabaseServiceKey || !$user_id) return;

    $url = $supabaseUrl . '/rest/v1/profiles?id=eq.' . urlencode($user_id);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updates));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $supabaseServiceKey,
        'Authorization: Bearer ' . $supabaseServiceKey,
        'Content-Type: application/json',
        'Prefer: return=minimal'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
}

function updateSupabaseByCustomerOrSub($customer_id, $sub_id, $updates, $user_id_fallback = null) {
    global $supabaseUrl, $supabaseServiceKey;
    if (!$supabaseServiceKey || !$customer_id) return;

    // Achar id do user pelo customer_id
    $url = $supabaseUrl . '/rest/v1/profiles?stripe_customer_id=eq.' . urlencode($customer_id) . '&select=id';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $supabaseServiceKey,
        'Authorization: Bearer ' . $supabaseServiceKey
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $res = json_decode($response, true);
    if (!empty($res) && isset($res[0]['id'])) {
        updateSupabaseProfile($res[0]['id'], $updates);
    } else if ($user_id_fallback) {
        updateSupabaseProfile($user_id_fallback, $updates);
    }
}

$PRICE_PRO = 'price_1TVHThILykQlxpCuY4bT6jVA';
$PRICE_ULTRA = 'price_1TVHTrILykQlxpCutQwYwX2K';

switch ($type) {
    case 'checkout.session.completed':
        $user_id = isset($data['client_reference_id']) ? $data['client_reference_id'] : null;
        $customer_id = isset($data['customer']) ? $data['customer'] : null;
        $subscription_id = isset($data['subscription']) ? $data['subscription'] : null;
        
        if ($user_id && $subscription_id) {
            updateSupabaseProfile($user_id, [
                'stripe_customer_id' => $customer_id,
                'stripe_subscription_id' => $subscription_id
            ]);
        }
        break;

    case 'customer.subscription.updated':
    case 'customer.subscription.created':
        $customer_id = $data['customer'];
        $subscription_id = $data['id'];
        $status = $data['status']; 
        $price_id = isset($data['items']['data'][0]['price']['id']) ? $data['items']['data'][0]['price']['id'] : null;
        $current_period_end = $data['current_period_end'];
        
        $plan = 'free';
        if ($status === 'active' || $status === 'trialing') {
            if ($price_id === $PRICE_PRO) $plan = 'pro';
            if ($price_id === $PRICE_ULTRA) $plan = 'ultra';
        }
        
        $user_id_meta = isset($data['metadata']['user_id']) ? $data['metadata']['user_id'] : null;
        updateSupabaseByCustomerOrSub($customer_id, $subscription_id, [
            'stripe_subscription_id' => $subscription_id,
            'subscription_status' => $status,
            'plan' => $plan,
            'subscription_end' => date('c', $current_period_end)
        ], $user_id_meta);
        break;

    case 'customer.subscription.deleted':
        $customer_id = $data['customer'];
        $user_id_meta = isset($data['metadata']['user_id']) ? $data['metadata']['user_id'] : null;
        updateSupabaseByCustomerOrSub($customer_id, $data['id'], [
            'subscription_status' => 'canceled',
            'plan' => 'free'
        ], $user_id_meta);
        break;
        
    case 'invoice.paid':
        $customer_id = $data['customer'];
        $subscription_id = $data['subscription'];
        if ($customer_id && $subscription_id) {
            updateSupabaseByCustomerOrSub($customer_id, $subscription_id, [
                'subscription_status' => 'active'
            ]);
        }
        break;

    case 'invoice.payment_failed':
        $customer_id = $data['customer'];
        $subscription_id = $data['subscription'];
        if ($customer_id && $subscription_id) {
            updateSupabaseByCustomerOrSub($customer_id, $subscription_id, [
                'subscription_status' => 'past_due'
            ]);
        }
        break;
}

http_response_code(200);
echo json_encode(['status' => 'success']);
?>
