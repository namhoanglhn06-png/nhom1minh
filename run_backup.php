<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/BackupEngine.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    header('Location: index.php');
    exit;
}

$engine = new BackupEngine();
$result = $engine->executeBackup($jobId);

if ($result['status'] === 'COMPLETED') {
    $msg = "Sao lưu thành công Phiên bản v{$result['version_number']}! Đã xử lý {$result['total_files']} tệp (+{$result['added_count']} mới, ~{$result['modified_count']} sửa, -{$result['deleted_count']} xóa).";
    header("Location: versions.php?job_id={$jobId}&status=success&msg=" . urlencode($msg));
    exit;
} else {
    $msg = "Sao lưu thất bại hoặc bị gián đoạn: " . ($result['error'] ?? 'Lỗi không xác định');
    header("Location: versions.php?job_id={$jobId}&status=danger&msg=" . urlencode($msg));
    exit;
}
