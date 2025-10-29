<?php
// Endpoint to download agent files
require_once __DIR__ . '/../config.php';

// Get the file to download (default: agent.py for backward compatibility)
$file = $_GET['file'] ?? 'agent.py';
$allowedFiles = ['agent.py', 'config.py', 'monitoring.py', 'permission.py'];

// Security: only allow specific files
if (!in_array($file, $allowedFiles)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file requested']);
    exit;
}

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="' . $file . '"');

// Read and output the requested file
$filePath = __DIR__ . '/../agent/' . $file;
if (file_exists($filePath)) {
    $content = file_get_contents($filePath);
    
    // Replace server base URL in config.py or agent.py
    if ($file === 'config.py' || $file === 'agent.py') {
        $serverBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $content = str_replace(
            "SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', 'http://localhost:8080')",
            "SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', '{$serverBase}')",
            $content
        );
        $content = str_replace(
            "SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', 'http://localhost')",
            "SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', '{$serverBase}')",
            $content
        );
    }
    
    echo $content;
} else {
    http_response_code(404);
    echo "# File not found on server: {$file}";
}

