<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/BackupEngine.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();

$pdo = Database::getConnection();

// Lấy danh sách tất cả các nguồn dữ liệu
$jobsStmt = $pdo->query("SELECT * FROM `backup_jobs` ORDER BY id DESC");
$allJobs = $jobsStmt->fetchAll();

$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? ($allJobs[0]['id'] ?? 0));

$previewData = null;
$error = null;
$successMsg = null;

$engine = new BackupEngine();

// Xử lý khi người dùng ấn nút Thực hiện sao lưu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_backup') {
    $targetJobId = (int)$_POST['job_id'];
    $versionNote = trim($_POST['version_note'] ?? '');

    try {
        $result = $engine->executeBackup($targetJobId);
        if ($result['status'] === 'COMPLETED') {
            $successMsg = "ĐÃ TẠO SAO LƯU THÀNH CÔNG PHIÊN BẢN v{$result['version_number']}! Đã xử lý {$result['total_files']} tệp (+{$result['added_count']} mới, ~{$result['modified_count']} sửa, -{$result['deleted_count']} xóa).";
        } else {
            $error = "Sao lưu bị gián đoạn hoặc lỗi: " . ($result['error'] ?? 'Lỗi không xác định');
        }
    } catch (Exception $e) {
        $error = "Lỗi khi sao lưu: " . $e->getMessage();
    }
}

// Lấy dữ liệu phân tích Preview nếu có job
if ($jobId > 0) {
    try {
        $previewData = $engine->preview($jobId);
    } catch (Exception $e) {
        $error = "Không thể phân tích thư mục nguồn: " . $e->getMessage();
    }
}

$pageTitle = 'Thực Hiện Sao Lưu Tăng Dần';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Thực Hiện Sao Lưu Tăng Dần (Incremental Backup)
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Hệ thống tự động so khớp mã băm SHA-256 để tìm các tệp thêm mới, sửa đổi hoặc xóa mà không tốn dung lượng lưu trữ lặp lại.
        </p>
    </div>

    <div>
        <a href="sources.php" class="btn btn-secondary">
            <?= Icons::get('folder') ?>
            <span>Quản Lý Nguồn</span>
        </a>
    </div>
</div>

<?php if ($successMsg): ?>
<div class="alert alert-success">
    <?= Icons::get('check-circle') ?>
    <span><?= htmlspecialchars($successMsg) ?></span>
    <a href="versions.php?job_id=<?= $jobId ?>" style="color: #fff; text-decoration: underline; margin-left: 12px; font-weight: 600;">Xem trong lịch sử phiên bản &rarr;</a>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger">
    <?= Icons::get('alert') ?>
    <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<?php if (empty($allJobs)): ?>
<!-- CHƯA CÓ NGUỒN DỮ LIỆU -->
<div class="dashboard-card" style="text-align: center; padding: 48px 20px; margin-bottom: 24px;">
    <div style="width: 56px; height: 56px; background: rgba(59,130,246,0.15); border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; color: #38bdf8; margin-bottom: 16px;">
        <?= Icons::get('folder') ?>
    </div>
    <h3 style="font-size: 1.2rem; font-weight: 700; color: #f8fafc; margin-bottom: 8px;">Chưa có nguồn dữ liệu nào được khai báo</h3>
    <p style="color: var(--text-dim); font-size: 0.9rem; max-width: 500px; margin: 0 auto 20px auto;">
        Để thực hiện sao lưu tăng dần (Incremental Backup), bạn cần khai báo ít nhất một thư mục nguồn dữ liệu cần bảo vệ.
    </p>
    <a href="sources.php#formSource" class="btn btn-primary" style="padding: 11px 24px;">
        <?= Icons::get('plus') ?> <span>+ Khai Báo Nguồn Dữ Liệu Ngay</span>
    </a>
</div>
<?php else: ?>

<!-- BƯỚC 1: CHỌN NGUỒN DỮ LIỆU -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <div class="card-title-group">
        <div class="card-heading">Bước 1: Chọn nguồn dữ liệu cần sao lưu</div>
    </div>

    <form action="backup.php" method="GET" style="display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 280px;">
            <label class="form-label" for="job_id">Nguồn dữ liệu:</label>
            <select class="form-control" id="job_id" name="job_id" onchange="this.form.submit();">
                <?php foreach ($allJobs as $job): ?>
                <option value="<?= $job['id'] ?>" <?= ($job['id'] == $jobId) ? 'selected' : '' ?>>
                    #<?= $job['id'] ?> - <?= htmlspecialchars($job['name']) ?> (<?= htmlspecialchars($job['source_path']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <button type="submit" class="btn btn-secondary">
                <?= Icons::get('refresh') ?>
                <span>Quét & Phân Tích Lại</span>
            </button>
        </div>
    </form>
</div>

<?php if ($previewData): ?>
<?php 
    $summary = $previewData['summary'];
    $diff = $previewData['diff'];
?>

