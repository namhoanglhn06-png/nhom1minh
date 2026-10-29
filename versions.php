<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();

$pdo = Database::getConnection();

// Lấy danh sách nguồn dữ liệu để lọc
$jobsStmt = $pdo->query("SELECT * FROM `backup_jobs` ORDER BY id DESC");
$allJobs = $jobsStmt->fetchAll();

$jobId = (int)($_GET['job_id'] ?? ($allJobs[0]['id'] ?? 0));
$versionId = (int)($_GET['version_id'] ?? 0);
$compareV1 = (int)($_GET['compare_v1'] ?? 0);
$compareV2 = (int)($_GET['compare_v2'] ?? 0);

$message = '';
$messageType = '';

// Xử lý XÓA PHIÊN BẢN (Chỉ Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_version') {
    if (!Auth::isAdmin()) {
        $message = "Thao tác bị từ chối: Chỉ Quản trị viên (Admin) mới có quyền xóa phiên bản!";
        $messageType = 'danger';
    } else {
        $delVersionId = (int)($_POST['version_id'] ?? 0);
        if ($delVersionId > 0) {
            $stmtDel = $pdo->prepare("DELETE FROM `versions` WHERE `id` = ?");
            $stmtDel->execute([$delVersionId]);
            $message = "Đã xóa thành công phiên bản ID #{$delVersionId}!";
            $messageType = 'success';
        }
    }
}

// Lấy danh sách các phiên bản theo job
$versionsQuery = "SELECT v.*, j.name as job_name FROM `versions` v JOIN `backup_jobs` j ON v.job_id = j.id";
if ($jobId > 0) {
    $versionsQuery .= " WHERE v.job_id = {$jobId}";
}
$versionsQuery .= " ORDER BY v.version_number DESC";
$versions = $pdo->query($versionsQuery)->fetchAll();

// Nếu chưa chọn phiên bản, mặc định chọn bản mới nhất
if ($versionId <= 0 && !empty($versions)) {
    $versionId = (int)$versions[0]['id'];
}

