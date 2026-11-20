<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/app/bootstrap.php';

echo "===== IMTS ID CRM - PHASE 1 INSTALL =====\n";
echo "PHP: " . PHP_VERSION . "\n";

echo "Testing CRM database... ";
$crm->query("SELECT 1");
echo "OK\n";

echo "Testing ERP database... ";
$erp->query("SELECT 1");
echo "OK\n";

echo "Checking required ERP tables... \n";
$required = [
    'imts_erp_student',
    'imts_erp_student_payment',
    'imts_erp_student_assigned',
    'imts_erp_student_assigned_follow_pdc',
    'imts_erp_admission_confirmation_status',
    'imts_erp_university',
    'imts_erp_session',
    'imts_erp_lead',
    'imts_erp_user',
];
$check = $erp->prepare("SHOW TABLES LIKE ?");
foreach ($required as $table) {
    $check->execute([$table]);
    if (!$check->fetchColumn()) {
        throw new RuntimeException("Missing ERP table: {$table}");
    }
    echo "  [OK] {$table}\n";
}

echo "Creating CRM tables... ";
$sql = file_get_contents(__DIR__ . '/database/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
    $crm->exec($statement);
}
echo "OK\n";

$stmt = $crm->prepare("SELECT id FROM crm_users WHERE username='admin' LIMIT 1");
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $password = bin2hex(random_bytes(6));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $crm->prepare(
        "INSERT INTO crm_users (username, password_hash, name, role)
         VALUES ('admin', ?, 'CRM Administrator', 'admin')"
    );
    $ins->execute([$hash]);

    echo "\nADMIN LOGIN CREATED\n";
    echo "Username: admin\n";
    echo "Password: {$password}\n";
    echo "SAVE THIS PASSWORD NOW. It is shown only during first install.\n";
} else {
    echo "Admin account already exists; password unchanged.\n";
}

echo "\nRunning first ERP sync...\n";
$sync = new SyncService($erp, $crm, $cfg);
$result = $sync->run('install');
print_r($result);

if (!$result['ok']) {
    exit(2);
}

echo "\n===== PHASE 1 INSTALL SUCCESS =====\n";
echo "Open the subdomain and log in.\n";