<!-- BƯỚC 2: KẾT QUẢ PHÂN TÍCH TĂNG DẦN -->
<section class="stats-row" style="margin-bottom: 24px;">
    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-green">T</span>ệp thêm mới (Added)</span>
            <div class="stat-icon-circle green"><?= Icons::get('check') ?></div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num" style="color: #34d399;">+<?= $summary['added_count'] ?></div>
            <div class="stat-sub-text">tệp mới chưa có trong kho</div>
        </div>
    </div>

    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-amber">T</span>ệp sửa đổi (Modified)</span>
            <div class="stat-icon-circle amber"><?= Icons::get('alert') ?></div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num" style="color: #fbbf24;">~<?= $summary['modified_count'] ?></div>
            <div class="stat-sub-text">tệp bị thay đổi nội dung</div>
        </div>
    </div>

    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span style="color: #f87171;">T</span>ệp đã xóa (Deleted)</span>
            <div class="stat-icon-circle" style="background-color: rgba(239, 68, 68, 0.2); color: #f87171;"><?= Icons::get('alert') ?></div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num" style="color: #f87171;">-<?= $summary['deleted_count'] ?></div>
            <div class="stat-sub-text">bảo toàn trong bản cũ</div>
        </div>
    </div>

    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-blue">T</span>ệp giữ nguyên (Unchanged)</span>
            <div class="stat-icon-circle blue"><?= Icons::get('check-circle') ?></div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num" style="color: #60a5fa;">=<?= $summary['unchanged_count'] ?></div>
            <div class="stat-sub-text">khử trùng lặp, không tốn ổ đĩa</div>
        </div>
    </div>
</section>

<!-- BƯỚC 3: XÁC NHẬN VÀ THỰC THI SAO LƯU -->
<div class="dashboard-card" style="margin-bottom: 24px;">
    <div class="card-title-group">
        <div class="card-heading">Bước 2: Xác nhận và Tiến hành tạo bản sao lưu</div>
    </div>

    <form action="backup.php" method="POST" style="display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="action" value="run_backup">
        <input type="hidden" name="job_id" value="<?= $jobId ?>">

        <div style="flex: 1; min-width: 280px;">
            <label class="form-label" for="version_note">Ghi chú cho phiên bản v<?= $summary['next_version_number'] ?>:</label>
            <input type="text" class="form-control" id="version_note" name="version_note" placeholder="Ví dụ: Sao lưu định kỳ, cập nhật mã nguồn dự án...">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="padding: 11px 26px; font-size: 0.95rem;">
                <?= Icons::get('play') ?>
                <span>Tạo Bản Sao Lưu Phiên Bản v<?= $summary['next_version_number'] ?></span>
            </button>
        </div>
    </form>
</div>

<!-- BẢNG DANH MỤC THAY ĐỔI CHI TIẾT -->
<div class="dashboard-card">
    <div class="card-title-group">
        <div class="card-heading">
            Chi tiết các tệp trong nguồn (Tổng: <?= count($diff['all_items']) ?> tệp)
        </div>
        <div>
            <input type="text" id="fileSearch" class="form-control" placeholder="Tìm kiếm tên tệp..." style="max-width: 240px; padding: 6px 12px; font-size: 0.82rem;" onkeyup="filterFiles()">
        </div>
    </div>

    <div class="table-responsive">
        <table class="custom-table" id="fileTable">
            <thead>
                <tr>
                    <th style="width: 50px;">STT</th>
                    <th>Đường dẫn tệp tương đối</th>
                    <th>Kích thước</th>
                    <th>Trạng thái</th>
                    <th>Mã băm SHA-256</th>
                    <th>Hành động lưu trữ CAS</th>
                </tr>
            </thead>
            <tbody>
                <?php $idx = 1; foreach ($diff['all_items'] as $item): ?>
                <tr>
                    <td class="table-id"><?= $idx++ ?></td>
                    <td>
                        <strong style="color: var(--text-main);"><?= htmlspecialchars($item['relative_path']) ?></strong>
                    </td>
                    <td><?= number_format($item['size']) ?> B</td>
                    <td>
                        <?php if ($item['status'] === 'ADDED'): ?>
                            <span class="pill-badge success">+ ADDED</span>
                        <?php elseif ($item['status'] === 'MODIFIED'): ?>
                            <span class="pill-badge warning">~ MODIFIED</span>
                        <?php elseif ($item['status'] === 'DELETED'): ?>
                            <span class="pill-badge danger">- DELETED</span>
                        <?php else: ?>
                            <span class="pill-badge" style="background-color: rgba(255,255,255,0.06); color: var(--text-muted);">= UNCHANGED</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="code-pill"><?= substr($item['hash'] ?? '', 0, 16) ?>...</span>
                    </td>
                    <td style="font-size: 0.8rem; color: var(--text-dim);">
                        <?php if ($item['status'] === 'ADDED'): ?>
                            Lưu object mới vào kho CAS vật lý
                        <?php elseif ($item['status'] === 'MODIFIED'): ?>
                            Băm lại nội dung, tạo object mới
                        <?php elseif ($item['status'] === 'DELETED'): ?>
                            Ghi nhận xóa, bảo toàn object bản cũ
                        <?php else: ?>
                            Kế thừa tham chiếu (khử trùng lặp)
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterFiles() {
    const input = document.getElementById('fileSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#fileTable tbody tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
</script>
<?php endif; // End check if previewData ?>
<?php endif; // End check if empty allJobs ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>
