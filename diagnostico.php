<?php
// ============================================================
// ARQUIVO TEMPORÁRIO DE DIAGNÓSTICO — REMOVER APÓS USO
// ============================================================
header('Content-Type: application/json');

$results = [];

// 1. Verificar onde o PHP está (DOCUMENT_ROOT)
$results['document_root']    = $_SERVER['DOCUMENT_ROOT'] ?? 'N/D';
$results['script_filename']  = $_SERVER['SCRIPT_FILENAME'] ?? 'N/D';
$results['dir']              = __DIR__;

// 2. Verificar se secrets.php existe em locais prováveis
$paths = [
    'secrets_raiz_server'        => $_SERVER['DOCUMENT_ROOT'] . '/secrets.php',
    'secrets_dir'                => __DIR__ . '/secrets.php',
    'secrets_dir_parent'         => dirname(__DIR__) . '/secrets.php',
    'env_local_raiz'             => $_SERVER['DOCUMENT_ROOT'] . '/.env.local',
    'env_local_dir'              => __DIR__ . '/.env.local',
];

foreach ($paths as $label => $path) {
    $results['exists'][$label] = [
        'path'   => $path,
        'exists' => file_exists($path),
    ];
}

// 3. Tentar carregar o secrets.php
$secretsPath = $_SERVER['DOCUMENT_ROOT'] . '/secrets.php';
if (file_exists($secretsPath)) {
    $secrets = include($secretsPath);
    if (is_array($secrets)) {
        $results['secrets_loaded'] = true;
        $results['stripe_key_set'] = !empty($secrets['STRIPE_SECRET_KEY']);
        $results['stripe_key_prefix'] = substr($secrets['STRIPE_SECRET_KEY'] ?? '', 0, 15) . '...';
    } else {
        $results['secrets_loaded'] = false;
        $results['secrets_error']  = 'include() não retornou array';
    }
} else {
    $results['secrets_loaded'] = false;
    $results['secrets_error']  = 'Arquivo não encontrado em: ' . $secretsPath;
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
