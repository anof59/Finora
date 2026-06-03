<?php
header('Content-Type: application/json');

$parentFile = dirname(dirname(__DIR__)) . '/secrets.php';
$localFile = __DIR__ . '/../secrets.php';

// Invalidate OPcache if possible
if (function_exists('opcache_invalidate')) {
    if (file_exists($parentFile)) opcache_invalidate($parentFile, true);
    if (file_exists($localFile)) opcache_invalidate($localFile, true);
}

$debug = [
    'parent_file' => [
        'path' => $parentFile,
        'exists' => file_exists($parentFile),
        'writable' => file_exists($parentFile) ? is_writable($parentFile) : is_writable(dirname($parentFile)),
        'mtime' => file_exists($parentFile) ? date('Y-m-d H:i:s', filemtime($parentFile)) : null
    ],
    'local_file' => [
        'path' => $localFile,
        'exists' => file_exists($localFile),
        'writable' => file_exists($localFile) ? is_writable($localFile) : is_writable(dirname($localFile)),
        'mtime' => file_exists($localFile) ? date('Y-m-d H:i:s', filemtime($localFile)) : null
    ]
];

function getKeyFromDisk($path) {
    if (!file_exists($path)) return 'not_exists';
    $content = file_get_contents($path);
    if (preg_match("/'STRIPE_SECRET_KEY'\s*=>\s*'([^']+)'/", $content, $matches)) {
        return $matches[1];
    }
    return 'not_found_in_regex';
}

if ($debug['parent_file']['exists']) {
    // Via include (OPcache susceptible)
    $parentData = @include($parentFile);
    if (is_array($parentData)) {
        $key = $parentData['STRIPE_SECRET_KEY'] ?? 'not_set';
        $debug['parent_file']['key_masked_include'] = strlen($key) > 8 ? substr($key, 0, 8) . '...' . substr($key, -8) : 'short_or_empty';
    }
    // Via direct read (No OPcache)
    $keyDisk = getKeyFromDisk($parentFile);
    $debug['parent_file']['key_masked_disk'] = strlen($keyDisk) > 8 ? substr($keyDisk, 0, 8) . '...' . substr($keyDisk, -8) : $keyDisk;
}

if ($debug['local_file']['exists']) {
    // Via include
    $localData = @include($localFile);
    if (is_array($localData)) {
        $key = $localData['STRIPE_SECRET_KEY'] ?? 'not_set';
        $debug['local_file']['key_masked_include'] = strlen($key) > 8 ? substr($key, 0, 8) . '...' . substr($key, -8) : 'short_or_empty';
    }
    // Via direct read
    $keyDisk = getKeyFromDisk($localFile);
    $debug['local_file']['key_masked_disk'] = strlen($keyDisk) > 8 ? substr($keyDisk, 0, 8) . '...' . substr($keyDisk, -8) : $keyDisk;
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>

