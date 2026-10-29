<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Logger.php';

/**
 * Quản lý kho lưu trữ tệp vật lý khử trùng lặp (Content-Addressable Storage - CAS)
 * Đáp ứng Phép thử 2 và Yêu cầu kỹ thuật 1
 */
class DeduplicationStorage {
    private string $storageDir;
    private PDO $pdo;

    public function __construct(?string $customStorageDir = null) {
        $this->pdo = Database::getConnection();
        $this->storageDir = $customStorageDir ?? realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'objects';
        
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
    }

    /**
     * Lấy đường dẫn lưu trữ tệp vật lý theo mã băm SHA-256
     */
    public function getObjectPhysicalPath(string $sha256Hash): string {
        $sub1 = substr($sha256Hash, 0, 2);
        $sub2 = substr($sha256Hash, 2, 2);
        $dir = $this->storageDir . DIRECTORY_SEPARATOR . $sub1 . DIRECTORY_SEPARATOR . $sub2;
        
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $sha256Hash;
    }

    /**
     * Băm nội dung và lưu trữ vào kho vật lý (khử trùng lặp nội dung)
     * Trả về: ['object_id' => int, 'sha256' => string, 'is_new_physical' => bool, 'size' => int]
     */
    public function storeFile(string $sourceFilePath): array {
        if (!file_exists($sourceFilePath) || !is_readable($sourceFilePath)) {
            throw new RuntimeException("Tệp nguồn không tồn tại hoặc không thể đọc: {$sourceFilePath}");
        }

        $fileSize = filesize($sourceFilePath);
        $sha256 = hash_file('sha256', $sourceFilePath);

        // 1. Kiểm tra xem mã băm này đã tồn tại trong CSDL hay chưa
        $stmt = $this->pdo->prepare("SELECT `id`, `storage_path`, `ref_count` FROM `objects` WHERE `sha256_hash` = ? LIMIT 1");
        $stmt->execute([$sha256]);
        $existing = $stmt->fetch();

        $physicalPath = $this->getObjectPhysicalPath($sha256);

        if ($existing && file_exists($physicalPath)) {
            // Tệp nội dung trùng lặp đã tồn tại -> KHÔNG chép lại vật lý, chỉ tăng ref_count (Deduplication)
            $updateStmt = $this->pdo->prepare("UPDATE `objects` SET `ref_count` = `ref_count` + 1 WHERE `id` = ?");
            $updateStmt->execute([$existing['id']]);

            return [
                'object_id' => (int)$existing['id'],
                'sha256' => $sha256,
                'is_new_physical' => false,
                'size' => $fileSize
            ];
        }

        // 2. Tệp nội dung mới -> Sao chép vật lý vào kho lưu trữ
        if (!copy($sourceFilePath, $physicalPath)) {
            throw new RuntimeException("Không thể sao chép tệp vào kho lưu trữ: {$physicalPath}");
        }

        // Ghi vào bảng objects
        if ($existing) {
            $objectId = (int)$existing['id'];
            $updateStmt = $this->pdo->prepare("UPDATE `objects` SET `storage_path` = ?, `ref_count` = `ref_count` + 1 WHERE `id` = ?");
            $updateStmt->execute([$physicalPath, $objectId]);
        } else {
            $insertStmt = $this->pdo->prepare("
                INSERT INTO `objects` (`sha256_hash`, `file_size`, `storage_path`, `ref_count`, `created_at`)
                VALUES (?, ?, ?, 1, NOW())
            ");
            $insertStmt->execute([$sha256, $fileSize, $physicalPath]);
            $objectId = (int)$this->pdo->lastInsertId();
        }

        return [
            'object_id' => $objectId,
            'sha256' => $sha256,
            'is_new_physical' => true,
            'size' => $fileSize
        ];
    }

    /**
     * Xác minh tính toàn vẹn của một object lưu trữ bằng mã băm SHA-256
     * Phục vụ Phép thử 3 (phát hiện hỏng hóc)
     */
    public function verifyObject(int $objectId): array {
        $stmt = $this->pdo->prepare("SELECT `sha256_hash`, `storage_path`, `file_size` FROM `objects` WHERE `id` = ?");
        $stmt->execute([$objectId]);
        $obj = $stmt->fetch();

        if (!$obj) {
            return ['valid' => false, 'error' => "Không tìm thấy object ID: {$objectId} trong CSDL"];
        }

        $physicalPath = $obj['storage_path'];
        if (!file_exists($physicalPath)) {
            return ['valid' => false, 'error' => "Tệp vật lý bị mất tại đường dẫn: {$physicalPath}"];
        }

        $actualHash = hash_file('sha256', $physicalPath);
        if ($actualHash !== $obj['sha256_hash']) {
            return [
                'valid' => false,
                'error' => "MÃ BĂM SAI LỆCH! Kỳ vọng: {$obj['sha256_hash']}, Thực tế: {$actualHash}",
                'expected_hash' => $obj['sha256_hash'],
                'actual_hash' => $actualHash
            ];
        }

        return ['valid' => true, 'sha256' => $actualHash, 'size' => filesize($physicalPath)];
    }

    /**
     * Thống kê kho lưu trữ khử trùng lặp (hiệu quả tiết kiệm dung lượng)
     */
    public function getStorageStats(): array {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_objects, SUM(file_size) AS total_physical_size, SUM(ref_count) AS total_references FROM `objects`");
        $row = $stmt->fetch();

        $stmtEntries = $this->pdo->query("SELECT SUM(file_size) AS total_logical_size FROM `entries` WHERE `change_type` != 'DELETED'");
        $entriesRow = $stmtEntries->fetch();

        $physical = (int)($row['total_physical_size'] ?? 0);
        $logical = (int)($entriesRow['total_logical_size'] ?? 0);
        $saved = max(0, $logical - $physical);
        $savedRatio = $logical > 0 ? round(($saved / $logical) * 100, 1) : 0;

        return [
            'total_objects' => (int)($row['total_objects'] ?? 0),
            'total_physical_size' => $physical,
            'total_logical_size' => $logical,
            'saved_bytes' => $saved,
            'saved_ratio' => $savedRatio
        ];
    }
}
