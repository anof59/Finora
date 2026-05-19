<?php
header("Content-Type: application/javascript");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$envFileLocal = __DIR__ . '/.env.local';
$envFileProd  = __DIR__ . '/.env';
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

$supabaseUrl = $env['NEXT_PUBLIC_SUPABASE_URL']     ?? getenv('NEXT_PUBLIC_SUPABASE_URL')     ?? $_ENV['NEXT_PUBLIC_SUPABASE_URL']     ?? $_SERVER['NEXT_PUBLIC_SUPABASE_URL']     ?? '';
$supabaseKey = $env['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? getenv('NEXT_PUBLIC_SUPABASE_ANON_KEY') ?? $_ENV['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? $_SERVER['NEXT_PUBLIC_SUPABASE_ANON_KEY'] ?? '';

// Injeta as variáveis no formato esperado pelas boas práticas (process.env.NEXT_PUBLIC_...)
echo "window.process = { env: { NEXT_PUBLIC_SUPABASE_URL: '" . $supabaseUrl . "', NEXT_PUBLIC_SUPABASE_ANON_KEY: '" . $supabaseKey . "' } };";
?>
