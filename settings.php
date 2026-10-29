<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/DeduplicationStorage.php';

// Bắt buộc đăng nhập
Auth::requireAuth();
$currentUser = Auth::user();

$pdo = Database::getConnection();
$storage = new DeduplicationStorage();
$stats = $storage->getStorageStats();

$message = '';
$messageType = '';

// Xử lý XUẤT BẢN SAO LƯU TÀI KHOẢN (DOWNLOAD AUTH BACKUP)
if (isset($_GET['action'])) {
    // 1. Tải bản sao lưu JSON tài khoản
    if ($_GET['action'] === 'download_auth_backup') {
        $filePath = Auth::exportAccountsBackup();
        if ($filePath && file_exists($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    // 2. Tải bản sao lưu SQL tài khoản người dùng
    elseif ($_GET['action'] === 'download_auth_sql') {
        $filePath = Auth::exportAccountsSqlBackup();
        if ($filePath && file_exists($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    // 3. Tải toàn bộ Database Dump (.SQL) cho tất cả các bảng (Chỉ Admin)
    elseif ($_GET['action'] === 'download_full_db_sql') {
        if (!Auth::isAdmin()) {
            die("Thao tác bị từ chối: Chỉ Quản trị viên (Admin) mới có quyền tải Full Database Dump!");
        }
        $filePath = Auth::exportFullDatabaseSqlBackup();
        if ($filePath && file_exists($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
}

// Xử lý FORM POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Kiểm tra quyền Quản trị viên đối với các tác vụ nhạy cảm
    if (in_array($action, ['run_gc', 'create_user', 'update_user', 'delete_user', 'restore_auth_backup'], true) && !Auth::isAdmin()) {
        $message = "Thao tác bị từ chối: Chỉ Quản trị viên (Admin) mới có quyền thực hiện chức năng này!";
        $messageType = 'danger';
    }

    // 1. DỌN DẸP KHO LƯU TRỮ CAS (GARBAGE COLLECTION)
    elseif ($action === 'run_gc') {
        $stmtGC = $pdo->query("SELECT id, storage_path FROM `objects` WHERE `id` NOT IN (SELECT DISTINCT object_id FROM `entries` WHERE object_id IS NOT NULL)");
        $orphaned = $stmtGC->fetchAll();
        $deletedCount = 0;
        foreach ($orphaned as $item) {
            if (file_exists($item['storage_path'])) {
                @unlink($item['storage_path']);
            }
            $pdo->prepare("DELETE FROM `objects` WHERE id = ?")->execute([$item['id']]);
            $deletedCount++;
        }
        $message = "Đã dọn dẹp thành công {$deletedCount} tệp không còn sử dụng trong kho lưu trữ!";
        $messageType = 'success';
    }

    // 2. TẠO BẢN SAO LƯU TÀI KHOẢN NGƯỜI DÙNG (BACKUP AUTH)
    elseif ($action === 'export_auth_backup') {
        $path = Auth::exportAccountsBackup();
        if ($path) {
            $message = "Đã tạo bản sao lưu tài khoản thành công tại: " . basename($path);
            $messageType = 'success';
        } else {
            $message = "Không thể tạo bản sao lưu tài khoản.";
            $messageType = 'danger';
        }
    }

    // 3. KHÔI PHỤC TÀI KHOẢN TỪ FILE TẢI LÊN (.JSON HOẶC .SQL)
    elseif ($action === 'restore_auth_backup') {
        if (!empty($_FILES['backup_file']['tmp_name'])) {
            $fileName = $_FILES['backup_file']['name'];
            $content = file_get_contents($_FILES['backup_file']['tmp_name']);
            if (str_ends_with(strtolower($fileName), '.sql')) {
                $res = Auth::restoreFromSqlContent($content);
            } else {
                $res = Auth::restoreAccountsFromBackup($content);
            }
            $message = $res['message'];
            $messageType = $res['success'] ? 'success' : 'danger';
        } elseif (!empty($_POST['restore_latest'])) {
            $latestPath = __DIR__ . '/storage/auth_backups/latest_users_backup.json';
            if (file_exists($latestPath)) {
                $content = file_get_contents($latestPath);
                $res = Auth::restoreAccountsFromBackup($content);
                $message = $res['message'];
                $messageType = $res['success'] ? 'success' : 'danger';
            } else {
                $message = "Chưa có bản sao lưu tài khoản nào được lưu trên hệ thống.";
                $messageType = 'danger';
            }
        } else {
            $message = "Vui lòng chọn tệp sao lưu (.json hoặc .sql) để khôi phục.";
            $messageType = 'danger';
        }
    }

    // 4. THÊM TÀI KHOẢN MỚI TỪ TRANG QUẢN TRỊ
    elseif ($action === 'create_user') {
        $res = Auth::register([
            'full_name'        => $_POST['full_name'] ?? '',
            'username'         => $_POST['username'] ?? '',
            'email'            => $_POST['email'] ?? '',
            'password'         => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password'] ?? '',
            'role'             => $_POST['role'] ?? 'OPERATOR'
        ]);
        $message = $res['message'];
        $messageType = $res['success'] ? 'success' : 'danger';
    }

    // 5. CẬP NHẬT THÔNG TIN CÁ NHÂN CỦA USER HIỆN TẠI
    elseif ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if (!empty($fullName) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $up = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
            $up->execute([$fullName, $email, $currentUser['id']]);
            $currentUser['full_name'] = $fullName;
            $currentUser['email'] = $email;
            $message = "Đã cập nhật thông tin cá nhân thành công!";
            $messageType = 'success';
        } else {
            $message = "Họ tên và email không hợp lệ.";
            $messageType = 'danger';
        }
    }

    // 6. CẬP NHẬT THÔNG TIN NGƯỜI DÙNG BỞI ADMIN
    elseif ($action === 'update_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = Auth::updateUser($targetUserId, [
            'full_name' => $_POST['full_name'] ?? '',
            'email'     => $_POST['email'] ?? '',
            'role'      => $_POST['role'] ?? 'OPERATOR',
            'status'    => $_POST['status'] ?? 'ACTIVE',
            'password'  => $_POST['password'] ?? ''
        ]);
        $message = $res['message'];
        $messageType = $res['success'] ? 'success' : 'danger';
    }

    // 7. XÓA TÀI KHOẢN NGƯỜI DÙNG BỞI ADMIN
    elseif ($action === 'delete_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = Auth::deleteUser($targetUserId, (int)$currentUser['id']);
        $message = $res['message'];
        $messageType = $res['success'] ? 'success' : 'danger';
    }
}

// Lấy danh sách tài khoản
$allUsers = Auth::getAllUsers();

// Kiểm tra danh sách bản sao lưu tài khoản hiện có
$authBackupFiles = [];
$authBackupDir = __DIR__ . '/storage/auth_backups';
if (is_dir($authBackupDir)) {
    $files = scandir($authBackupDir);
    foreach ($files as $f) {
        if ((str_ends_with($f, '.json') || str_ends_with($f, '.sql')) && !str_starts_with($f, 'latest_')) {
            $authBackupFiles[] = [
                'name' => $f,
                'path' => $authBackupDir . DIRECTORY_SEPARATOR . $f,
                'size' => filesize($authBackupDir . DIRECTORY_SEPARATOR . $f),
                'time' => date('d/m/Y H:i:s', filemtime($authBackupDir . DIRECTORY_SEPARATOR . $f))
            ];
        }
    }
    usort($authBackupFiles, fn($a, $b) => strcmp($b['name'], $a['name']));
}

$pageTitle = 'Cài Đặt Hệ Thống & Quản Lý / Sao Lưu User';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px;">
    <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
        Cài Đặt Hệ Thống & Quản Lý Người Dùng
    </h1>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
        Quản trị bảo mật, sao lưu tăng dần Content-Addressable Storage (CAS), quản lý tài khoản và sao lưu dữ liệu xác thực.
    </p>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>" style="margin-bottom: 24px;">
    <?= Icons::get($messageType === 'success' ? 'check' : 'alert') ?>
    <span><?= htmlspecialchars($message) ?></span>
</div>
<?php endif; ?>

<!-- CỘT 1 & CỘT 2: KHO CAS & THÔNG TIN TÀI KHOẢN ĐANG ĐĂNG NHẬP -->
<div class="content-grid" style="grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    <!-- CỘT 1: THIẾT LẬP KHO LƯU TRỮ CAS -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Cấu hình kho lưu trữ (CAS Storage)</div>
        </div>

        <div class="system-info-list" style="margin-bottom: 20px;">
            <div class="system-info-item">
                <span class="system-info-label">Thuật toán băm mã</span>
                <span class="system-info-val"><span class="code-pill">SHA-256 (FIPS 180-4)</span></span>
            </div>

            <div class="system-info-item">
                <span class="system-info-label">Thư mục vật lý</span>
                <span class="system-info-val" style="font-size: 0.78rem;"><span class="code-pill">storage/objects/</span></span>
            </div>

            <div class="system-info-item">
                <span class="system-info-label">Số đối tượng lưu trữ</span>
                <span class="system-info-val"><?= $stats['total_objects'] ?> tệp độc bản</span>
            </div>

            <div class="system-info-item">
                <span class="system-info-label">Dung lượng logic danh mục</span>
                <span class="system-info-val"><?= number_format($stats['total_logical_size'] / 1024, 1) ?> KB</span>
            </div>

            <div class="system-info-item">
                <span class="system-info-label">Dung lượng vật lý trên đĩa</span>
                <span class="system-info-val"><?= number_format($stats['total_physical_size'] / 1024, 1) ?> KB</span>
            </div>

            <div class="system-info-item">
                <span class="system-info-label">Tỉ lệ tiết kiệm ổ đĩa</span>
                <span class="system-info-val" style="color: #34d399; font-size: 1.1rem;"><?= $stats['saved_ratio'] ?>%</span>
            </div>
        </div>

        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="run_gc">
            <?php if (Auth::isAdmin()): ?>
                <button type="submit" class="btn btn-secondary" style="width: 100%;">
                    <?= Icons::get('refresh') ?>
                    <span>Dọn Dẹp Tệp Rác Mồ Côi (Garbage Collection)</span>
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-secondary" style="width: 100%; opacity: 0.6; cursor: not-allowed;" title="Chỉ Quản trị viên mới có quyền chạy dọn dẹp rác" disabled>
                    <?= Icons::get('lock') ?>
                    <span>Dọn Dẹp Rác CAS (Chỉ Quản Trị Viên)</span>
                </button>
            <?php endif; ?>
        </form>
    </div>

    <!-- CỘT 2: HỒ SƠ TÀI KHOẢN ĐANG ĐĂNG NHẬP -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Hồ sơ người dùng hiện tại</div>
        </div>

        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
            <div class="user-avatar" style="width: 56px; height: 56px; font-size: 1.3rem; background: <?= ($currentUser['role'] === 'ADMIN') ? 'linear-gradient(135deg, #3b82f6, #1d4ed8)' : 'linear-gradient(135deg, #10b981, #059669)' ?>;">
                <?= htmlspecialchars(strtoupper(substr($currentUser['username'] ?? 'U', 0, 1))) ?>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 1.1rem; color: var(--text-main);"><?= htmlspecialchars($currentUser['full_name']) ?></div>
                <div style="font-size: 0.82rem; color: var(--text-dim); margin-top: 2px;">
                    @<?= htmlspecialchars($currentUser['username']) ?> &bull; 
                    <span class="badge" style="background: rgba(59,130,246,0.2); color: #60a5fa;"><?= htmlspecialchars($currentUser['role']) ?></span>
                </div>
            </div>
        </div>

        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Họ và tên hiển thị:</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($currentUser['full_name']) ?>" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Địa chỉ Email:</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($currentUser['email']) ?>" required>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 18px;">
                <button type="submit" class="btn btn-primary">
                    <?= Icons::get('check') ?>
                    <span>Lưu Thay Đổi</span>
                </button>
                <a href="logout.php" class="btn btn-danger" style="text-decoration: none;">
                    <?= Icons::get('trash') ?>
                    <span>Đăng Xuất</span>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- KHU VỰC QUẢN LÝ & SAO LƯU TÀI KHOẢN NGƯỜI DÙNG (YÊU CẦU ĐẶC BIỆT CỦA BẠN) -->
<div class="dashboard-card" id="accounts-section" style="margin-bottom: 24px;">
    <div class="card-title-group" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div>
            <div class="card-heading" style="font-size: 1.15rem; color: #f8fafc;">
                🔐 Quản Lý & Sao Lưu Dữ Liệu Tài Khoản (Authentication & Auth Backup)
            </div>
            <p style="font-size: 0.85rem; color: var(--text-dim); margin-top: 4px;">
                Toàn bộ tài khoản đăng nhập được mã hóa mật khẩu Bcrypt. Bạn có thể xuất bản sao lưu (Backup) và phục hồi (Restore) tài khoản bất kỳ lúc nào.
            </p>
        </div>

        <!-- CÁC NÚT THAO TÁC SAO LƯU NHANH (HỖ TRỢ CẢ SQL VÀ JSON) -->
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <a href="settings.php?action=download_auth_sql" class="btn btn-secondary" style="text-decoration: none;" title="Xuất và tải về script SQL chèn tài khoản vào MySQL">
                <?= Icons::get('download') ?>
                <span>Tải Sao Lưu User (.SQL)</span>
            </a>

            <?php if (Auth::isAdmin()): ?>
                <a href="settings.php?action=download_full_db_sql" class="btn btn-secondary" style="text-decoration: none;" title="Xuất toàn bộ 7 bảng trong Database thành file .sql dump">
                    <?= Icons::get('database') ?>
                    <span>Full Database Dump (.SQL)</span>
                </a>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('createUserModal').style.display='flex'">
                    <?= Icons::get('plus') ?>
                    <span>Thêm Tài Khoản</span>
                </button>
            <?php else: ?>
                <span class="badge" style="background: rgba(245,158,11,0.15); color: #f59e0b; padding: 6px 12px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;">
                    <?= Icons::get('lock') ?> Chỉ Admin mới có quyền tạo User & Full Dump
                </span>
            <?php endif; ?>

            <a href="settings.php?action=download_auth_backup" class="btn btn-success" style="text-decoration: none;" title="Xuất và tải về tệp JSON chứa toàn bộ tài khoản">
                <?= Icons::get('download') ?>
                <span>Sao Lưu User (.JSON)</span>
            </a>
        </div>
    </div>

    <!-- DANH SÁCH TẤT CẢ TÀI KHOẢN TRONG HỆ THỐNG -->
    <div class="table-responsive" style="margin-bottom: 20px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Tên Đăng Nhập</th>
                    <th>Họ và Tên</th>
                    <th>Email</th>
                    <th>Vai Trò</th>
                    <th>Trạng Thái</th>
                    <th>Ngày Tạo</th>
                    <th>Đăng Nhập Cuối</th>
                    <th style="width: 130px;">Hành Động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $u): ?>
                <tr>
                    <td><code>#<?= $u['id'] ?></code></td>
                    <td>
                        <strong style="color: #38bdf8;">@<?= htmlspecialchars($u['username']) ?></strong>
                        <?php if ($u['id'] === $currentUser['id']): ?>
                            <span class="badge" style="background: rgba(34,197,94,0.2); color: #4ade80; font-size: 10px; margin-left: 4px;">Bạn</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><span style="color: var(--text-dim); font-size: 0.85rem;"><?= htmlspecialchars($u['email']) ?></span></td>
                    <td>
                        <?php if ($u['role'] === 'ADMIN'): ?>
                            <span class="badge" style="background: rgba(59,130,246,0.2); color: #60a5fa;">Quản trị viên</span>
                        <?php elseif ($u['role'] === 'VIEWER'): ?>
                            <span class="badge" style="background: rgba(148,163,184,0.2); color: #cbd5e1;">Khách xem</span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(16,185,129,0.2); color: #34d399;">Vận hành viên</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['status'] === 'ACTIVE'): ?>
                            <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                                ● Hoạt động
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171;">
                                ✕ Tạm khóa
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.82rem; color: var(--text-dim);"><?= htmlspecialchars($u['created_at']) ?></td>
                    <td style="font-size: 0.82rem; color: var(--text-dim);">
                        <?= $u['last_login'] ? htmlspecialchars($u['last_login']) : '<em>Chưa đăng nhập</em>' ?>
                    </td>
                    <td>
                        <?php if (Auth::isAdmin()): ?>
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 11px;" 
                                    onclick='openEditUserModal(<?= json_encode([
                                        'id' => $u['id'],
                                        'username' => $u['username'],
                                        'full_name' => $u['full_name'],
                                        'email' => $u['email'],
                                        'role' => $u['role'],
                                        'status' => $u['status']
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    Sửa
                                </button>
                                <?php if ($u['id'] !== $currentUser['id'] && $u['id'] !== 1): ?>
                                    <form action="settings.php" method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa tài khoản <?= htmlspecialchars($u['username']) ?>?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 4px 8px; font-size: 11px;" title="Xóa tài khoản">
                                            &times;
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim); font-size: 10px;">Bảo toàn</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span style="font-size: 11px; color: var(--text-dim);">Chỉ xem</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- KHU VỰC KHÔI PHỤC TÀI KHOẢN TỪ BẢN SAO LƯU -->
    <div style="background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 18px 20px;">
        <h4 style="font-size: 0.95rem; font-weight: 600; color: #f8fafc; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; flex-shrink: 0; color: #38bdf8;">
                <?= Icons::get('restore') ?>
            </span>
            <span>Khôi Phục Danh Sách Tài Khoản Từ Bản Sao Lưu (Restore Auth Accounts)</span>
        </h4>

        <?php if (Auth::isAdmin()): ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: center;">
            <!-- Cách 1: Khôi phục nhanh từ snapshot mới nhất -->
            <form action="settings.php" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn khôi phục từ bản sao lưu tài khoản mới nhất?')">
                <input type="hidden" name="action" value="restore_auth_backup">
                <input type="hidden" name="restore_latest" value="1">
                <p style="font-size: 0.82rem; color: var(--text-dim); margin-bottom: 10px;">
                    Tự động đồng bộ từ bản snapshot tự động mới nhất được hệ thống sao lưu:
                </p>
                <button type="submit" class="btn btn-secondary" style="width: 100%;">
                    <?= Icons::get('refresh') ?>
                    <span>Khôi phục từ Bản Sao Lưu Gần Nhất</span>
                </button>
            </form>

            <!-- Cách 2: Tải lên tệp SQL hoặc JSON sao lưu -->
            <form action="settings.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="restore_auth_backup">
                <p style="font-size: 0.82rem; color: var(--text-dim); margin-bottom: 10px;">
                    Hoặc tải lên tệp sao lưu tài khoản định dạng <code>.sql</code> hoặc <code>.json</code>:
                </p>
                <div style="display: flex; gap: 8px;">
                    <input type="file" name="backup_file" accept=".sql,.json" class="form-control" style="padding: 6px 10px;" required>
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                        <?= Icons::get('upload') ?>
                        <span>Tải Lên & Khôi Phục</span>
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="alert alert-warning" style="margin-top: 10px;">
            <?= Icons::get('lock') ?>
            <span>Chức năng phục hồi danh sách tài khoản hệ thống từ bản sao lưu chỉ dành riêng cho <strong>Quản trị viên (Admin)</strong>.</span>
        </div>
        <?php endif; ?>

        <?php if (!empty($authBackupFiles)): ?>
        <div style="margin-top: 16px; border-top: 1px dashed rgba(255,255,255,0.08); padding-top: 12px;">
            <span style="font-size: 0.82rem; color: var(--text-dim);">Các bản sao lưu tài khoản đã lưu trên máy chủ:</span>
            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
                <?php foreach (array_slice($authBackupFiles, 0, 5) as $bf): ?>
                    <span class="code-pill" style="font-size: 0.78rem;">
                        📁 <?= htmlspecialchars($bf['name']) ?> (<?= $bf['time'] ?>)
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL THÊM TÀI KHOẢN MỚI -->
<div id="createUserModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #1e293b; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; max-width: 480px; width: 100%; padding: 28px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
            <h3 style="font-size: 1.2rem; font-weight: 700; color: #f8fafc;">Tạo Tài Khoản Người Dùng Mới</h3>
            <button type="button" onclick="document.getElementById('createUserModal').style.display='none'" style="background: none; border: none; color: #94a3b8; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="create_user">

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Họ và tên người dùng:</label>
                <input type="text" name="full_name" class="form-control" placeholder="Nguyễn Văn B" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Tên đăng nhập (Username):</label>
                <input type="text" name="username" class="form-control" placeholder="nhanvien_01" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Email:</label>
                <input type="email" name="email" class="form-control" placeholder="user@backuptool.local" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Vai trò:</label>
                <select name="role" class="form-control">
                    <option value="OPERATOR">Vận hành viên (Operator)</option>
                    <option value="ADMIN">Quản trị viên (Admin)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label class="form-label">Mật khẩu khởi tạo:</label>
                <input type="password" name="password" class="form-control" placeholder="Tối thiểu 6 ký tự..." required>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="document.getElementById('createUserModal').style.display='none'" class="btn btn-secondary">Hủy</button>
                <button type="submit" class="btn btn-primary">Xác Nhận Tạo & Sao Lưu</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CHỈNH SỬA TÀI KHOẢN -->
<div id="editUserModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #1e293b; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; max-width: 480px; width: 100%; padding: 28px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
            <h3 style="font-size: 1.2rem; font-weight: 700; color: #f8fafc;">Chỉnh Sửa Tài Khoản Người Dùng</h3>
            <button type="button" onclick="document.getElementById('editUserModal').style.display='none'" style="background: none; border: none; color: #94a3b8; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit_user_id" value="">

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Tên đăng nhập (Username):</label>
                <input type="text" id="edit_username" class="form-control" readonly style="opacity: 0.7; background: rgba(15,23,42,0.6);">
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Họ và tên người dùng:</label>
                <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Email:</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label">Vai trò:</label>
                    <select name="role" id="edit_role" class="form-control">
                        <option value="OPERATOR">Vận hành viên</option>
                        <option value="ADMIN">Quản trị viên</option>
                        <option value="VIEWER">Chỉ xem (Viewer)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Trạng thái:</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="ACTIVE">Hoạt động (ACTIVE)</option>
                        <option value="SUSPENDED">Tạm khóa (SUSPENDED)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label class="form-label">Đổi mật khẩu mới (Để trống nếu giữ nguyên):</label>
                <input type="password" name="password" class="form-control" placeholder="Để trống nếu không đổi...">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="document.getElementById('editUserModal').style.display='none'" class="btn btn-secondary">Hủy</button>
                <button type="submit" class="btn btn-primary">Lưu Thay Đổi & Sao Lưu</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUserModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_status').value = user.status;
    document.getElementById('editUserModal').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
