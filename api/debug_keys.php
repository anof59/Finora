<?php
header('Content-Type: application/json');

$parentFile = dirname(dirname(__DIR__)) . '/secrets.php';
$localFile = __DIR__ . '/../secrets.php';

$debug = [
    'parent_file' => [
        'path' => $parentFile,
        'exists' => file_exists($parentFile),
        'writable' => file_exists($parentFile) ? is_writable($parentFile) : is_writable(dirname($parentFile))
    ],
    'local_file' => [
        'path' => $localFile,
        'exists' => file_exists($localFile),
        'writable' => file_exists($localFile) ? is_writable($localFile) : is_writable(dirname($localFile))
    ]
];

if ($debug['parent_file']['exists']) {
    $parentData = include($parentFile);
    if (is_array($parentData)) {
        $key = $parentData['STRIPE_SECRET_KEY'] ?? 'not_set';
        $debug['parent_file']['key_masked'] = strlen($key) > 8 ? substr($key, 0, 8) . '...' . substr($key, -8) : 'short_or_empty';
    }
}

if ($debug['local_file']['exists']) {
    $localData = include($localFile);
    if (is_array($localData)) {
        $key = $localData['STRIPE_SECRET_KEY'] ?? 'not_set';
        $debug['local_file']['key_masked'] = strlen($key) > 8 ? substr($key, 0, 8) . '...' . substr($key, -8) : 'short_or_empty';
    }
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
