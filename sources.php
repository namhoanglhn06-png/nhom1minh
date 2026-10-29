<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/BackupEngine.php';

require_once __DIR__ . '/core/Auth.php';
Auth::requireAuth();

$pdo = Database::getConnection();
$message = '';
$messageType = '';

// Kiểm tra quyền Quản trị viên trước khi Thêm / Sửa / Xóa nguồn
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add_source', 'edit_source', 'delete_source'], true) && !Auth::isAdmin()) {
        $message = 'Thao tác bị từ chối: Nguồn dữ liệu đã được bảo toàn bởi Quản trị viên! Chỉ tài khoản Admin mới có quyền thay đổi cấu hình nguồn.';
        $messageType = 'danger';
    }
    // 1. Xử lý THÊM MỚI nguồn dữ liệu
    elseif ($action === 'add_source') {
        $name = trim($_POST['name'] ?? '');
        $sourcePath = trim($_POST['source_path'] ?? '');
        $targetPath = trim($_POST['target_path'] ?? '');
        $excluded = trim($_POST['excluded_extensions'] ?? '');

        if ($name === '' || $sourcePath === '' || $targetPath === '') {
            $message = 'Vui lòng nhập đầy đủ Tên nguồn, Thư mục nguồn và Thư mục lưu trữ!';
            $messageType = 'danger';
        } elseif (!is_dir($sourcePath)) {
            $message = "Thư mục nguồn không tồn tại trên máy tính: '{$sourcePath}'";
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `backup_jobs` (`name`, `source_path`, `target_path`, `excluded_extensions`) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $sourcePath, $targetPath, $excluded]);
            $message = "Đã thêm mới thành công nguồn dữ liệu: '{$name}'";
            $messageType = 'success';
        }
    }
    // 2. Xử lý CẬP NHẬT (SỬA) nguồn dữ liệu
    elseif ($action === 'edit_source') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sourcePath = trim($_POST['source_path'] ?? '');
        $targetPath = trim($_POST['target_path'] ?? '');
        $excluded = trim($_POST['excluded_extensions'] ?? '');

        if ($id <= 0 || $name === '' || $sourcePath === '') {
            $message = 'Thông tin cập nhật không hợp lệ!';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("UPDATE `backup_jobs` SET `name` = ?, `source_path` = ?, `target_path` = ?, `excluded_extensions` = ? WHERE `id` = ?");
            $stmt->execute([$name, $sourcePath, $targetPath, $excluded, $id]);
            $message = "Đã cập nhật thành công nguồn dữ liệu ID #{$id}";
            $messageType = 'success';
        }
    }
    // 3. Xử lý XÓA nguồn dữ liệu
    elseif ($action === 'delete_source') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM `backup_jobs` WHERE `id` = ?");
            $stmt->execute([$id]);
            $message = "Đã xóa nguồn dữ liệu ID #{$id}";
            $messageType = 'success';
        }
    }
}

// Lấy danh sách tất cả các nguồn dữ liệu
$jobsStmt = $pdo->query("
    SELECT j.*, 
        (SELECT COUNT(*) FROM `versions` v WHERE v.job_id = j.id AND v.status = 'COMPLETED') AS completed_versions,
        (SELECT v.completed_at FROM `versions` v WHERE v.job_id = j.id AND v.status = 'COMPLETED' ORDER BY v.version_number DESC LIMIT 1) AS last_backup_time,
        (SELECT v.version_number FROM `versions` v WHERE v.job_id = j.id AND v.status = 'COMPLETED' ORDER BY v.version_number DESC LIMIT 1) AS latest_version_num
    FROM `backup_jobs` j
    ORDER BY j.id DESC
");
$sources = $jobsStmt->fetchAll();

// Nếu người dùng bấm sửa nguồn nào đó
$editSource = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $pdo->prepare("SELECT * FROM `backup_jobs` WHERE `id` = ?");
    $stmtEdit->execute([$editId]);
    $editSource = $stmtEdit->fetch();
}

