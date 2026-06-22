<?php
// A simple PHP server to capture debug logs
$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod === 'POST') {
    $data = file_get_contents('php://input');
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $data . PHP_EOL;
    file_put_contents(__DIR__ . '/debug_logs.txt', $logEntry, FILE_APPEND);
    echo "OK";
} else {
    echo "Debug server running.";
}
