<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ler o .env.local de forma segura no backend
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

// Usar process.env logicamente no backend
$openAiKey = isset($env['OPENAI_API_KEY']) ? $env['OPENAI_API_KEY'] : '';

if (empty($openAiKey)) {
    echo json_encode(["error" => "OpenAI API Key not configured no servidor backend"]);
    http_response_code(500);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages'])) {
    echo json_encode(["error" => "Invalid request body"]);
    http_response_code(400);
    exit;
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => isset($input['model']) ? $input['model'] : 'gpt-3.5-turbo',
    'messages' => $input['messages']
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $openAiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$responseData = json_decode($response, true);

// Se houver erro de quota/creditos (429) ou outro erro de autenticação, retorna mock
if ($httpCode >= 400 || (isset($responseData['error']) && strpos(strtolower($responseData['error']['message']), 'quota') !== false)) {
    // Retorna resposta simulada para teste
    http_response_code(200);
    echo json_encode([
        "choices" => [
            [
                "message" => [
                    "role" => "assistant",
                    "content" => "*(Resposta Simulada)* Olá! Parece que o seu saldo da OpenAI esgotou ou houve um erro na API. Como o seu Assistente FFinora, minha recomendação simulada é: **Reduza despesas supérfluas e foque em quitar suas dívidas com maiores juros primeiro**. Quando a sua chave API estiver com créditos, eu voltarei a analisar seus dados reais em detalhes!"
                ]
            ]
        ]
    ]);
    exit;
}

http_response_code($httpCode);
echo $response;
?>
