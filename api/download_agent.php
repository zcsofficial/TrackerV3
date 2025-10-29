<?php
// Endpoint to download agent.py file
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="agent.py"');

// Read and output agent.py from server
$agentPath = __DIR__ . '/../agent/agent.py';
if (file_exists($agentPath)) {
    $content = file_get_contents($agentPath);
    // Replace placeholder with actual server base URL
    $serverBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $content = str_replace("SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', 'http://localhost')", "SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', '{$serverBase}')", $content);
    echo $content;
} else {
    http_response_code(404);
    echo "# Agent file not found on server";
}

