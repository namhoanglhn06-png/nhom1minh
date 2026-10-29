<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/RestoreEngine.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();

$pdo = Database::getConnection();

// Lấy danh sách các phiên bản COMPLETED
$stmt = $pdo->query("
    SELECT v.*, j.name as job_name, j.source_path 
    FROM `versions` v 
    JOIN `backup_jobs` j ON v.job_id = j.id 
    WHERE v.status = 'COMPLETED' 
    ORDER BY v.id DESC
");
$completedVersions = $stmt->fetchAll();

$versionId = (int)($_POST['version_id'] ?? $_GET['version_id'] ?? 0);
if ($versionId <= 0 && !empty($completedVersions)) {
    $versionId = (int)$completedVersions[0]['id'];
}

$projectRoot = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
$defaultTarget = $projectRoot . '/storage/test_restored_output';
$restoreResult = null;
$restoreError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'do_restore') {
    $targetDir = trim($_POST['target_dir'] ?? '');
    $selectedVersionId = (int)($_POST['version_id'] ?? 0);

    if ($selectedVersionId <= 0 || $targetDir === '') {
        $restoreError = "Vui lòng chọn phiên bản và nhập thư mục đích khôi phục!";
    } else {
        $engine = new RestoreEngine();
        try {
            $restoreResult = $engine->restore($selectedVersionId, $targetDir);
        } catch (Exception $e) {
            $restoreError = $e->getMessage();
        }
    }
}

$pageTitle = 'Khôi Phục Dữ Liệu Theo Phiên Bản';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Khôi Phục Dữ Liệu (Version Recovery)
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Lấy lại dữ liệu nguyên vẹn tại một thời điểm vào thư mục mới. Bắt buộc kiểm tra mã băm SHA-256 trước khi ghi.
        </p>
    </div>

    <div>
        <a href="versions.php" class="btn btn-secondary">
            <?= Icons::get('branch') ?>
            <span>Lịch Sử Phiên Bản</span>
        </a>
    </div>
</div>

<?php if ($restoreError): ?>
<div class="alert alert-danger">
    <?= Icons::get('alert') ?>
    <span><?= htmlspecialchars($restoreError) ?></span>
</div>
<?php endif; ?>

<?php if ($restoreResult && $restoreResult['success']): ?>
<div class="alert alert-success">
    <?= Icons::get('check-circle') ?>
    <span>KHÔI PHỤC THÀNH CÔNG 100%! Đã khôi phục <?= $restoreResult['restored_count'] ?> tệp nguyên vẹn vào: <span class="code-pill"><?= htmlspecialchars($restoreResult['target_dir']) ?></span>. Toàn bộ mã băm SHA-256 khớp chuẩn xác.</span>
</div>
<?php endif; ?>

<!-- FORM THIẾT LẬP KHÔI PHỤC -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <div class="card-title-group">
        <div class="card-heading">Thiết lập tiến trình khôi phục</div>
    </div>

    <form action="restore.php" method="POST">
        <input type="hidden" name="action" value="do_restore">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label" for="version_id">Chọn phiên bản cần lấy lại dữ liệu:</label>
                <select class="form-control" id="version_id" name="version_id" required>
                    <?php if (empty($completedVersions)): ?>
                        <option value="">(Chưa có phiên bản hoàn tất nào)</option>
                    <?php else: ?>
                        <?php foreach ($completedVersions as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= ($v['id'] == $versionId) ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($v['job_name']) ?>] v<?= $v['version_number'] ?> (Lúc <?= $v['completed_at'] ?> - <?= $v['total_files'] ?> tệp)
                        </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="target_dir">Thư mục đích khôi phục mới (Target Output):</label>
                <input type="text" class="form-control" id="target_dir" name="target_dir" value="<?= htmlspecialchars($defaultTarget) ?>" required>
            </div>
        </div>

        <div style="background-color: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px; margin-bottom: 16px;">
            <div style="font-weight: 600; margin-bottom: 8px; color: var(--text-main);">Rào chắn bảo mật & toàn vẹn:</div>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-muted);">
                    <input type="checkbox" checked disabled> Bắt buộc kiểm tra mã băm SHA-256 (Phép thử 3)
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-muted);">
                    <input type="checkbox" checked disabled> Chặn đứng Path Traversal '../' (Phép thử 5)
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-muted);">
                    <input type="checkbox" checked disabled> Chặn ghi đè vào thư mục nguồn gốc
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 11px 26px; font-size: 0.95rem;" <?= empty($completedVersions) ? 'disabled' : '' ?>>
            <?= Icons::get('restore') ?>
            <span>Tiến Hành Khôi Phục Phiên Bản Đã Chọn</span>
        </button>
    </form>
</div>

<!-- KẾT QUẢ KHÔI PHỤC CHI TIẾT -->
<?php if ($restoreResult): ?>
<div class="dashboard-card">
    <div class="card-title-group">
        <div class="card-heading">
            Chi tiết các tệp khôi phục (Thành công: <?= $restoreResult['restored_count'] ?> tệp | Lỗi: <?= $restoreResult['failed_count'] ?>)
        </div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 50px;">STT</th>
                    <th>Đường dẫn tệp tương đối</th>
                    <th>Đích đến trên đĩa</th>
                    <th>Kích thước</th>
                    <th>Mã băm SHA-256</th>
                    <th>Kết quả kiểm tra</th>
                </tr>
            </thead>
            <tbody>
                <?php $idx = 1; foreach ($restoreResult['restored_files'] as $rf): ?>
                <tr>
                    <td class="table-id"><?= $idx++ ?></td>
                    <td><strong style="color: var(--text-main);"><?= htmlspecialchars($rf['relative_path']) ?></strong></td>
                    <td style="font-size: 0.8rem; color: var(--text-dim);"><?= htmlspecialchars($rf['destination']) ?></td>
                    <td><?= number_format($rf['size']) ?> B</td>
                    <td>
                        <span class="code-pill"><?= substr($rf['hash'], 0, 16) ?>...</span>
                    </td>
                    <td>
                        <span class="pill-badge success">Khớp mã băm</span>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php foreach ($restoreResult['failed_files'] as $ff): ?>
                <tr style="background-color: var(--danger-bg);">
                    <td class="table-id"><?= $idx++ ?></td>
                    <td><strong style="color: var(--danger);"><?= htmlspecialchars($ff['file']) ?></strong></td>
                    <td colspan="3" style="color: var(--danger);"><?= htmlspecialchars($ff['reason']) ?></td>
                    <td>
                        <span class="pill-badge danger">Thất bại</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>
