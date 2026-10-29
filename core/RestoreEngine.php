<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SecurityValidator.php';
require_once __DIR__ . '/DeduplicationStorage.php';
require_once __DIR__ . '/Logger.php';

/**
 * Động cơ khôi phục dữ liệu theo phiên bản (Restore Engine)
 * Đáp ứng Yêu cầu kỹ thuật 2, Phép thử 1, 3, 5
 */
class RestoreEngine {
    private PDO $pdo;
    private DeduplicationStorage $storage;

    public function __construct(?DeduplicationStorage $storage = null) {
        $this->pdo = Database::getConnection();
        $this->storage = $storage ?? new DeduplicationStorage();
    }

    /**
     * Lấy danh sách các tệp hợp lệ trong phiên bản cần khôi phục (không lấy tệp DELETED)
     */
    public function getRestorableEntries(int $versionId): array {
        $stmt = $this->pdo->prepare("
            SELECT e.*, o.storage_path, o.sha256_hash, v.job_id, v.version_number, v.status as version_status, j.source_path
            FROM `entries` e
            JOIN `versions` v ON e.version_id = v.id
            JOIN `backup_jobs` j ON v.job_id = j.id
            JOIN `objects` o ON e.object_id = o.id
            WHERE e.version_id = ? AND e.change_type != 'DELETED'
            ORDER BY e.relative_path ASC
        ");
        $stmt->execute([$versionId]);
        return $stmt->fetchAll();
    }

    /**
     * KIỂM TRA MÃ BĂM TOÀN BỘ PHIÊN BẢN TRƯỚC KHI KHÔI PHỤC (Phép thử 3)
     */
    public function verifyVersion(int $versionId): array {
        $entries = $this->getRestorableEntries($versionId);
        $results = [
            'valid_count' => 0,
            'corrupt_count' => 0,
            'details' => []
        ];

        foreach ($entries as $entry) {
            $verify = $this->storage->verifyObject((int)$entry['object_id']);
            if ($verify['valid']) {
                $results['valid_count']++;
                $results['details'][] = [
                    'relative_path' => $entry['relative_path'],
                    'status' => 'OK',
                    'hash' => $verify['sha256']
                ];
            } else {
                $results['corrupt_count']++;
                $results['details'][] = [
                    'relative_path' => $entry['relative_path'],
                    'status' => 'CORRUPT',
                    'error' => $verify['error']
                ];
                Logger::error('VERIFY_FAIL', "Phát hiện tệp hỏng khi kiểm tra: {$entry['relative_path']} - {$verify['error']}", (int)$entry['job_id'], $versionId);
            }
        }

        return $results;
    }

    /**
     * THỰC HIỆN KHÔI PHỤC DỮ LIỆU VÀO THƯ MỤC ĐÍCH MỚI
     */
    public function restore(int $versionId, string $targetDir, array $selectedPaths = [], bool $enforceStrictVerify = true): array {
        // 1. Kiểm tra trạng thái phiên bản: Phiên bản gián đoạn/FAILED không được phép khôi phục hoàn chỉnh
        $stmtVer = $this->pdo->prepare("SELECT * FROM `versions` WHERE `id` = ?");
        $stmtVer->execute([$versionId]);
        $version = $stmtVer->fetch();

        if (!$version) {
            throw new InvalidArgumentException("Không tìm thấy phiên bản ID: {$versionId}");
        }

        if ($version['status'] === 'FAILED') {
            throw new RuntimeException("TỪ CHỐI KHÔI PHỤC: Phiên bản v{$version['version_number']} là phiên bản gián đoạn/chưa hoàn tất (Yêu cầu kỹ thuật 2)!");
        }

        // 2. Lấy thông tin tác vụ để kiểm tra chống ghi đè nguồn
        $stmtJob = $this->pdo->prepare("SELECT `source_path` FROM `backup_jobs` WHERE `id` = ?");
        $stmtJob->execute([$version['job_id']]);
        $job = $stmtJob->fetch();

        // 3. Kiểm tra an toàn: Chặn ghi đè nguồn & Chặn Path Traversal (Phép thử 5)
        SecurityValidator::assertNoSourceOverwrite($job['source_path'], $targetDir);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $realTarget = realpath($targetDir);
        if ($realTarget === false) {
            throw new RuntimeException("Thư mục đích không tồn tại hoặc không thể tạo: {$targetDir}");
        }

        $entries = $this->getRestorableEntries($versionId);
        if (empty($entries)) {
            throw new RuntimeException("Phiên bản v{$version['version_number']} không có tệp nào để khôi phục.");
        }

        // Lọc theo danh sách tệp được chọn nếu có
        if (!empty($selectedPaths)) {
            $pathSet = array_flip($selectedPaths);
            $entries = array_filter($entries, fn($e) => isset($pathSet[$e['relative_path']]));
        }

        $restoredFiles = [];
        $failedFiles = [];

        Logger::info('RESTORE_START', "Bắt đầu khôi phục phiên bản v{$version['version_number']} vào thư mục: {$realTarget}", (int)$version['job_id'], $versionId);

        foreach ($entries as $entry) {
            $relPath = $entry['relative_path'];

            // 4. Kiểm tra an toàn từng đường dẫn tệp (chống ../)
            SecurityValidator::assertSafeRelativePath($relPath);

            $objPhysicalPath = $entry['storage_path'];
            $expectedHash = $entry['verified_hash'];

            // 5. Kiểm tra mã băm trước khi khôi phục (Phép thử 3)
            if ($enforceStrictVerify) {
                if (!file_exists($objPhysicalPath)) {
                    $failedFiles[] = [
                        'file' => $relPath,
                        'reason' => 'Tệp lưu trữ vật lý không tồn tại trên đĩa!'
                    ];
                    continue;
                }

                $actualHash = hash_file('sha256', $objPhysicalPath);
                if ($actualHash !== $expectedHash) {
                    // PHÁT HIỆN HỎNG HÓC: Không xác nhận khôi phục thành công!
                    $failedFiles[] = [
                        'file' => $relPath,
                        'reason' => "Mã băm không khớp! Kỳ vọng: {$expectedHash}, Thực tế: {$actualHash}"
                    ];
                    Logger::error('RESTORE_HASH_MISMATCH', "Phát hiện mã băm sai lệch khi khôi phục tệp: {$relPath}", (int)$version['job_id'], $versionId, $relPath);
                    continue;
                }
            }

            // Tạo thư mục cha nếu chưa có
            $destFile = SecurityValidator::buildSafeTargetPath($realTarget, $relPath);
            $destDir = dirname($destFile);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }

            // Khôi phục tệp
            if (copy($objPhysicalPath, $destFile)) {
                $restoredFiles[] = [
                    'relative_path' => $relPath,
                    'destination' => $destFile,
                    'size' => filesize($destFile),
                    'hash' => $expectedHash
                ];
            } else {
                $failedFiles[] = [
                    'file' => $relPath,
                    'reason' => 'Không thể ghi tệp vào thư mục đích.'
                ];
            }
        }

        $isFullSuccess = empty($failedFiles);
        $stageStatus = $isFullSuccess ? 'RESTORE_SUCCESS' : 'RESTORE_PARTIAL_OR_FAILED';

        Logger::log(
            $stageStatus,
            "Kết quả khôi phục: Thành công " . count($restoredFiles) . " tệp, Thất bại " . count($failedFiles) . " tệp.",
            $isFullSuccess ? 'INFO' : 'ERROR',
            (int)$version['job_id'],
            $versionId
        );

        return [
            'success' => $isFullSuccess,
            'version_number' => $version['version_number'],
            'target_dir' => $realTarget,
            'restored_count' => count($restoredFiles),
            'failed_count' => count($failedFiles),
            'restored_files' => $restoredFiles,
            'failed_files' => $failedFiles
        ];
    }
}