$pageTitle = 'Quản Lý Nguồn Dữ Liệu';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Quản Lý Nguồn Dữ Liệu
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Khai báo, chỉnh sửa, quét kiểm tra và thiết lập các thư mục cần sao lưu tăng dần.
        </p>
    </div>

    <div>
        <?php if (Auth::isAdmin()): ?>
            <a href="#formSource" class="btn btn-primary" onclick="document.getElementById('name').focus();">
                <?= Icons::get('folder-plus') ?>
                <span>+ Thêm Nguồn Mới</span>
            </a>
        <?php else: ?>
            <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; padding: 8px 14px; font-size: 13px;">
                🔒 Nguồn dữ liệu được bảo toàn bởi Admin
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <?= Icons::get($messageType === 'success' ? 'check-circle' : 'alert') ?>
    <span><?= htmlspecialchars($message) ?></span>
</div>
<?php endif; ?>

<!-- DANH SÁCH NGUỒN DỮ LIỆU HIỆN CÓ -->
<div class="dashboard-card" style="margin-bottom: 28px;">
    <div class="card-title-group">
        <div class="card-heading">Danh sách nguồn dữ liệu (<?= count($sources) ?> nguồn)</div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Tên nguồn dữ liệu</th>
                    <th>Đường dẫn nguồn gốc</th>
                    <th>Số phiên bản</th>
                    <th>Lần sao lưu cuối</th>
                    <th>Thao tác quản trị</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sources)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 28px; color: var(--text-dim);">Chưa có nguồn dữ liệu nào. Vui lòng thêm nguồn bên dưới!</td></tr>
                <?php else: ?>
                    <?php foreach ($sources as $s): ?>
                    <tr>
                        <td class="table-id">#<?= $s['id'] ?></td>
                        <td>
                            <strong style="color: var(--text-main); font-size: 0.95rem;"><?= htmlspecialchars($s['name']) ?></strong>
                            <div style="font-size: 0.76rem; color: var(--text-dim); margin-top: 2px;">
                                Đích lưu: <span class="code-pill"><?= htmlspecialchars($s['target_path']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="code-pill"><?= htmlspecialchars($s['source_path']) ?></span>
                            <?php if (!is_dir($s['source_path'])): ?>
                                <span class="pill-badge danger" style="margin-left: 6px;">Không tìm thấy thư mục</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['completed_versions'] > 0): ?>
                                <span class="pill-badge success">v<?= $s['latest_version_num'] ?> (<?= $s['completed_versions'] ?> bản)</span>
                            <?php else: ?>
                                <span class="pill-badge warning">Chưa sao lưu</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.8rem;"><?= htmlspecialchars($s['last_backup_time'] ?? 'Chưa có') ?></td>
                        <td>
                            <div class="action-btns">
                                <a href="backup.php?job_id=<?= $s['id'] ?>" class="icon-action-btn" title="Sao lưu ngay" style="background-color: rgba(16, 185, 129, 0.2); color: #34d399;">
                                    <?= Icons::get('play') ?>
                                </a>
                                <a href="versions.php?job_id=<?= $s['id'] ?>" class="icon-action-btn" title="Xem lịch sử phiên bản">
                                    <?= Icons::get('branch') ?>
                                </a>
                                <?php if (Auth::isAdmin()): ?>
                                    <a href="sources.php?edit_id=<?= $s['id'] ?>#formSource" class="icon-action-btn" title="Chỉnh sửa nguồn">
                                        <?= Icons::get('bolt') ?>
                                    </a>
                                    <form action="sources.php" method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa nguồn dữ liệu này cùng toàn bộ cấu hình?');">
                                        <input type="hidden" name="action" value="delete_source">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="icon-action-btn" title="Xóa nguồn" style="background-color: rgba(239, 68, 68, 0.2); color: #f87171;">
                                            &times;
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span title="Chỉ Admin có quyền chỉnh sửa/xóa nguồn" style="font-size: 11px; color: var(--text-dim); padding: 4px 8px; background: rgba(255,255,255,0.05); border-radius: 4px;">Bảo toàn</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- FORM THÊM / SỬA NGUỒN DỮ LIỆU HOẶC THÔNG BÁO BẢO TOÀN -->
<?php if (Auth::isAdmin()): ?>
<div class="dashboard-card" id="formSource">
    <div class="card-title-group">
        <div class="card-heading">
            <?= $editSource ? ('Chỉnh sửa nguồn dữ liệu: ' . htmlspecialchars($editSource['name'])) : 'Thêm mới nguồn dữ liệu' ?>
        </div>
        <?php if ($editSource): ?>
            <a href="sources.php" class="btn btn-secondary btn-sm">Hủy chỉnh sửa</a>
        <?php endif; ?>
    </div>

    <form action="sources.php" method="POST">
        <input type="hidden" name="action" value="<?= $editSource ? 'edit_source' : 'add_source' ?>">
        <?php if ($editSource): ?>
            <input type="hidden" name="id" value="<?= $editSource['id'] ?>">
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label" for="name">Tên nguồn dữ liệu:</label>
                <input type="text" class="form-control" id="name" name="name" placeholder="Ví dụ: Dữ liệu đồ án phần mềm" value="<?= htmlspecialchars($editSource['name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="excluded_extensions">Phần mở rộng loại trừ (Excluded):</label>
                <input type="text" class="form-control" id="excluded_extensions" name="excluded_extensions" placeholder=".tmp, .log, .cache, .git" value="<?= htmlspecialchars($editSource['excluded_extensions'] ?? '.tmp, .log, .cache') ?>">
            </div>
        </div>

