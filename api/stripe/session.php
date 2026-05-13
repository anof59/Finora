<?php
// /api/stripe/session.php
// Le uma Checkout Session do Stripe e retorna email + plan
// SOMENTE se metadata.source === 'landing'. Usado por /obrigado.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ffinora.com.br');

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

$stripeSecret = isset($env['STRIPE_SECRET_KEY']) ? $env['STRIPE_SECRET_KEY'] : '';
if (!$stripeSecret) {
      http_response_code(500);
      echo json_encode(['error' => 'stripe_not_configured']);
      exit;
}

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId || !preg_match('/^cs_/', $sessionId)) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid_session_id']);
      exit;
}

$ch = curl_init(
      'https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId)
      . '?expand[]=customer&expand[]=subscription'
  );
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $stripeSecret,
            ]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 400) {
      http_response_code(500);
      echo json_encode(['error' => 'stripe_error']);
      exit;
}

$session = json_decode($resp, true);

$source = $session['metadata']['source'] ?? null;
if ($source !== 'landing') {
      http_response_code(403);
      echo json_encode(['error' => 'invalid_source']);
      exit;
}

$paymentStatus = $session['payment_status'] ?? null;
$status        = $session['status'] ?? null;
if ($paymentStatus !== 'paid' && $status !== 'complete') {
      http_response_code(402);
      echo json_encode(['error' => 'not_paid']);
      exit;
}

$plan = $session['metadata']['plan'] ?? null;
if (!in_array($plan, ['pro', 'ultra'], true)) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid_plan_metadata']);
      exit;
}

$email = $session['customer_details']['email']
        ?? ($session['customer']['email'] ?? null);

$customerId     = is_array($session['customer'] ?? null)
                  ? ($session['customer']['id'] ?? null)
                  : ($session['customer'] ?? null);
$subscriptionId = is_array($session['subscription'] ?? null)
                  ? ($session['subscription']['id'] ?? null)
                  : ($session['subscription'] ?? null);

echo json_encode([
                     'status'          => $status,
                     'payment_status'  => $paymentStatus,
                     'email'           => $email,
                     'plan'            => $plan,
                     'customer_id'     => $customerId,
                     'subscription_id' => $subscriptionId,
                 ]);
