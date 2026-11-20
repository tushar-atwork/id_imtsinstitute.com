<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../config/local.php';
date_default_timezone_set($cfg['app']['timezone'] ?? 'Asia/Kolkata');

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('PHP 8.3+ is required. Current: ' . PHP_VERSION);
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/SyncService.php';

if (PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name($cfg['app']['session_name'] ?? 'imts_id_crm');
        session_start();
    }
}

$crm = Db::connect($cfg['crm_db']);
$erp = Db::connect($cfg['erp_db']);
