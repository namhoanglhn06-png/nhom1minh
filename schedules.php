<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Icons.php';

require_once __DIR__ . '/core/Auth.php';
Auth::requireAuth();

$pdo = Database::getConnection();
$message = '';
$messageType = '';

// Lấy danh sách nguồn dữ liệu
$allJobs = $pdo->query("SELECT * FROM `backup_jobs` ORDER BY id DESC")->fetchAll();

// Xử lý BẬT / TẮT lịch biểu (Chỉ Admin)
if (isset($_GET['toggle_id'])) {
    if (!Auth::isAdmin()) {
        die("Thao tác bị từ chối: Chỉ Quản trị viên (Admin) mới có quyền bật/tắt lịch biểu!");
    }
    $toggleId = (int)$_GET['toggle_id'];
    $stmt = $pdo->prepare("UPDATE `schedules` SET `is_active` = IF(`is_active`=1, 0, 1) WHERE `id` = ?");
    $stmt->execute([$toggleId]);
    header('Location: schedules.php');
    exit;
}

// Xử lý FORM POST: THÊM / XÓA LỊCH BIỂU (Chỉ Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add_schedule', 'delete_schedule'], true) && !Auth::isAdmin()) {
        $message = "Thao tác bị từ chối: Lịch biểu tự động được bảo toàn bởi Quản trị viên! Chỉ Admin mới có quyền thêm hoặc xóa lịch biểu.";
        $messageType = 'danger';
    }
    // Xử lý THÊM MỚI lịch biểu
    elseif ($action === 'add_schedule') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $freq = $_POST['frequency'] ?? 'DAILY';
        $runTime = $_POST['run_time'] ?? '00:00:00';

        if ($jobId > 0) {
            $stmt = $pdo->prepare("INSERT INTO `schedules` (`job_id`, `frequency`, `run_time`, `is_active`, `next_run`) VALUES (?, ?, ?, 1, DATE_ADD(NOW(), INTERVAL 1 DAY))");
            $stmt->execute([$jobId, $freq, $runTime]);
            $message = "Đã thêm mới lịch biểu tự động thành công!";
            $messageType = 'success';
        }
    }
    // Xử lý XÓA lịch biểu
    elseif ($action === 'delete_schedule') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId > 0) {
            $stmt = $pdo->prepare("DELETE FROM `schedules` WHERE `id` = ?");
            $stmt->execute([$delId]);
            $message = "Đã xóa lịch biểu thành công!";
            $messageType = 'success';
        }
    }
}

