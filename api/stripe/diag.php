<?php
header('Content-Type: application/json');

$results = [
    '__DIR__' => __DIR__,
    'open_basedir' => ini_get('open_basedir'),
    'files' => [],
    'env_vars' => []
];

// Check potential file paths
$paths = [
    '../../.env' => __DIR__ . '/../../.env',
    '../../.env.local' => __DIR__ . '/../../.env.local',
    '../../../.env' => __DIR__ . '/../../../.env',
    '../../../../.env' => __DIR__ . '/../../../../.env',
];

foreach ($paths as $label => $path) {
    $real = realpath($path);
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    $results['files'][$label] = [
        'path' => $path,
        'real_path' => $real,
        'exists' => $exists,
        'readable' => $readable,
    ];
}

// Check environment variables safely (without printing secret content)
$vars = ['STRIPE_SECRET_KEY', 'NEXT_PUBLIC_SUPABASE_URL', 'SUPABASE_SERVICE_ROLE_KEY', 'NEXT_PUBLIC_APP_URL'];
foreach ($vars as $var) {
    $val_getenv = getenv($var);
    $val_env = $_ENV[$var] ?? null;
    $val_server = $_SERVER[$var] ?? null;
    
    $results['env_vars'][$var] = [
        'getenv' => $val_getenv !== false ? 'set (len ' . strlen($val_getenv) . ')' : 'not set',
        '_ENV' => $val_env !== null ? 'set (len ' . strlen($val_env) . ')' : 'not set',
        '_SERVER' => $val_server !== null ? 'set (len ' . strlen($val_server) . ')' : 'not set',
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>
