<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Lớp xử lý Xác thực (Authentication) & Phân quyền (RBAC)
 * Bảo vệ toàn bộ hệ thống khỏi truy cập trái phép.
 * Hỗ trợ Sao lưu & Phục hồi danh sách tài khoản người dùng.
 */
class Auth {
    private static bool $sessionStarted = false;

    /**
     * Khởi động session an toàn
     */
    public static function startSession(): void {
        if (!self::$sessionStarted && session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                ini_set('session.cookie_httponly', '1');
                ini_set('session.use_only_cookies', '1');
                session_name('BACKUPTOOL_AUTH_SESSION');
                session_start();
            }
            self::$sessionStarted = true;
        }
    }

    /**
     * Kiểm tra trạng thái đã đăng nhập
     */
    public static function check(): bool {
        self::startSession();
        return !empty($_SESSION['auth_user_id']);
    }

    /**
     * Lấy thông tin người dùng đang đăng nhập
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('SELECT id, username, email, full_name, role, avatar, created_at, last_login, status FROM users WHERE id = ? AND status = "ACTIVE" LIMIT 1');
            $stmt->execute([$_SESSION['auth_user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                return $user;
            }
        } catch (Exception $e) {
            // Log fallback
        }

        // Nếu tài khoản không còn hợp lệ trong DB, huỷ session
        self::logout();
        return null;
    }

    /**
     * Chặn truy cập trái phép - Bắt buộc phải đăng nhập
     */
    public static function requireAuth(): void {
        self::startSession();
        if (!self::check() || self::user() === null) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
            header("Location: login.php?redirect={$redirect}&error=" . urlencode("Vui lòng đăng nhập để truy cập hệ thống!"));
            exit;
        }
    }

    /**
     * Kiểm tra người dùng hiện tại có phải là Quản trị viên (Admin) không
     */
    public static function isAdmin(): bool {
        $user = self::user();
        return $user !== null && ($user['role'] ?? '') === 'ADMIN';
    }

    /**
     * Bắt buộc quyền Quản trị viên (Admin)
     */
    public static function requireAdmin(): void {
        self::requireAuth();
        if (!self::isAdmin()) {
            http_response_code(403);
            die('
            <div style="font-family: sans-serif; background: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center;">
                <div style="background: #1e293b; padding: 32px; border-radius: 12px; border: 1px solid #ef4444; max-width: 450px; text-align: center;">
                    <h2 style="color: #ef4444; margin-bottom: 12px;">⛔ 403 - Quyền Truy Cập Bị Từ Chối</h2>
                    <p style="color: #94a3b8; font-size: 14px; line-height: 1.6; margin-bottom: 20px;">
                        Tài khoản của bạn là <strong>Nhân viên vận hành (Operator)</strong>. Thao tác cấu hình hệ thống này chỉ dành riêng cho <strong>Quản trị viên (Admin)</strong>.
                    </p>
                    <a href="index.php" style="display: inline-block; background: #3b82f6; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 14px;">Quay Lại Dashboard</a>
                </div>
            </div>');
        }
    }

    /**
     * Đăng nhập hệ thống (bằng Username hoặc Email)
     */
    public static function login(string $identity, string $password): array {
        self::startSession();
        $identity = trim($identity);

        if (empty($identity) || empty($password)) {
            return ['success' => false, 'message' => 'Vui lòng nhập tên đăng nhập/email và mật khẩu.'];
        }

        try {
            $pdo = Database::getConnection();

            // Đảm bảo luôn có tài khoản Quản trị viên mặc định nếu bảng trống
            try {
                $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if ($userCount === 0) {
                    $defaultHash = password_hash('admin123', PASSWORD_BCRYPT);
                    $ins = $pdo->prepare('INSERT INTO users (id, username, email, password_hash, full_name, role) VALUES (1, "admin", "admin@backuptool.local", ?, "Quản Trị Viên Hệ Thống", "ADMIN")');
                    $ins->execute([$defaultHash]);
                }
            } catch (Exception $e) {}

            $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1');
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['success' => false, 'message' => 'Tài khoản hoặc email không tồn tại trong hệ thống.'];
            }

            if ($user['status'] !== 'ACTIVE') {
                return ['success' => false, 'message' => 'Tài khoản của bạn hiện đang bị tạm khóa. Vui lòng liên hệ Quản trị viên.'];
            }

            if (!password_verify($password, $user['password_hash'])) {
                self::recordLog('WARNING', 'Đăng nhập thất bại: Sai mật khẩu cho tài khoản [' . $identity . ']');
                return ['success' => false, 'message' => 'Mật khẩu đăng nhập không chính xác!'];
            }

            // Đăng nhập thành công -> Tái tạo Session ID chống Session Fixation
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['auth_user_id'] = (int)$user['id'];
            $_SESSION['auth_user_name'] = $user['username'];
            $_SESSION['auth_full_name'] = $user['full_name'];
            $_SESSION['auth_role'] = $user['role'];

            // Cập nhật thời gian đăng nhập cuối
            $upStmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $upStmt->execute([$user['id']]);

            self::recordLog('INFO', "Người dùng [{$user['username']}] ({$user['role']}) đã đăng nhập thành công.");

            return [
                'success' => true,
                'message' => 'Đăng nhập thành công!',
                'user' => $user
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Lỗi kết nối cơ sở dữ liệu: ' . $e->getMessage()];
        }
    }

    /**
     * Đăng ký tài khoản người dùng mới
     */
    public static function register(array $data): array {
        self::startSession();

        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $password = (string)($data['password'] ?? '');
        $passwordConfirm = (string)($data['password_confirm'] ?? '');
        $role = in_array($data['role'] ?? '', ['ADMIN', 'OPERATOR', 'VIEWER'], true) ? $data['role'] : 'OPERATOR';

        // 1. Kiểm tra validation
        $errors = [];
        if (empty($fullName)) {
            $errors[] = 'Họ và tên không được để trống.';
        }

        if (empty($username) || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $errors[] = 'Tên đăng nhập phải từ 3 đến 30 ký tự, chỉ gồm chữ cái, số và dấu gạch dưới (_).';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Địa chỉ email không hợp lệ.';
        }

        if (strlen($password) < 6) {
            $errors[] = 'Mật khẩu phải có độ dài tối thiểu 6 ký tự.';
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'Mật khẩu xác nhận không trùng khớp.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'message' => implode(' ', $errors), 'errors' => $errors];
        }

        try {
            $pdo = Database::getConnection();

            // 2. Kiểm tra trùng username hoặc email
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ((int)$stmt->fetchColumn() > 0) {
                return ['success' => false, 'message' => "Tên đăng nhập '{$username}' đã có người sử dụng. Vui lòng chọn tên khác."];
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ((int)$stmt->fetchColumn() > 0) {
                return ['success' => false, 'message' => "Địa chỉ email '{$email}' đã được đăng ký. Vui lòng dùng email khác hoặc đăng nhập."];
            }

            // 3. Băm mật khẩu bằng Bcrypt chuẩn an toàn
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            // 4. Lưu vào Database
            $insert = $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$username, $email, $passwordHash, $fullName, $role]);
            $newId = (int)$pdo->lastInsertId();

            self::recordLog('INFO', "Đã tạo tài khoản mới: [{$username}] ({$role}) - Email: {$email}");

            // Tự động sao lưu tài khoản vừa tạo vào bản ghi snapshot lưu trữ
            self::exportAccountsBackup();

            return [
                'success' => true,
                'message' => 'Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay.',
                'user_id' => $newId
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Lỗi lưu tài khoản: ' . $e->getMessage()];
        }
    }

    /**
     * Đăng xuất khỏi hệ thống
     */
    public static function logout(): void {
        self::startSession();
        $username = $_SESSION['auth_user_name'] ?? 'Khách';
        self::recordLog('INFO', "Người dùng [{$username}] đã đăng xuất.");

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Lấy danh sách tất cả tài khoản người dùng
     */
    public static function getAllUsers(): array {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT id, username, email, full_name, role, created_at, last_login, status FROM users ORDER BY id ASC');
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * SAO LƯU HỆ THỐNG TÀI KHOẢN (Auth Backup Snapshot)
     * Xuất toàn bộ dữ liệu tài khoản người dùng thành file JSON/SQL lưu trong kho lưu trữ
     */
    public static function exportAccountsBackup(?string $customDir = null): string {
        $backupDir = $customDir ?? __DIR__ . '/../storage/auth_backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT id, username, email, password_hash, full_name, role, avatar, created_at, last_login, status FROM users');
            $users = $stmt->fetchAll();

            $backupData = [
                'system' => 'BackupTool Authentication Snapshot',
                'exported_at' => date('Y-m-d H:i:s'),
                'total_accounts' => count($users),
                'checksum' => hash('sha256', json_encode($users)),
                'users' => $users
            ];

            $fileName = 'users_auth_backup_' . date('Ymd_His') . '.json';
            $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

            file_put_contents($filePath, json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // Đồng thời lưu bản latest
            file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'latest_users_backup.json', json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            self::recordLog('INFO', "Đã tạo bản sao lưu tài khoản người dùng: {$fileName} (" . count($users) . " tài khoản).");

            return $filePath;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * KHÔI PHỤC DANH SÁCH TÀI KHOẢN TỪ BẢN SAO LƯU (Restore Auth Accounts)
     */
    public static function restoreAccountsFromBackup(string $jsonContent): array {
        try {
            $data = json_decode($jsonContent, true);
            if (!isset($data['users']) || !is_array($data['users'])) {
                return ['success' => false, 'message' => 'Tệp sao lưu không đúng định dạng chuẩn của hệ thống.'];
            }

            $pdo = Database::getConnection();
            $pdo->beginTransaction();

            $insertOrUpdate = $pdo->prepare('
                INSERT INTO users (username, email, password_hash, full_name, role, status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    password_hash = VALUES(password_hash),
                    full_name = VALUES(full_name),
                    role = VALUES(role),
                    status = VALUES(status)
            ');

            $count = 0;
            foreach ($data['users'] as $u) {
                if (!empty($u['username']) && !empty($u['password_hash'])) {
                    $insertOrUpdate->execute([
                        $u['username'],
                        $u['email'] ?? ($u['username'] . '@backuptool.local'),
                        $u['password_hash'],
                        $u['full_name'] ?? $u['username'],
                        $u['role'] ?? 'OPERATOR',
                        $u['status'] ?? 'ACTIVE'
                    ]);
                    $count++;
                }
            }

            $pdo->commit();
            self::recordLog('INFO', "Đã khôi phục thành công {$count} tài khoản người dùng từ bản sao lưu.");

            return ['success' => true, 'message' => "Đã khôi phục thành công {$count} tài khoản từ bản sao lưu!", 'count' => $count];
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Lỗi khôi phục tài khoản: ' . $e->getMessage()];
        }
    }

    /**
     * XUẤT BẢN SAO LƯU TÀI KHOẢN DƯỚI DẠNG SQL SCRIPT (.SQL)
     * Có thể chạy trực tiếp trong phpMyAdmin hoặc MySQL Command Line
     */
    public static function exportAccountsSqlBackup(?string $customDir = null): string {
        $backupDir = $customDir ?? __DIR__ . '/../storage/auth_backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT * FROM `users`');
            $users = $stmt->fetchAll();

            $sql = "-- ========================================================\n";
            $sql .= "-- SAO LƯU TÀI KHOẢN NGƯỜI DÙNG & XÁC THỰC (BTL PTPMMNM)\n";
            $sql .= "-- Xuất lúc: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Tổng số tài khoản: " . count($users) . "\n";
            $sql .= "-- ========================================================\n\n";
            $sql .= "USE `btl_backup_db`;\n\n";

            if (!empty($users)) {
                $sql .= "INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `status`, `created_at`, `last_login`)\nVALUES\n";
                $rows = [];
                foreach ($users as $u) {
                    $id = (int)$u['id'];
                    $un = $pdo->quote($u['username']);
                    $em = $pdo->quote($u['email']);
                    $pw = $pdo->quote($u['password_hash']);
                    $fn = $pdo->quote($u['full_name']);
                    $ro = $pdo->quote($u['role']);
                    $st = $pdo->quote($u['status']);
                    $ca = $pdo->quote($u['created_at']);
                    $ll = $u['last_login'] ? $pdo->quote($u['last_login']) : "NULL";
                    $rows[] = "  ({$id}, {$un}, {$em}, {$pw}, {$fn}, {$ro}, {$st}, {$ca}, {$ll})";
                }
                $sql .= implode(",\n", $rows);
                $sql .= "\nON DUPLICATE KEY UPDATE\n";
                $sql .= "  `password_hash` = VALUES(`password_hash`),\n";
                $sql .= "  `full_name` = VALUES(`full_name`),\n";
                $sql .= "  `role` = VALUES(`role`),\n";
                $sql .= "  `status` = VALUES(`status`);\n\n";
            }

            $fileName = 'users_auth_backup_' . date('Ymd_His') . '.sql';
            $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents($filePath, $sql);

            // Đồng thời lưu bản latest
            file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'latest_users_backup.sql', $sql);

            self::recordLog('INFO', "Đã tạo bản sao lưu tài khoản dạng SQL: {$fileName}");
            return $filePath;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * XUẤT TOÀN BỘ CƠ SỞ DỮ LIỆU MYSQL (.SQL DUMP)
     * Sao lưu trọn gói toàn bộ hệ thống gồm tất cả 7 bảng
     */
    public static function exportFullDatabaseSqlBackup(?string $customDir = null): string {
        $backupDir = $customDir ?? __DIR__ . '/../storage/auth_backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            $pdo = Database::getConnection();
            $tables = ['backup_jobs', 'objects', 'versions', 'entries', 'run_logs', 'schedules', 'users'];

            $sql = "-- ========================================================\n";
            $sql .= "-- FULL DATABASE DUMP: BTL PTPMMNM BACKUP & RESTORE TOOL\n";
            $sql .= "-- Thời gian xuất: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Tương thích: MySQL 5.7+ / MariaDB 10.4+\n";
            $sql .= "-- ========================================================\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
            $sql .= "START TRANSACTION;\n\n";
            $sql .= "CREATE DATABASE IF NOT EXISTS `btl_backup_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
            $sql .= "USE `btl_backup_db`;\n\n";

            foreach ($tables as $tbl) {
                // Lấy CREATE TABLE
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch();
                if ($createStmt && isset($createStmt[1])) {
                    $sql .= "-- Cấu trúc bảng: `{$tbl}`\n";
                    $sql .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
                    $sql .= $createStmt[1] . ";\n\n";
                }

                // Lấy Dữ liệu
                $dataStmt = $pdo->query("SELECT * FROM `{$tbl}`");
                $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $sql .= "-- Dữ liệu bảng: `{$tbl}` (" . count($rows) . " bản ghi)\n";
                    $cols = array_keys($rows[0]);
                    $colsEscaped = implode(', ', array_map(fn($c) => "`{$c}`", $cols));

                    $valLines = [];
                    foreach ($rows as $row) {
                        $escapedVals = array_map(function($v) use ($pdo) {
                            if ($v === null) return 'NULL';
                            return $pdo->quote((string)$v);
                        }, array_values($row));
                        $valLines[] = "  (" . implode(', ', $escapedVals) . ")";
                    }
                    $sql .= "INSERT INTO `{$tbl}` ({$colsEscaped}) VALUES\n" . implode(",\n", $valLines) . ";\n\n";
                }
            }

            $sql .= "COMMIT;\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            $sql .= "-- Kết thúc bản sao lưu toàn vẹn.\n";

            $fileName = 'full_database_dump_' . date('Ymd_His') . '.sql';
            $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents($filePath, $sql);

            self::recordLog('INFO', "Đã tạo bản sao lưu toàn bộ cơ sở dữ liệu: {$fileName}");
            return $filePath;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * KHÔI PHỤC CƠ SỞ DỮ LIỆU TỪ TỆP SQL SCRIPT
     */
    public static function restoreFromSqlContent(string $sqlContent): array {
        try {
            $pdo = Database::getConnection();
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
            $pdo->exec($sqlContent);
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");

            self::recordLog('INFO', "Đã khôi phục dữ liệu từ tệp SQL thành công.");
            return ['success' => true, 'message' => 'Đã thực thi và khôi phục cơ sở dữ liệu từ tệp SQL thành công!'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Lỗi khi thực thi câu lệnh SQL: ' . $e->getMessage()];
        }
    }

    /**
     * Cập nhật thông tin người dùng bởi Quản trị viên (Admin)
     */
    public static function updateUser(int $userId, array $data): array {
        try {
            $pdo = Database::getConnection();
            $fullName = trim($data['full_name'] ?? '');
            $email = trim($data['email'] ?? '');
            $role = in_array($data['role'] ?? '', ['ADMIN', 'OPERATOR', 'VIEWER'], true) ? $data['role'] : 'OPERATOR';
            $status = in_array($data['status'] ?? '', ['ACTIVE', 'SUSPENDED'], true) ? $data['status'] : 'ACTIVE';
            $password = trim($data['password'] ?? '');

            if (empty($fullName) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Họ tên và địa chỉ email không hợp lệ.'];
            }

            // Kiểm tra trùng email với tài khoản khác
            $checkEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
            $checkEmail->execute([$email, $userId]);
            if ((int)$checkEmail->fetchColumn() > 0) {
                return ['success' => false, 'message' => "Email '{$email}' đã được sử dụng bởi tài khoản khác."];
            }

            // Bảo vệ không thể hạ quyền hoặc khóa tài khoản admin root ID 1
            if ($userId === 1 && ($role !== 'ADMIN' || $status !== 'ACTIVE')) {
                return ['success' => false, 'message' => 'Không thể hạ quyền hoặc tạm khóa tài khoản Quản trị viên gốc (#1)!'];
            }

            if (!empty($password)) {
                if (strlen($password) < 6) {
                    return ['success' => false, 'message' => 'Mật khẩu mới phải có tối thiểu 6 ký tự.'];
                }
                $pwHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, status = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$fullName, $email, $role, $status, $pwHash, $userId]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?');
                $stmt->execute([$fullName, $email, $role, $status, $userId]);
            }

            self::recordLog('INFO', "Quản trị viên đã cập nhật tài khoản ID #{$userId} ({$role} - {$status})");
            self::exportAccountsBackup();

            return ['success' => true, 'message' => "Đã cập nhật thông tin tài khoản ID #{$userId} thành công!"];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Lỗi khi cập nhật tài khoản: ' . $e->getMessage()];
        }
    }

    /**
     * Xóa tài khoản người dùng bởi Quản trị viên
     */
    public static function deleteUser(int $userId, int $currentUserId): array {
        if ($userId === $currentUserId) {
            return ['success' => false, 'message' => 'Bạn không thể tự xóa tài khoản của chính mình khi đang đăng nhập!'];
        }

        if ($userId === 1) {
            return ['success' => false, 'message' => 'Không thể xóa tài khoản Quản trị viên gốc (#1) của hệ thống!'];
        }

        try {
            $pdo = Database::getConnection();
            $stmtUser = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $stmtUser->execute([$userId]);
            $username = $stmtUser->fetchColumn();

            if (!$username) {
                return ['success' => false, 'message' => 'Tài khoản cần xóa không tồn tại.'];
            }

            $delStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $delStmt->execute([$userId]);

            self::recordLog('WARNING', "Đã xóa tài khoản người dùng [{$username}] (ID #{$userId})");
            self::exportAccountsBackup();

            return ['success' => true, 'message' => "Đã xóa thành công tài khoản '{$username}'!"];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Lỗi khi xóa tài khoản: ' . $e->getMessage()];
        }
    }

    /**
     * Ghi nhật ký vào bảng run_logs
     */
    private static function recordLog(string $level, string $message): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('INSERT INTO run_logs (log_level, stage, message) VALUES (?, "AUTH", ?)');
            $stmt->execute([$level, $message]);
        } catch (Exception $e) {
            // bỏ qua nếu lỗi ghi log
        }
    }
}