// Lấy chi tiết phiên bản đang chọn
$selectedVersion = null;
$entries = [];
if ($versionId > 0) {
    $stmtVer = $pdo->prepare("SELECT v.*, j.name as job_name FROM `versions` v JOIN `backup_jobs` j ON v.job_id = j.id WHERE v.id = ?");
    $stmtVer->execute([$versionId]);
    $selectedVersion = $stmtVer->fetch();

    $stmtEntries = $pdo->prepare("
        SELECT e.*, o.storage_path 
        FROM `entries` e 
        LEFT JOIN `objects` o ON e.object_id = o.id 
        WHERE e.version_id = ? 
        ORDER BY e.relative_path ASC
    ");
    $stmtEntries->execute([$versionId]);
    $entries = $stmtEntries->fetchAll();
}

// Xử lý SO SÁNH GIỮA 2 PHIÊN BẢN (COMPARE DIFF)
$compareDiff = null;
if ($compareV1 > 0 && $compareV2 > 0 && $compareV1 !== $compareV2) {
    $stmt1 = $pdo->prepare("SELECT * FROM `entries` WHERE `version_id` = ?");
    $stmt1->execute([$compareV1]);
    $entriesV1 = [];
    foreach ($stmt1->fetchAll() as $row) {
        $entriesV1[$row['relative_path']] = $row;
    }

    $stmt2 = $pdo->prepare("SELECT * FROM `entries` WHERE `version_id` = ?");
    $stmt2->execute([$compareV2]);
    $entriesV2 = [];
    foreach ($stmt2->fetchAll() as $row) {
        $entriesV2[$row['relative_path']] = $row;
    }

    $allPaths = array_unique(array_merge(array_keys($entriesV1), array_keys($entriesV2)));
    sort($allPaths);

    $compareDiff = [];
    foreach ($allPaths as $path) {
        $e1 = $entriesV1[$path] ?? null;
        $e2 = $entriesV2[$path] ?? null;

        if ($e1 && !$e2) {
            $compareDiff[] = ['path' => $path, 'diff' => 'REMOVED', 'detail' => 'Có ở V1, đã bị xóa ở V2'];
        } elseif (!$e1 && $e2) {
            $compareDiff[] = ['path' => $path, 'diff' => 'ADDED', 'detail' => 'Thêm mới trong V2'];
        } elseif ($e1['verified_hash'] !== $e2['verified_hash']) {
            $compareDiff[] = ['path' => $path, 'diff' => 'MODIFIED', 'detail' => 'Nội dung bị sửa đổi'];
        } else {
            $compareDiff[] = ['path' => $path, 'diff' => 'IDENTICAL', 'detail' => 'Nội dung giống hệt nhau'];
        }
    }
}

$pageTitle = 'Quản Lý Phiên Bản & So Sánh';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Quản Lý Phiên Bản & Danh Mục Tệp
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Duyệt lịch sử các bản sao lưu, xem chi tiết cây tệp theo mốc thời gian và so sánh sự khác biệt giữa hai phiên bản bất kỳ.
        </p>
    </div>

    <div>
        <a href="backup.php?job_id=<?= $jobId ?>" class="btn btn-primary">
            <?= Icons::get('play') ?>
            <span>Tạo Phiên Bản Mới</span>
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <?= Icons::get('check-circle') ?>
    <span><?= htmlspecialchars($message) ?></span>
</div>
<?php endif; ?>

<!-- BỘ LỌC NGUỒN VÀ SO SÁNH 2 PHIÊN BẢN -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <div class="card-title-group">
        <div class="card-heading">Công cụ đối chiếu & Lọc phiên bản</div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
        <!-- Lọc theo nguồn -->
        <form action="versions.php" method="GET">
            <label class="form-label" for="filter_job">Chọn nguồn dữ liệu:</label>
            <div style="display: flex; gap: 8px;">
                <select class="form-control" id="filter_job" name="job_id" onchange="this.form.submit();">
                    <?php foreach ($allJobs as $j): ?>
                    <option value="<?= $j['id'] ?>" <?= ($j['id'] == $jobId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($j['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- Form so sánh 2 phiên bản -->
        <form action="versions.php" method="GET" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="job_id" value="<?= $jobId ?>">
            <div style="flex: 1; min-width: 140px;">
                <label class="form-label">Phiên bản gốc (A):</label>
                <select class="form-control" name="compare_v1">
                    <?php foreach ($versions as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= ($compareV1 == $v['id']) ? 'selected' : '' ?>>v<?= $v['version_number'] ?> (<?= substr($v['completed_at'] ?? '', 0, 10) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 140px;">
                <label class="form-label">Phiên bản mới (B):</label>
                <select class="form-control" name="compare_v2">
                    <?php foreach ($versions as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= ($compareV2 == $v['id']) ? 'selected' : '' ?>>v<?= $v['version_number'] ?> (<?= substr($v['completed_at'] ?? '', 0, 10) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="btn btn-secondary">
                    <?= Icons::get('branch') ?>
                    <span>So Sánh (Diff)</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- KẾT QUẢ SO SÁNH NẾU ĐƯỢC KÍCH HOẠT -->
<?php if ($compareDiff !== null): ?>
<div class="dashboard-card" style="margin-bottom: 24px; border-color: var(--primary);">
    <div class="card-title-group">
        <div class="card-heading" style="color: #60a5fa;">
            Kết quả so sánh giữa hai phiên bản (<?= count($compareDiff) ?> mục)
        </div>
        <a href="versions.php?job_id=<?= $jobId ?>" class="btn btn-secondary btn-sm">Đóng so sánh</a>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Đường dẫn tệp</th>
                    <th>Trạng thái sai khác</th>
                    <th>Chi tiết so sánh</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($compareDiff as $diffItem): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($diffItem['path']) ?></strong></td>
                    <td>
                        <?php if ($diffItem['diff'] === 'ADDED'): ?>
                            <span class="pill-badge success">+ THÊM MỚI</span>
                        <?php elseif ($diffItem['diff'] === 'MODIFIED'): ?>
                            <span class="pill-badge warning">~ SỬA ĐỔI</span>
                        <?php elseif ($diffItem['diff'] === 'REMOVED'): ?>
                            <span class="pill-badge danger">- BỊ XÓA</span>
                        <?php else: ?>
                            <span class="pill-badge" style="background: rgba(255,255,255,0.05); color: var(--text-dim);">= GIỐNG NHAU</span>
                        <?php endif; ?>
                    </td>
                    <td style="color: var(--text-muted); font-size: 0.84rem;"><?= htmlspecialchars($diffItem['detail']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- BẢNG DANH SÁCH CÁC PHIÊN BẢN (TIMELINE) -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <div class="card-title-group">
        <div class="card-heading">Dòng thời gian các phiên bản (<?= count($versions) ?> phiên bản)</div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Phiên bản</th>
                    <th>Thời gian hoàn tất</th>
                    <th>Trạng thái</th>
                    <th>Số lượng tệp</th>
                    <th>Thay đổi (+ / ~ / - / =)</th>
                    <th>Dung lượng</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($versions)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 24px; color: var(--text-dim);">Chưa có phiên bản nào cho nguồn dữ liệu này.</td></tr>
                <?php else: ?>
                    <?php foreach ($versions as $v): ?>
                    <tr style="<?= ($v['id'] == $versionId) ? 'background-color: rgba(59, 130, 246, 0.1);' : '' ?>">
                        <td>
                            <strong style="color: #60a5fa; font-size: 1.05rem;">v<?= $v['version_number'] ?></strong>
                        </td>
                        <td style="font-size: 0.8rem;"><?= htmlspecialchars($v['completed_at'] ?? $v['started_at']) ?></td>
                        <td>
                            <?php if ($v['status'] === 'COMPLETED'): ?>
                                <span class="pill-badge success">Thành công</span>
                            <?php elseif ($v['status'] === 'IN_PROGRESS'): ?>
                                <span class="pill-badge warning">Đang chạy</span>
                            <?php else: ?>
                                <span class="pill-badge danger">Thất bại</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((int)$v['total_files']) ?> tệp</td>
                        <td>
                            <span class="pill-badge success">+<?= $v['added_count'] ?></span>
                            <span class="pill-badge warning">~<?= $v['modified_count'] ?></span>
                            <span class="pill-badge danger">-<?= $v['deleted_count'] ?></span>
                            <span class="pill-badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim);">=<?= $v['unchanged_count'] ?></span>
                        </td>
                        <td><?= number_format((int)$v['total_bytes'] / 1024, 1) ?> KB</td>
                        <td>
                            <div class="action-btns">
                                <a href="versions.php?job_id=<?= $jobId ?>&version_id=<?= $v['id'] ?>" class="icon-action-btn" title="Xem danh mục tệp">
                                    <?= Icons::get('eye') ?>
                                </a>
                                <?php if ($v['status'] === 'COMPLETED'): ?>
                                <a href="restore.php?version_id=<?= $v['id'] ?>" class="icon-action-btn" title="Khôi phục phiên bản này" style="background-color: rgba(16, 185, 129, 0.2); color: #34d399;">
                                    <?= Icons::get('restore') ?>
                                </a>
                                <?php endif; ?>
                                <form action="versions.php?job_id=<?= $jobId ?>" method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa bản ghi phiên bản này?');">
                                    <input type="hidden" name="action" value="delete_version">
                                    <input type="hidden" name="version_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="icon-action-btn" title="Xóa phiên bản" style="background-color: rgba(239, 68, 68, 0.2); color: #f87171;">
                                        &times;
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- CHI TIẾT DANH MỤC TỆP CỦA PHIÊN BẢN ĐANG CHỌN -->
<?php if ($selectedVersion): ?>
<div class="dashboard-card">
    <div class="card-title-group">
        <div class="card-heading">
            Danh mục tệp phiên bản v<?= $selectedVersion['version_number'] ?> (<?= count($entries) ?> tệp)
        </div>
        <div>
            <a href="restore.php?version_id=<?= $selectedVersion['id'] ?>" class="btn btn-primary btn-sm">
                <?= Icons::get('restore') ?>
                <span>Khôi Phục Bản Này</span>
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 50px;">STT</th>
                    <th>Đường dẫn tệp tương đối</th>
                    <th>Dung lượng</th>
                    <th>Trạng thái</th>
                    <th>Mã băm SHA-256 xác thực</th>
                    <th>Thời gian sửa đổi gốc</th>
                </tr>
            </thead>
            <tbody>
                <?php $idx = 1; foreach ($entries as $e): ?>
                <tr>
                    <td class="table-id"><?= $idx++ ?></td>
                    <td><strong style="color: var(--text-main);"><?= htmlspecialchars($e['relative_path']) ?></strong></td>
                    <td><?= number_format((int)$e['file_size']) ?> B</td>
                    <td>
                        <?php if ($e['change_type'] === 'ADDED'): ?>
                            <span class="pill-badge success">+ ADDED</span>
                        <?php elseif ($e['change_type'] === 'MODIFIED'): ?>
                            <span class="pill-badge warning">~ MODIFIED</span>
                        <?php elseif ($e['change_type'] === 'DELETED'): ?>
                            <span class="pill-badge danger">- DELETED</span>
                        <?php else: ?>
                            <span class="pill-badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim);">= UNCHANGED</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($e['verified_hash']): ?>
                            <span class="code-pill" title="<?= htmlspecialchars($e['verified_hash']) ?>"><?= substr($e['verified_hash'], 0, 16) ?>...</span>
                        <?php else: ?>
                            <span style="color: var(--text-dim);">(Không có)</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.8rem;"><?= htmlspecialchars($e['file_mtime'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>
