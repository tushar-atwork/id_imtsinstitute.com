<?php
require __DIR__ . '/../app/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(419);
    exit('Invalid CSRF token');
}

$sync = new SyncService($erp, $crm, $cfg);
$result = $sync->run('manual');
$_SESSION['sync_result'] = $result;
header('Location: index.php');
exit;