// Lấy danh sách các lịch biểu
$schedules = $pdo->query("
    SELECT s.*, j.name as job_name, j.source_path 
    FROM `schedules` s 
    JOIN `backup_jobs` j ON s.job_id = j.id 
    ORDER BY s.id DESC
")->fetchAll();

$pageTitle = 'Lịch Biểu Sao Lưu Tự Động';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Lịch Biểu Tự Động Hóa (Backup Scheduler)
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Thiết lập chu kỳ sao lưu tự động định kỳ (Hàng ngày, Hàng tuần) giúp đảm bảo an toàn dữ liệu không cần thao tác tay.
        </p>
    </div>

    <div>
        <a href="#addScheduleCard" class="btn btn-primary">
            <?= Icons::get('folder-plus') ?>
            <span>+ Tạo Lịch Biểu Mới</span>
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <?= Icons::get('check-circle') ?>
    <span><?= htmlspecialchars($message) ?></span>
</div>
<?php endif; ?>

<!-- BẢNG DANH SÁCH LỊCH BIỂU -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <div class="card-title-group">
        <div class="card-heading">Danh sách lịch biểu tự động đang hoạt động (<?= count($schedules) ?> lịch biểu)</div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Nguồn dữ liệu sao lưu</th>
                    <th>Tần suất chu kỳ</th>
                    <th>Thời gian chạy</th>
                    <th>Lần chạy trước</th>
                    <th>Lần chạy kế tiếp</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 28px; color: var(--text-dim);">Chưa có lịch biểu tự động nào. Vui lòng thiết lập ở form bên dưới!</td></tr>
                <?php else: ?>
                    <?php foreach ($schedules as $sc): ?>
                    <tr>
                        <td class="table-id">#<?= $sc['id'] ?></td>
                        <td>
                            <strong style="color: var(--text-main);"><?= htmlspecialchars($sc['job_name']) ?></strong>
                            <div style="font-size: 0.76rem; color: var(--text-dim);"><?= htmlspecialchars($sc['source_path']) ?></div>
                        </td>
                        <td>
                            <span class="pill-badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;">
                                <?= htmlspecialchars($sc['frequency']) ?>
                            </span>
                        </td>
                        <td style="font-family: Consolas, monospace;"><?= htmlspecialchars($sc['run_time']) ?></td>
                        <td style="font-size: 0.8rem;"><?= htmlspecialchars($sc['last_run'] ?? 'Chưa chạy') ?></td>
                        <td style="font-size: 0.8rem; color: #34d399;"><?= htmlspecialchars($sc['next_run'] ?? 'Đang lập lịch') ?></td>
                        <td>
                            <?php if ($sc['is_active']): ?>
                                <span class="pill-badge success">Đang hoạt động</span>
                            <?php else: ?>
                                <span class="pill-badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim);">Tạm dừng</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="backup.php?job_id=<?= $sc['job_id'] ?>" class="icon-action-btn" title="Chạy ngay bây giờ" style="background-color: rgba(16, 185, 129, 0.2); color: #34d399;">
                                    <?= Icons::get('play') ?>
                                </a>
                                <?php if (Auth::isAdmin()): ?>
                                    <a href="schedules.php?toggle_id=<?= $sc['id'] ?>" class="icon-action-btn" title="Bật / Tắt lịch biểu">
                                        <?= Icons::get('refresh') ?>
                                    </a>
                                    <form action="schedules.php" method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa lịch biểu này?');">
                                        <input type="hidden" name="action" value="delete_schedule">
                                        <input type="hidden" name="id" value="<?= $sc['id'] ?>">
                                        <button type="submit" class="icon-action-btn" title="Xóa lịch biểu" style="background-color: rgba(239, 68, 68, 0.2); color: #f87171;">
                                            &times;
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span title="Lịch biểu được bảo toàn bởi Admin" style="font-size: 11px; color: var(--text-dim); padding: 4px 8px; background: rgba(255,255,255,0.05); border-radius: 4px;">Tự động</span>
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

<!-- FORM THÊM LỊCH BIỂU MỚI (CHỈ ADMIN) HOẶC THÔNG BÁO BẢO TOÀN -->
<?php if (Auth::isAdmin()): ?>
<div class="dashboard-card" id="addScheduleCard">
    <div class="card-title-group">
        <div class="card-heading">Tạo mới lịch biểu sao lưu tự động</div>
    </div>

    <form action="schedules.php" method="POST">
        <input type="hidden" name="action" value="add_schedule">

        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label" for="job_id">Chọn nguồn dữ liệu:</label>
                <select class="form-control" id="job_id" name="job_id" required>
                    <?php foreach ($allJobs as $job): ?>
                    <option value="<?= $job['id'] ?>"><?= htmlspecialchars($job['name']) ?> (<?= htmlspecialchars($job['source_path']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="frequency">Tần suất chu kỳ:</label>
                <select class="form-control" id="frequency" name="frequency">
                    <option value="HOURLY">Mỗi giờ (Hourly)</option>
                    <option value="DAILY" selected>Hàng ngày (Daily)</option>
                    <option value="WEEKLY">Hàng tuần (Weekly)</option>
                    <option value="MONTHLY">Hàng tháng (Monthly)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="run_time">Thời điểm thực thi:</label>
                <input type="time" class="form-control" id="run_time" name="run_time" value="02:00:00">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 10px;">
            <?= Icons::get('check-circle') ?>
            <span>Kích Hoạt Lịch Biểu Mới</span>
        </button>
    </form>
</div>
<?php else: ?>
<div class="dashboard-card">
    <div style="display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; background: rgba(59,130,246,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #38bdf8; flex-shrink: 0;">
            <?= Icons::get('refresh') ?>
        </div>
        <div>
            <h4 style="color: #f8fafc; font-size: 1.05rem; font-weight: 600;">Lịch Trình Tự Động Được Bảo Toàn Bởi Quản Trị Viên</h4>
            <p style="color: var(--text-dim); font-size: 0.85rem; margin-top: 4px; line-height: 1.5;">
                Các lịch trình sao lưu tự động định kỳ đã được <strong>Quản trị viên (Admin)</strong> thiết lập sẵn nhằm bảo vệ an toàn cho dữ liệu. Khách hàng/nhân viên vận hành có thể theo dõi thời gian chạy tiếp theo hoặc nhấn nút <strong>Chạy ngay bây giờ (Play)</strong> để chủ động sao lưu tức thì.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>
