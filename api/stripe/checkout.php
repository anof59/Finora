<?php
header("Content-Type: application/json");
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

$stripe_secret = isset($env['STRIPE_SECRET_KEY']) ? $env['STRIPE_SECRET_KEY'] : '';
$app_url = isset($env['NEXT_PUBLIC_APP_URL']) ? $env['NEXT_PUBLIC_APP_URL'] : 'http://localhost:3000'; // fallback se não configurado

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['price_id']) || empty($input['user_id'])) {
    echo json_encode(['error' => 'Parâmetros inválidos']);
    exit;
}

$price_id = $input['price_id'];
$user_id = $input['user_id'];
$email = isset($input['email']) ? $input['email'] : '';

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $stripe_secret,
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_POST, true);

$data = [
    'success_url' => $app_url . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => $app_url,
    'mode' => 'subscription',
    'client_reference_id' => $user_id,
    'line_items' => [
        0 => [
            'price' => $price_id,
            'quantity' => 1
        ]
    ]
];

if (!empty($email)) {
    $data['customer_email'] = $email;
}

$postFields = http_build_query($data);

curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resData = json_decode($response, true);

if ($httpcode === 200 && isset($resData['url'])) {
    echo json_encode(['url' => $resData['url']]);
} else {
    echo json_encode(['error' => 'Erro da Stripe', 'details' => $resData]);
}
?>
