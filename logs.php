<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();
$pdo = Database::getConnection();

// Xử lý XÓA TOÀN BỘ NHẬT KÝ (Chỉ Quản trị viên Admin mới có quyền)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    if (!Auth::isAdmin()) {
        die("Thao tác bị từ chối: Chỉ Quản trị viên (Admin) mới có quyền xóa sạch nhật ký hệ thống!");
    }
    $pdo->query("TRUNCATE TABLE `run_logs`");
    header('Location: logs.php');
    exit;
}

// Xử lý LỌC NHẬT KÝ
$levelFilter = $_GET['level'] ?? 'ALL';
$searchQuery = trim($_GET['q'] ?? '');

$sql = "SELECT l.*, j.name as job_name FROM `run_logs` l LEFT JOIN `backup_jobs` j ON l.job_id = j.id WHERE 1=1";
$params = [];

if ($levelFilter !== 'ALL') {
    $sql .= " AND l.log_level = ?";
    $params[] = $levelFilter;
}

if ($searchQuery !== '') {
    $sql .= " AND (l.message LIKE ? OR l.stage LIKE ? OR l.file_target LIKE ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
}

$sql .= " ORDER BY l.id DESC LIMIT 150";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Nhật Ký Thực Thi Hệ Thống';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Nhật Ký & Lịch Sử Tác Vụ (Audit Logs)
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Ghi nhận toàn bộ sự kiện quét tệp, băm mã SHA-256, khử trùng lặp và các cảnh báo gián đoạn trong hệ thống.
        </p>
    </div>

    <div style="display: flex; gap: 8px;">
        <?php if (Auth::isAdmin()): ?>
        <form action="logs.php" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa toàn bộ lịch sử nhật ký?');">
            <input type="hidden" name="action" value="clear_logs">
            <button type="submit" class="btn btn-secondary btn-sm" style="background-color: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.3);">
                Xóa lịch sử nhật ký
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- THANH LỌC VÀ TÌM KIẾM NHẬT KÝ -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <form action="logs.php" method="GET" style="display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;">
        <div style="width: 180px;">
            <label class="form-label" for="level">Mức độ log:</label>
            <select class="form-control" id="level" name="level" onchange="this.form.submit();">
                <option value="ALL" <?= ($levelFilter === 'ALL') ? 'selected' : '' ?>>Tất cả mức độ</option>
                <option value="INFO" <?= ($levelFilter === 'INFO') ? 'selected' : '' ?>>Thông tin (INFO)</option>
                <option value="WARNING" <?= ($levelFilter === 'WARNING') ? 'selected' : '' ?>>Cảnh báo (WARNING)</option>
                <option value="ERROR" <?= ($levelFilter === 'ERROR') ? 'selected' : '' ?>>Lỗi sự cố (ERROR)</option>
            </select>
        </div>

        <div style="flex: 1; min-width: 240px;">
            <label class="form-label" for="q">Tìm kiếm từ khóa:</label>
            <input type="text" class="form-control" id="q" name="q" placeholder="Nhập tên tệp, giai đoạn, mã hash..." value="<?= htmlspecialchars($searchQuery) ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-primary">
                <?= Icons::get('eye') ?>
                <span>Lọc Nhật Ký</span>
            </button>
        </div>
    </form>
</div>

<!-- BẢNG NHẬT KÝ CHI TIẾT -->
<div class="dashboard-card">
    <div class="card-title-group">
        <div class="card-heading">
            Lịch sử thực thi (Hiển thị <?= count($logs) ?> sự kiện gần nhất)
        </div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th style="width: 160px;">Thời gian</th>
                    <th style="width: 90px;">Mức độ</th>
                    <th style="width: 150px;">Giai đoạn</th>
                    <th>Nội dung thông điệp chi tiết</th>
                    <th>Nguồn liên quan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 28px; color: var(--text-dim);">Không có bản ghi nhật ký nào phù hợp với bộ lọc.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="table-id">#<?= $log['id'] ?></td>
                        <td style="font-size: 0.8rem;"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td>
                            <?php if ($log['log_level'] === 'INFO'): ?>
                                <span class="pill-badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;">INFO</span>
                            <?php elseif ($log['log_level'] === 'WARNING'): ?>
                                <span class="pill-badge warning">WARN</span>
                            <?php else: ?>
                                <span class="pill-badge danger">ERROR</span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color: var(--text-main); font-size: 0.84rem;"><?= htmlspecialchars($log['stage']) ?></strong></td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($log['message']) ?></td>
                        <td>
                            <?php if ($log['job_name']): ?>
                                <span class="pill-badge" style="background: rgba(255,255,255,0.06); color: var(--text-muted);"><?= htmlspecialchars($log['job_name']) ?></span>
                            <?php else: ?>
                                <span style="color: var(--text-dim); font-size: 0.8rem;">(Hệ thống)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/views/footer.php'; ?>
