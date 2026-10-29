<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/BackupEngine.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    header('Location: index.php');
    exit;
}

$engine = new BackupEngine();
try {
    $previewData = $engine->preview($jobId);
    $job = $previewData['job'];
    $summary = $previewData['summary'];
    $diff = $previewData['diff'];
} catch (Exception $e) {
    die("Lỗi khi xem trước phạm vi sao lưu: " . htmlspecialchars($e->getMessage()));
}

$pageTitle = 'Xem Trước Phạm Vi & Phân Tích Tăng Dần';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <a href="index.php" class="btn btn-secondary btn-sm" style="margin-bottom: 8px;">
            <?= Icons::get('arrow-left') ?>
            <span>Quay lại trang chủ</span>
        </a>
        <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--gray-900);">
            Phân Tích Thay Đổi Tăng Dần: <?= htmlspecialchars($job['name']) ?>
        </h1>
        <p style="color: var(--gray-500); font-size: 0.9rem; margin-top: 4px;">
            Đối chiếu thư mục nguồn hiện tại với <strong>Phiên bản v<?= $summary['latest_version_number'] ?: '0 (Bản sao đầu tiên)' ?></strong>
        </p>
    </div>

    <div>
        <a href="run_backup.php?job_id=<?= $job['id'] ?>" class="btn btn-primary" style="padding: 12px 24px; font-size: 1rem;">
            <?= Icons::get('play') ?>
            <span>Tiến Hành Sao Lưu Phiên Bản v<?= $summary['next_version_number'] ?></span>
        </a>
    </div>
</div>

<!-- THỐNG KÊ DIFF -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">
            <?= Icons::get('check') ?>
        </div>
        <div class="stat-info">
            <div class="stat-value" style="color: #166534;">+<?= $summary['added_count'] ?></div>
            <div class="stat-label">Tệp mới thêm vào (Added)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <?= Icons::get('alert') ?>
        </div>
        <div class="stat-info">
            <div class="stat-value" style="color: #92400e;">~<?= $summary['modified_count'] ?></div>
            <div class="stat-label">Tệp sửa đổi nội dung (Modified)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: var(--danger-bg); color: var(--danger);">
            <?= Icons::get('alert') ?>
        </div>
        <div class="stat-info">
            <div class="stat-value" style="color: #991b1b;">-<?= $summary['deleted_count'] ?></div>
            <div class="stat-label">Tệp đã bị xóa (Deleted)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">
            <?= Icons::get('check-circle') ?>
        </div>
        <div class="stat-info">
            <div class="stat-value" style="color: #1d4ed8;">=<?= $summary['unchanged_count'] ?></div>
            <div class="stat-label">Tệp giữ nguyên (Unchanged)</div>
        </div>
    </div>
</div>

<!-- DANH MỤC CHI TIẾT TỆP THAY ĐỔI -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <?= Icons::get('list') ?>
            <span>Bảng Danh Mục So Sánh Chi Tiết (Tổng số: <?= count($diff['all_items']) ?> mục)</span>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 50px;">STT</th>
                        <th>Đường dẫn tệp tương đối</th>
                        <th>Kích thước</th>
                        <th>Trạng thái</th>
                        <th>Mã băm SHA-256 nội dung</th>
                        <th>Hành động sao lưu dự kiến</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($diff['all_items'])): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 24px; color: var(--gray-500);">Thư mục nguồn hiện đang rỗng.</td></tr>
                    <?php else: ?>
                        <?php $idx = 1; foreach ($diff['all_items'] as $item): ?>
                        <tr>
                            <td><?= $idx++ ?></td>
                            <td>
                                <span style="font-weight: 600; color: var(--gray-800);"><?= htmlspecialchars($item['relative_path']) ?></span>
                            </td>
                            <td><?= number_format($item['size']) ?> B</td>
                            <td>
                                <?php if ($item['status'] === 'ADDED'): ?>
                                    <span class="badge badge-success">+ ADDED</span>
                                <?php elseif ($item['status'] === 'MODIFIED'): ?>
                                    <span class="badge badge-warning">~ MODIFIED</span>
                                <?php elseif ($item['status'] === 'DELETED'): ?>
                                    <span class="badge badge-danger">- DELETED</span>
                                <?php else: ?>
                                    <span class="badge badge-gray">= UNCHANGED</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="code-pill" title="<?= htmlspecialchars($item['hash'] ?? '') ?>">
                                    <?= substr($item['hash'] ?? '', 0, 16) ?>...
                                </span>
                            </td>
                            <td style="font-size: 0.85rem; color: var(--gray-600);">
                                <?php if ($item['status'] === 'ADDED'): ?>
                                    Lưu object mới vào kho CAS vật lý
                                <?php elseif ($item['status'] === 'MODIFIED'): ?>
                                    Băm lại nội dung, tạo object mới
                                <?php elseif ($item['status'] === 'DELETED'): ?>
                                    Ghi nhận xóa, bảo toàn object bản cũ
                                <?php else: ?>
                                    Kế thừa tham chiếu, không tốn thêm ổ đĩa
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/footer.php'; ?>
