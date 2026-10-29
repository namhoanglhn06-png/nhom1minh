<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Lớp ghi nhật ký hoạt động hệ thống vào MySQL và file
 */
class Logger {

    public static function log(string $stage, string $message, string $level = 'INFO', ?int $jobId = null, ?int $versionId = null, ?string $fileTarget = null): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO `run_logs` (`job_id`, `version_id`, `log_level`, `stage`, `message`, `file_target`, `created_at`)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$jobId, $versionId, strtoupper($level), $stage, $message, $fileTarget]);
        } catch (Exception $e) {
            // Không làm gián đoạn luồng chính nếu lỗi log
            error_log("[Logger Error] " . $e->getMessage());
        }
    }

    public static function info(string $stage, string $message, ?int $jobId = null, ?int $versionId = null, ?string $fileTarget = null): void {
        self::log($stage, $message, 'INFO', $jobId, $versionId, $fileTarget);
    }

    public static function warning(string $stage, string $message, ?int $jobId = null, ?int $versionId = null, ?string $fileTarget = null): void {
        self::log($stage, $message, 'WARNING', $jobId, $versionId, $fileTarget);
    }

    public static function error(string $stage, string $message, ?int $jobId = null, ?int $versionId = null, ?string $fileTarget = null): void {
        self::log($stage, $message, 'ERROR', $jobId, $versionId, $fileTarget);
    }
}
