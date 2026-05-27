<?php
header('Content-Type: application/json');

$env = [];
$secretsFile = __DIR__ . '/../../secrets.php';
if (file_exists($secretsFile)) {
    $secrets = include($secretsFile);
    if (is_array($secrets)) {
        $env = array_merge($env, $secrets);
    }
}

$openai = $env['OPENAI_API_KEY'] ?? '';
$subUrl = $env['NEXT_PUBLIC_SUPABASE_URL'] ?? '';
$subKey = $env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? '';
$stripe = $env['STRIPE_SECRET_KEY'] ?? '';

echo json_encode([
    'openai_length' => strlen($openai),
    'openai_truncated' => (strpos($openai, '...') !== false),
    'supabase_url' => $subUrl,
    'supabase_key_length' => strlen($subKey),
    'supabase_key_truncated' => (strpos($subKey, '...') !== false),
    'stripe_key_length' => strlen($stripe),
    'stripe_key_truncated' => (strpos($stripe, '...') !== false),
]);
?>
