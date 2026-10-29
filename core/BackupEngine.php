<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SecurityValidator.php';
require_once __DIR__ . '/DeduplicationStorage.php';
require_once __DIR__ . '/Logger.php';

/**
 * Động cơ sao lưu tăng dần (Incremental Backup Engine)
 * Xử lý: Quét thư mục, phân loại thay đổi (+ / ~ / - / =), lưu phiên bản giao dịch an toàn
 */
class BackupEngine {
    private PDO $pdo;
    private DeduplicationStorage $storage;

    public function __construct(?DeduplicationStorage $storage = null) {
        $this->pdo = Database::getConnection();
        $this->storage = $storage ?? new DeduplicationStorage();
    }

    /**
     * Lấy thông tin tác vụ sao lưu
     */
    public function getJob(int $jobId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM `backup_jobs` WHERE `id` = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            throw new InvalidArgumentException("Không tìm thấy tác vụ sao lưu có ID: {$jobId}");
        }
        return $job;
    }

    /**
     * Lấy phiên bản hoàn thành (COMPLETED) gần nhất của tác vụ
     */
    public function getLatestCompletedVersion(int $jobId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM `versions` 
            WHERE `job_id` = ? AND `status` = 'COMPLETED' 
            ORDER BY `version_number` DESC LIMIT 1
        ");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Lấy danh mục tệp của một phiên bản dưới dạng bản đồ [relative_path => entry_data]
     */
    public function getVersionEntriesMap(int $versionId): array {
        $stmt = $this->pdo->prepare("
            SELECT e.*, o.sha256_hash, o.storage_path 
            FROM `entries` e
            LEFT JOIN `objects` o ON e.object_id = o.id
            WHERE e.version_id = ?
        ");
        $stmt->execute([$versionId]);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['relative_path']] = $row;
        }
        return $map;
    }

    /**
     * Quét thư mục nguồn theo đệ quy, lọc đuôi mở rộng và loại bỏ symlink
     */
    public function scanSourceDirectory(string $sourcePath, array $excludedExtensions = []): array {
        $sourceReal = realpath($sourcePath);
        if ($sourceReal === false || !is_dir($sourceReal)) {
            throw new InvalidArgumentException("Thư mục nguồn không tồn tại: {$sourcePath}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceReal, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $fullPath = $item->getPathname();

            // 1. Kiểm tra và bỏ qua symlink
            if ($item->isLink() || SecurityValidator::isSymlink($fullPath)) {
                Logger::warning('SCAN', "Bỏ qua liên kết tượng trưng (symlink): {$fullPath}");
                continue;
            }

            if ($item->isFile()) {
                // 2. Kiểm tra phần mở rộng loại trừ
                $ext = strtolower('.' . pathinfo($fullPath, PATHINFO_EXTENSION));
                if (in_array($ext, $excludedExtensions, true)) {
                    continue;
                }

                // 3. Chuẩn hóa đường dẫn tương đối (luôn dùng '/')
                $rel = substr($fullPath, strlen($sourceReal));
                $normalizedRel = ltrim(str_replace(['\\', '//'], '/', $rel), '/');

                // Kiểm tra an toàn chống path traversal
                SecurityValidator::assertSafeRelativePath($normalizedRel);

                $files[$normalizedRel] = [
                    'full_path' => $fullPath,
                    'relative_path' => $normalizedRel,
                    'size' => $item->getSize(),
                    'mtime' => date('Y-m-d H:i:s', $item->getMTime()),
                ];
            }
        }

        ksort($files);
        return $files;
    }

    /**
     * XEM TRƯỚC (PREVIEW): So sánh nguồn hiện tại với phiên bản gần nhất mà không thay đổi CSDL
     */
    public function preview(int $jobId): array {
        $job = $this->getJob($jobId);
        $excluded = array_filter(array_map('trim', explode(',', strtolower($job['excluded_extensions'] ?? ''))));

        $currentFiles = $this->scanSourceDirectory($job['source_path'], $excluded);
        $latestVersion = $this->getLatestCompletedVersion($jobId);

        $oldEntries = $latestVersion ? $this->getVersionEntriesMap((int)$latestVersion['id']) : [];

        $diff = [
            'added' => [],
            'modified' => [],
            'deleted' => [],
            'unchanged' => [],
            'all_items' => []
        ];

        $visited = [];

        foreach ($currentFiles as $relPath => $fileInfo) {
            $visited[$relPath] = true;
            $currentHash = hash_file('sha256', $fileInfo['full_path']);

            if (!isset($oldEntries[$relPath]) || $oldEntries[$relPath]['change_type'] === 'DELETED') {
                // Tệp mới
                $item = [
                    'relative_path' => $relPath,
                    'size' => $fileInfo['size'],
                    'status' => 'ADDED',
                    'hash' => $currentHash,
                    'mtime' => $fileInfo['mtime']
                ];
                $diff['added'][] = $item;
                $diff['all_items'][] = $item;
            } else {
                $oldItem = $oldEntries[$relPath];
                if ($oldItem['verified_hash'] !== $currentHash) {
                    // Tệp bị sửa đổi nội dung
                    $item = [
                        'relative_path' => $relPath,
                        'size' => $fileInfo['size'],
                        'status' => 'MODIFIED',
                        'hash' => $currentHash,
                        'old_hash' => $oldItem['verified_hash'],
                        'mtime' => $fileInfo['mtime']
                    ];
                    $diff['modified'][] = $item;
                    $diff['all_items'][] = $item;
                } else {
                    // Tệp không thay đổi
                    $item = [
                        'relative_path' => $relPath,
                        'size' => $fileInfo['size'],
                        'status' => 'UNCHANGED',
                        'hash' => $currentHash,
                        'mtime' => $fileInfo['mtime']
                    ];
                    $diff['unchanged'][] = $item;
                    $diff['all_items'][] = $item;
                }
            }
        }

        // Kiểm tra các tệp bị xóa (có ở bản cũ nhưng không còn ở nguồn hiện tại)
        foreach ($oldEntries as $relPath => $oldItem) {
            if (!isset($visited[$relPath]) && $oldItem['change_type'] !== 'DELETED') {
                $item = [
                    'relative_path' => $relPath,
                    'size' => (int)$oldItem['file_size'],
                    'status' => 'DELETED',
                    'hash' => $oldItem['verified_hash'],
                    'mtime' => $oldItem['file_mtime']
                ];
                $diff['deleted'][] = $item;
                $diff['all_items'][] = $item;
            }
        }

        $summary = [
            'total_current_files' => count($currentFiles),
            'added_count' => count($diff['added']),
            'modified_count' => count($diff['modified']),
            'deleted_count' => count($diff['deleted']),
            'unchanged_count' => count($diff['unchanged']),
            'latest_version_number' => $latestVersion ? (int)$latestVersion['version_number'] : 0,
            'next_version_number' => ($latestVersion ? (int)$latestVersion['version_number'] : 0) + 1
        ];

        return [
            'job' => $job,
            'summary' => $summary,
            'diff' => $diff
        ];
    }

    /**
     * THỰC HIỆN SAO LƯU TĂNG DẦN (Giao dịch an toàn Atomic Versioning)
     * Đáp ứng Yêu cầu kỹ thuật 1 & 2
     */
    public function executeBackup(int $jobId, bool $simulateAbort = false): array {
        $previewData = $this->preview($jobId);
        $job = $previewData['job'];
        $diff = $previewData['diff'];
        $nextVersionNum = $previewData['summary']['next_version_number'];

        // 1. Tạo phiên bản ở trạng thái IN_PROGRESS
        $stmtVer = $this->pdo->prepare("
            INSERT INTO `versions` (`job_id`, `version_number`, `status`, `started_at`)
            VALUES (?, ?, 'IN_PROGRESS', NOW())
        ");
        $stmtVer->execute([$jobId, $nextVersionNum]);
        $versionId = (int)$this->pdo->lastInsertId();

        Logger::info('BACKUP_START', "Bắt đầu sao lưu phiên bản v{$nextVersionNum} cho tác vụ: {$job['name']}", $jobId, $versionId);

        try {
            $totalFiles = 0;
            $totalBytes = 0;
            $addedCount = 0;
            $modifiedCount = 0;
            $deletedCount = 0;
            $unchangedCount = 0;

            $stmtEntry = $this->pdo->prepare("
                INSERT INTO `entries` (`version_id`, `object_id`, `relative_path`, `file_size`, `file_mtime`, `change_type`, `verified_hash`)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $latestVersion = $this->getLatestCompletedVersion($jobId);
            $oldEntries = $latestVersion ? $this->getVersionEntriesMap((int)$latestVersion['id']) : [];

            $processedCount = 0;

            // Xử lý các tệp hiện tại trong nguồn
            foreach ($diff['all_items'] as $item) {
                // Mô phỏng gián đoạn giữa chừng nếu simulateAbort = true (Phục vụ Phép thử 4)
                if ($simulateAbort && $processedCount >= 2) {
                    throw new RuntimeException("MÔ PHỎNG GIÁN ĐOẠN ĐỘT NGỘT: Đang sao lưu thì mất điện hoặc tiến trình bị hủy!");
                }

                $relPath = $item['relative_path'];
                $status = $item['status'];

                if ($status === 'DELETED') {
                    // Bản ghi xóa: object_id = NULL, change_type = 'DELETED'
                    // Tệp bị xóa ở bản mới KHÔNG làm mất object ở bản cũ (Yêu cầu kỹ thuật 1)
                    $stmtEntry->execute([
                        $versionId,
                        null,
                        $relPath,
                        $item['size'],
                        $item['mtime'],
                        'DELETED',
                        $item['hash']
                    ]);
                    $deletedCount++;
                } else {
                    $sourceFile = SecurityValidator::normalizePath($job['source_path']) . '/' . $relPath;

                    // Lưu nội dung vào kho CAS (tự động khử trùng lặp)
                    $storeRes = $this->storage->storeFile($sourceFile);

                    $stmtEntry->execute([
                        $versionId,
                        $storeRes['object_id'],
                        $relPath,
                        $storeRes['size'],
                        $item['mtime'],
                        $status,
                        $storeRes['sha256']
                    ]);

                    $totalFiles++;
                    $totalBytes += $storeRes['size'];

                    if ($status === 'ADDED') $addedCount++;
                    elseif ($status === 'MODIFIED') $modifiedCount++;
                    elseif ($status === 'UNCHANGED') $unchangedCount++;
                }

                $processedCount++;
            }

            // 2. Chuyển trạng thái sang COMPLETED sau khi hoàn tất toàn bộ
            $updateVer = $this->pdo->prepare("
                UPDATE `versions` SET 
                    `status` = 'COMPLETED',
                    `total_files` = ?,
                    `total_bytes` = ?,
                    `added_count` = ?,
                    `modified_count` = ?,
                    `deleted_count` = ?,
                    `unchanged_count` = ?,
                    `completed_at` = NOW()
                WHERE `id` = ?
            ");
            $updateVer->execute([
                $totalFiles,
                $totalBytes,
                $addedCount,
                $modifiedCount,
                $deletedCount,
                $unchangedCount,
                $versionId
            ]);

            Logger::info('BACKUP_SUCCESS', "Phiên bản v{$nextVersionNum} sao lưu THÀNH CÔNG! Đã lưu {$totalFiles} tệp ({$addedCount} mới, {$modifiedCount} sửa, {$deletedCount} xóa)", $jobId, $versionId);

            // Tự động sao lưu dữ liệu tài khoản người dùng gắn liền với phiên bản sao lưu
            require_once __DIR__ . '/Auth.php';
            Auth::exportAccountsBackup();


            return [
                'status' => 'COMPLETED',
                'version_id' => $versionId,
                'version_number' => $nextVersionNum,
                'total_files' => $totalFiles,
                'total_bytes' => $totalBytes,
                'added_count' => $addedCount,
                'modified_count' => $modifiedCount,
                'deleted_count' => $deletedCount,
                'unchanged_count' => $unchangedCount
            ];

        } catch (Exception $e) {
            // Khi bị gián đoạn hoặc gặp sự cố: ĐÁNH DẤU LÀ FAILED, KHÔNG CÔNG BỐ LÀ HOÀN CHỈNH
            // Đáp ứng Yêu cầu kỹ thuật 2 & Phép thử 4
            $updateFail = $this->pdo->prepare("UPDATE `versions` SET `status` = 'FAILED' WHERE `id` = ?");
            $updateFail->execute([$versionId]);

            Logger::error('BACKUP_ABORTED', "Phiên bản v{$nextVersionNum} BỊ GIÁN ĐOẠN HOẶC LỖI: " . $e->getMessage(), $jobId, $versionId);

            return [
                'status' => 'FAILED',
                'version_id' => $versionId,
                'version_number' => $nextVersionNum,
                'error' => $e->getMessage()
            ];
        }
    }
}
