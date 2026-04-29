<?php
header("Content-Type: application/javascript");

$envFile = __DIR__ . '/.env.local';
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

$supabaseUrl = isset($env['NEXT_PUBLIC_SUPABASE_URL']) ? $env['NEXT_PUBLIC_SUPABASE_URL'] : '';
$supabaseKey = isset($env['NEXT_PUBLIC_SUPABASE_ANON_KEY']) ? $env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] : '';

// Injeta as variáveis no formato esperado pelas boas práticas (process.env.NEXT_PUBLIC_...)
echo "window.process = { env: { NEXT_PUBLIC_SUPABASE_URL: '" . $supabaseUrl . "', NEXT_PUBLIC_SUPABASE_ANON_KEY: '" . $supabaseKey . "' } };";
?>
