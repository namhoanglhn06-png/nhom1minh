<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';

echo "--- RUNNING AUTHENTICATION TESTS ---\n";

// Test 1: login with wrong password
$r1 = Auth::login('admin', 'wrong_pass');
echo 'Test 1 (wrong pass): ' . (!$r1['success'] ? 'PASS' : 'FAIL') . ' -> ' . $r1['message'] . "\n";

// Test 2: login with correct password
$r2 = Auth::login('admin', 'admin123');
echo 'Test 2 (correct pass): ' . ($r2['success'] ? 'PASS' : 'FAIL') . ' -> Logged in as: ' . ($r2['user']['username'] ?? '') . "\n";

// Test 3: register new user
$testUser = 'dev_' . time();
$r3 = Auth::register([
    'full_name' => 'Kỹ sư Phần mềm',
    'username' => $testUser,
    'email' => $testUser . '@company.local',
    'password' => 'Pass@123456',
    'password_confirm' => 'Pass@123456',
    'role' => 'OPERATOR'
]);
echo 'Test 3 (register new account): ' . ($r3['success'] ? 'PASS' : 'FAIL') . ' -> ' . $r3['message'] . "\n";

// Test 4: login with newly registered user
$r4 = Auth::login($testUser, 'Pass@123456');
echo 'Test 4 (login with new account): ' . ($r4['success'] ? 'PASS' : 'FAIL') . ' -> Role: ' . ($r4['user']['role'] ?? '') . "\n";

// Test 5: test unauthorized login (cannot press randomly)
$r5 = Auth::login('hacker', '123456');
echo 'Test 5 (unauthorized account): ' . (!$r5['success'] ? 'PASS' : 'FAIL') . ' -> ' . $r5['message'] . "\n";

// Test 6: export auth backup snapshot
$backupPath = Auth::exportAccountsBackup();
echo 'Test 6 (export accounts backup): ' . (!empty($backupPath) && file_exists($backupPath) ? 'PASS' : 'FAIL') . ' -> File: ' . basename($backupPath) . "\n";

// Test 7: restore accounts backup
$backupContent = file_get_contents($backupPath);
$r7 = Auth::restoreAccountsFromBackup($backupContent);
echo 'Test 7 (restore accounts backup): ' . ($r7['success'] ? 'PASS' : 'FAIL') . ' -> ' . $r7['message'] . "\n";

// Test 8: update user account (Admin)
$r8 = Auth::updateUser($r3['user_id'], [
    'full_name' => 'Kỹ sư Cấp cao Đã Cập Nhật',
    'email'     => $testUser . '_updated@company.local',
    'role'      => 'ADMIN',
    'status'    => 'ACTIVE'
]);
echo 'Test 8 (update user account): ' . ($r8['success'] ? 'PASS' : 'FAIL') . ' -> ' . $r8['message'] . "\n";

// Test 9: delete user account (Admin)
$r9 = Auth::deleteUser($r3['user_id'], 1); // current admin is 1
echo 'Test 9 (delete user account): ' . ($r9['success'] ? 'PASS' : 'FAIL') . ' -> ' . $r9['message'] . "\n";

echo "--- ALL AUTH TESTS FINISHED ---\n";
