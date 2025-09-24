<?php
require_once __DIR__ . '/config.php';
start_session();
session_destroy();
header('Location: ' . BASE_URL . 'index.php');
exit;


