<?php
// Simulate an AJAX POST request with an invalid config parameter to gui/index.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['api'] = '1';

if (isset($argv[1])) {
    $arg = $argv[1];
    $decoded = base64_decode($arg, true);
    $GLOBALS['mock_input_stream'] = ($decoded !== false && json_validate($decoded)) ? $decoded : $arg;
}

require __DIR__ . '/../gui/index.php';
