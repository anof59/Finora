<?php
header('Content-Type: application/json');

$debug = [];

$secretsPaths = [
    'relative_dir' => __DIR__ . '/../secrets.php',
    'relative_checkout' => __DIR__ . '/../../secrets.php',
    'doc_root' => (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) 
        ? $_SERVER['DOCUMENT_ROOT'] . '/secrets.php' : '',
    'dirname_double' => dirname(dirname(__DIR__)) . '/secrets.php',
];

$debug['paths_checked'] = [];
foreach ($secretsPaths as $key => $path) {
    if (empty($path)) {
        $debug['paths_checked'][$key] = ['status' => 'empty_path'];
        continue;
    }
    
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    
    $included_ok = false;
    $is_array_val = false;
    $keys_found = [];
    
    if ($readable) {
        $data = include($path);
        if (is_array($data)) {
            $included_ok = true;
            $is_array_val = true;
            foreach ($data as $k => $v) {
                $len = strlen($v);
                $masked = ($len > 8) ? substr($v, 0, 4) . '...' . substr($v, -4) : 'short_or_empty';
                $keys_found[$k] = [
                    'length' => $len,
                    'masked' => $masked
                ];
            }
        } else {
            $included_ok = true;
            $is_array_val = false;
        }
    }
    
    $debug['paths_checked'][$key] = [
        'resolved_path' => $path,
        'exists' => $exists,
        'readable' => $readable,
        'included_ok' => $included_ok,
        'is_array' => $is_array_val,
        'keys' => $keys_found
    ];
}

$debug['server_vars'] = [
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'not_set',
    'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'not_set',
    '__DIR__' => __DIR__
];

// Carregar variáveis do .env ou .env.local se houver
$envPaths = [
    __DIR__ . '/../../.env.local',
    __DIR__ . '/../../.env',
];
$debug['env_files'] = [];
foreach ($envPaths as $file) {
    $exists = file_exists($file);
    $debug['env_files'][$file] = [
        'exists' => $exists,
        'readable' => $exists ? is_readable($file) : false
    ];
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
