<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getConnection();

    $sql = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `email` VARCHAR(150) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `full_name` VARCHAR(100) NOT NULL,
        `role` ENUM('ADMIN', 'OPERATOR', 'VIEWER') DEFAULT 'OPERATOR',
        `avatar` VARCHAR(255) NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `last_login` DATETIME NULL,
        `status` ENUM('ACTIVE', 'SUSPENDED') DEFAULT 'ACTIVE',
        INDEX `idx_users_username` (`username`),
        INDEX `idx_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);

    // Kiểm tra xem đã có tài khoản admin chưa
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE `username` = ?');
    $stmt->execute(['admin']);
    if ((int)$stmt->fetchColumn() === 0) {
        $adminHash = password_hash('admin123', PASSWORD_BCRYPT);
        $insert = $pdo->prepare('INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['admin', 'admin@backuptool.local', $adminHash, 'Quản Trị Viên Hệ Thống', 'ADMIN']);
        echo "[OK] Đã tạo bảng users và tạo tài khoản quản trị mặc định (admin / admin123)\n";
    } else {
        echo "[OK] Bảng users đã sẵn sàng, tài khoản admin đã tồn tại.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
