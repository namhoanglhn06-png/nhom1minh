<?php
declare(strict_types=1);

/**
 * Cấu hình kết nối cơ sở dữ liệu MySQL (PDO)
 * Tương thích chuẩn XAMPP MariaDB / MySQL
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'btl_backup_db');
define('DB_USER', 'root');
define('DB_PASS', '');

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Tự động khôi phục / khởi tạo nếu chưa có CSDL btl_backup_db trên XAMPP
                if ($e->getCode() == 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                    try {
                        $initPdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS, $options);
                        $initPdo->exec("CREATE DATABASE IF NOT EXISTS `btl_backup_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                        $schemaFile = __DIR__ . '/../database/schema.sql';
                        if (file_exists($schemaFile)) {
                            $initPdo->exec("USE `btl_backup_db`;");
                            $initPdo->exec(file_get_contents($schemaFile));
                        }
                        self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                        return self::$instance;
                    } catch (Exception $inner) {
                        // Bỏ qua lỗi phụ để hiện thông báo chi tiết
                    }
                }

                http_response_code(500);
                die('
                <div style="font-family: sans-serif; background: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;">
                    <div style="background: #1e293b; padding: 32px; border-radius: 12px; border: 1px solid #ef4444; max-width: 520px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
                        <h2 style="color: #ef4444; margin-bottom: 12px; font-size: 1.25rem;">⚠️ Lỗi Kết Nối Cơ Sở Dữ Liệu MySQL</h2>
                        <p style="color: #94a3b8; font-size: 14px; line-height: 1.6; margin-bottom: 16px;">
                            Không thể kết nối đến máy chủ MySQL trên cổng <strong>3306</strong>.<br>
                            Chi tiết: <code>' . htmlspecialchars($e->getMessage()) . '</code>
                        </p>
                        <div style="background: rgba(15,23,42,0.8); padding: 14px; border-radius: 8px; font-size: 13px; color: #38bdf8; margin-bottom: 20px;">
                            💡 <strong>Hướng dẫn khắc phục nhanh:</strong><br>
                            1. Mở <strong>XAMPP Control Panel</strong>.<br>
                            2. Bấm nút <strong>Start</strong> ở dòng <strong>MySQL</strong>.<br>
                            3. Tải lại trang web này.
                        </div>
                        <button onclick="window.location.reload();" style="background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600;">Thử Lại Ngay</button>
                    </div>
                </div>');
            }
        }
        return self::$instance;
    }
}