<?php
$projectRoot = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
$defaultSourceVal = $editSource['source_path'] ?? ($projectRoot . '/test_data/dataset_200_files');
$defaultTargetVal = $editSource['target_path'] ?? ($projectRoot . '/storage/test_backup_storage');
?>
        <div class="form-group">
            <label class="form-label" for="source_path">Đường dẫn thư mục nguồn (Source Path):</label>
            <input type="text" class="form-control" id="source_path" name="source_path" placeholder="C:/thư mục nguồn" value="<?= htmlspecialchars($defaultSourceVal) ?>" required>
            <div class="form-hint">Hệ thống sẽ quét đệ quy các tệp trong thư mục này để phát hiện thay đổi và sao lưu tăng dần.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="target_path">Đường dẫn thư mục lưu trữ (Storage Directory):</label>
            <input type="text" class="form-control" id="target_path" name="target_path" placeholder="C:/kho lưu trữ" value="<?= htmlspecialchars($defaultTargetVal) ?>" required>
        </div>

        <div style="display: flex; gap: 12px; margin-top: 20px;">
            <button type="submit" class="btn btn-primary">
                <?= Icons::get('check-circle') ?>
                <span><?= $editSource ? 'Lưu Thay Đổi' : 'Tạo Mới Nguồn Dữ Liệu' ?></span>
            </button>
            <?php if ($editSource): ?>
                <a href="sources.php" class="btn btn-secondary">Hủy bỏ</a>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php else: ?>
<div class="dashboard-card" id="formSource">
    <div style="display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; background: rgba(59,130,246,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #38bdf8; flex-shrink: 0;">
            <?= Icons::get('shield') ?>
        </div>
        <div>
            <h4 style="color: #f8fafc; font-size: 1.05rem; font-weight: 600;">Chế Độ Khách Hàng / Nhân Viên Vận Hành (Data Protection Policy)</h4>
            <p style="color: var(--text-dim); font-size: 0.85rem; margin-top: 4px; line-height: 1.5;">
                Toàn bộ nguồn dữ liệu đã được <strong>Quản trị viên (Admin)</strong> thiết lập và bảo toàn an toàn. Bạn có quyền bấm <strong>Sao lưu ngay (Play)</strong> và <strong>Xem lịch sử phiên bản</strong> để thực hiện nghiệp vụ sao lưu/khôi phục dữ liệu, nhưng không thể xóa hoặc sửa đổi cấu hình nguồn của hệ thống.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>
