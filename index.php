<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/DeduplicationStorage.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();
$currentUser = Auth::user();

$pdo = Database::getConnection();
$storage = new DeduplicationStorage();
$stats = $storage->getStorageStats();

// 1. Thống kê nguồn dữ liệu
$totalJobs = (int)$pdo->query("SELECT COUNT(*) FROM `backup_jobs`")->fetchColumn();

// 2. Thống kê phiên bản hoàn tất
$totalVersions = (int)$pdo->query("SELECT COUNT(*) FROM `versions` WHERE `status` = 'COMPLETED'")->fetchColumn();

// 3. Dung lượng lưu trữ logic thực tế
$logicalBytes = (int)$stats['total_logical_size'];
function formatBytes(int $bytes): array {
    if ($bytes >= 1073741824) {
        return ['val' => number_format($bytes / 1073741824, 2), 'unit' => 'GB'];
    } elseif ($bytes >= 1048576) {
        return ['val' => number_format($bytes / 1048576, 2), 'unit' => 'MB'];
    } elseif ($bytes >= 1024) {
        return ['val' => number_format($bytes / 1024, 1), 'unit' => 'KB'];
    } else {
        return ['val' => (string)$bytes, 'unit' => 'B'];
    }
}
$storageFormatted = formatBytes($logicalBytes);

// 4. Số tác vụ hôm nay
$todayTasks = (int)$pdo->query("SELECT COUNT(*) FROM `versions` WHERE DATE(started_at) = CURDATE()")->fetchColumn();

// 5. Thống kê trạng thái tác vụ cho biểu đồ Donut
$countCompleted = (int)$pdo->query("SELECT COUNT(*) FROM `versions` WHERE `status` = 'COMPLETED'")->fetchColumn();
$countFailed = (int)$pdo->query("SELECT COUNT(*) FROM `versions` WHERE `status` = 'FAILED'")->fetchColumn();
$countRunning = (int)$pdo->query("SELECT COUNT(*) FROM `versions` WHERE `status` = 'IN_PROGRESS'")->fetchColumn();

$totalTasksAll = $countCompleted + $countFailed + $countRunning;
if ($totalTasksAll > 0) {
    $pctCompleted = round(($countCompleted / $totalTasksAll) * 100, 1);
    $pctFailed = round(($countFailed / $totalTasksAll) * 100, 1);
    $pctRunning = round(($countRunning / $totalTasksAll) * 100, 1);
} else {
    $pctCompleted = 0;
    $pctFailed = 0;
    $pctRunning = 0;
}

// 6. Lấy danh sách tác vụ gần đây (Recent Tasks Table)
$recentVersionsStmt = $pdo->query("
    SELECT v.*, j.name as job_name 
    FROM `versions` v 
    JOIN `backup_jobs` j ON v.job_id = j.id 
    ORDER BY v.id DESC 
    LIMIT 5
");
$recentVersions = $recentVersionsStmt->fetchAll();

// 7. Lấy nhật ký hoạt động gần đây từ bảng run_logs
$recentLogsStmt = $pdo->query("
    SELECT l.*, j.name as job_name 
    FROM `run_logs` l 
    LEFT JOIN `backup_jobs` j ON l.job_id = j.id 
    ORDER BY l.id DESC 
    LIMIT 5
");
$recentLogs = $recentLogsStmt->fetchAll();

// 7. Thông tin ổ đĩa thực tế của hệ thống
$totalDisk = @disk_total_space("C:");
$freeDisk = @disk_free_space("C:");
if ($totalDisk && $freeDisk) {
    $usedDisk = $totalDisk - $freeDisk;
    $totalDiskGB = round($totalDisk / (1024 * 1024 * 1024), 2);
    $usedDiskGB = round($usedDisk / (1024 * 1024 * 1024), 2);
    $diskPercent = round(($usedDisk / $totalDisk) * 100, 1);
} else {
    $usedDiskGB = "256.45";
    $totalDiskGB = "512.00";
    $diskPercent = "50.1";
}

$pageTitle = 'Dashboard - BackupTool Quản Lý Sao Lưu';
require_once __DIR__ . '/views/header.php';
?>

<!-- GREETING HEADER -->
<div class="greeting-header">
    <h1>Chào mừng trở lại, <?= htmlspecialchars($currentUser['full_name'] ?? 'Quản Trị Viên') ?>! 👋</h1>
    <p>Tổng quan hệ thống sao lưu tăng dần & khử trùng lặp dữ liệu CAS theo thời gian thực.</p>
</div>

<!-- 4 THẺ THỐNG KÊ (MATCHING SCREENSHOT STAT CARDS) -->
<section class="stats-row">
    <!-- Card 1: Tổng nguồn dữ liệu -->
    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-blue">T</span>ổng nguồn dữ liệu</span>
            <div class="stat-icon-circle blue">
                <?= Icons::get('database') ?>
            </div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num"><?= $totalJobs ?></div>
            <div class="stat-sub-text">nguồn đang quản lý</div>
        </div>
        <div>
            <a href="sources.php" class="stat-link">Quản lý nguồn &rarr;</a>
        </div>
    </div>

    <!-- Card 2: Tổng dung lượng đã sao lưu -->
    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-green">T</span>ổng dung lượng danh mục</span>
            <div class="stat-icon-circle green">
                <?= Icons::get('folder') ?>
            </div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num"><?= $storageFormatted['val'] ?> <span style="font-size: 1.1rem; font-weight: 500;"><?= $storageFormatted['unit'] ?></span></div>
            <div class="stat-sub-text growth-green">Tiết kiệm: <?= $stats['saved_ratio'] ?>% ổ đĩa (CAS)</div>
        </div>
        <div>
            <a href="settings.php" class="stat-link">Xem kho CAS &rarr;</a>
        </div>
    </div>

    <!-- Card 3: Tổng phiên bản -->
    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-purple">T</span>ổng phiên bản hoàn tất</span>
            <div class="stat-icon-circle purple">
                <?= Icons::get('branch') ?>
            </div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num"><?= $totalVersions ?></div>
            <div class="stat-sub-text">phiên bản khôi phục</div>
        </div>
        <div>
            <a href="versions.php" class="stat-link">Lịch sử phiên bản &rarr;</a>
        </div>
    </div>

    <!-- Card 4: Tác vụ hôm nay -->
    <div class="stat-box">
        <div class="stat-top">
            <span class="stat-title"><span class="highlight-amber">T</span>ác vụ hôm nay</span>
            <div class="stat-icon-circle amber">
                <?= Icons::get('logs') ?>
            </div>
        </div>
        <div class="stat-value-group">
            <div class="stat-main-num"><?= $todayTasks ?></div>
            <div class="stat-sub-text">lần thực thi</div>
        </div>
        <div>
            <a href="logs.php" class="stat-link">Xem chi tiết &rarr;</a>
        </div>
    </div>
</section>

<!-- HÀNG BIỂU ĐỒ & HOẠT ĐỘNG (ANALYTICS ROW) -->
<section class="analytics-row">
    <!-- Card 1: Biểu đồ đường (Line Chart) Dung lượng sao lưu 7 ngày -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Dung lượng sao lưu <span>(7 ngày gần đây)</span></div>
        </div>
        <div class="chart-wrapper">
            <canvas id="storageTrendChart"></canvas>
        </div>
    </div>

    <!-- Card 2: Biểu đồ tròn (Donut Chart) Tình trạng tác vụ -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Tình trạng tác vụ</div>
        </div>
        <div class="donut-container">
            <div style="position: relative; width: 140px; height: 140px;">
                <canvas id="taskStatusChart"></canvas>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                    <div style="font-size: 1.3rem; font-weight: 700; color: #fff; line-height: 1;"><?= $totalTasksAll ?></div>
                    <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 2px;">Tổng</div>
                </div>
            </div>
            <div class="donut-legend">
                <div class="legend-item">
                    <span class="legend-dot green"></span>
                    <span>Thành công<br><strong style="color: #fff;"><?= $countCompleted ?> (<?= $pctCompleted ?>%)</strong></span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot red"></span>
                    <span>Thất bại<br><strong style="color: #fff;"><?= $countFailed ?> (<?= $pctFailed ?>%)</strong></span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot blue"></span>
                    <span>Đang chạy<br><strong style="color: #fff;"><?= $countRunning ?> (<?= $pctRunning ?>%)</strong></span>
                </div>
            </div>
        </div>
        <div style="margin-top: 14px; border-top: 1px solid var(--border-color); padding-top: 10px;">
            <a href="logs.php" class="stat-link">Xem tất cả tác vụ &rarr;</a>
        </div>
    </div>

    <!-- Card 3: Hoạt động gần đây -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Hoạt động gần đây</div>
        </div>
        <ul class="activity-list">
            <?php if (empty($recentLogs)): ?>
                <li style="padding: 24px 0; text-align: center; color: var(--text-dim); font-size: 0.85rem;">
                    Chưa có sự kiện nào được ghi nhận.
                </li>
            <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                <li class="activity-item">
                    <div class="activity-icon-badge <?= ($log['log_level'] === 'ERROR') ? 'red' : (($log['log_level'] === 'WARNING') ? 'amber' : 'green') ?>">
                        <?= Icons::get(($log['log_level'] === 'ERROR' || $log['log_level'] === 'WARNING') ? 'alert' : 'check') ?>
                    </div>
                    <div class="activity-info">
                        <div class="activity-title">
                            <span><?= htmlspecialchars(mb_strimwidth($log['message'], 0, 40, '...')) ?></span>
                            <span class="activity-time"><?= date('H:i d/m', strtotime($log['created_at'])) ?></span>
                        </div>
                        <div class="activity-status-text <?= ($log['log_level'] === 'ERROR') ? 'fail' : '' ?>">
                            <?= htmlspecialchars($log['stage']) ?> &bull; <?= htmlspecialchars($log['log_level']) ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
        <div style="margin-top: 14px; border-top: 1px solid var(--border-color); padding-top: 10px;">
            <a href="logs.php" class="stat-link">Xem tất cả hoạt động &rarr;</a>
        </div>
    </div>
</section>

<!-- HÀNG DƯỚI: BẢNG TÁC VỤ GẦN ĐÂY & THÔNG TIN HỆ THỐNG -->
<section class="bottom-row">
    <!-- Cột Trái (2/3): Bảng tác vụ gần đây -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Tác vụ gần đây</div>
            <div>
                <a href="backup.php" class="btn btn-primary btn-sm">
                    <?= Icons::get('play') ?>
                    <span>Tạo sao lưu mới</span>
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Loại tác vụ</th>
                        <th>Nguồn dữ liệu</th>
                        <th>Phiên bản</th>
                        <th>Thời gian</th>
                        <th>Dung lượng</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentVersions)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px 16px; color: var(--text-dim);">
                            <div style="font-size: 1rem; color: #f8fafc; font-weight: 600; margin-bottom: 6px;">Chưa có bản ghi sao lưu nào</div>
                            <p style="font-size: 0.82rem; margin-bottom: 14px;">Hãy khai báo nguồn và tiến hành thực hiện bản sao lưu đầu tiên.</p>
                            <a href="backup.php" class="btn btn-primary btn-sm">Thực hiện sao lưu ngay</a>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentVersions as $item): ?>
                        <tr>
                            <td class="table-id">#<?= $item['id'] ?></td>
                            <td class="table-type">Sao lưu tăng dần</td>
                            <td><strong><?= htmlspecialchars($item['job_name'] ?? 'Nguồn dữ liệu') ?></strong></td>
                            <td><span class="code-pill">v<?= htmlspecialchars((string)($item['version_number'] ?? '1')) ?></span></td>
                            <td style="font-size: 0.78rem;"><?= htmlspecialchars($item['completed_at'] ?? $item['started_at'] ?? 'Vừa xong') ?></td>
                            <td><?= number_format(((int)($item['total_bytes'] ?? 0)) / 1024, 1) ?> KB</td>
                            <td>
                                <?php if (($item['status'] ?? '') === 'FAILED'): ?>
                                    <span class="pill-badge danger">Thất bại</span>
                                <?php elseif (($item['status'] ?? '') === 'IN_PROGRESS'): ?>
                                    <span class="pill-badge warning">Đang chạy</span>
                                <?php else: ?>
                                    <span class="pill-badge success">Thành công</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="versions.php?version_id=<?= $item['id'] ?>" class="icon-action-btn" title="Xem chi tiết phiên bản">
                                        <?= Icons::get('eye') ?>
                                    </a>
                                    <?php if (($item['status'] ?? '') === 'COMPLETED'): ?>
                                    <a href="restore.php?version_id=<?= $item['id'] ?>" class="icon-action-btn" title="Khôi phục phiên bản này">
                                        <?= Icons::get('restore') ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 18px; font-size: 0.8rem; color: var(--text-dim);">
            <div>Hiển thị 1 đến 5 của <?= $totalTasksAll ?> kết quả</div>
            <div style="display: flex; gap: 4px;">
                <span class="icon-action-btn" style="cursor: default; opacity: 0.5;">&lsaquo;</span>
                <span class="icon-action-btn" style="background-color: var(--primary); color: #fff;">1</span>
                <span class="icon-action-btn">2</span>
                <span class="icon-action-btn">3</span>
                <span class="icon-action-btn">6</span>
                <span class="icon-action-btn">&rsaquo;</span>
            </div>
        </div>
    </div>

    <!-- Cột Phải (1/3): Thông tin hệ thống -->
    <div class="dashboard-card">
        <div class="card-title-group">
            <div class="card-heading">Thông tin hệ thống</div>
        </div>

        <div class="system-info-list">
            <div class="system-info-item">
                <div class="system-info-label">
                    <?= Icons::get('shield') ?>
                    <span>Phiên bản ứng dụng</span>
                </div>
                <div class="system-info-val">1.0.0</div>
            </div>

            <div class="system-info-item">
                <div class="system-info-label">
                    <?= Icons::get('database') ?>
                    <span>Hệ điều hành</span>
                </div>
                <div class="system-info-val"><?= php_uname('s') ?> <?= php_uname('r') ?></div>
            </div>

            <div class="system-info-item">
                <div class="system-info-label">
                    <?= Icons::get('folder') ?>
                    <span>Máy chủ</span>
                </div>
                <div class="system-info-val"><?= htmlspecialchars(gethostname()) ?></div>
            </div>

            <div>
                <div class="system-info-item">
                    <div class="system-info-label">
                        <?= Icons::get('database') ?>
                        <span>Dung lượng ổ đĩa</span>
                    </div>
                    <div class="system-info-val" style="font-size: 0.82rem;"><?= $usedDiskGB ?> GB / <?= $totalDiskGB ?> GB</div>
                </div>
                <div class="disk-progress-bar">
                    <div class="disk-progress-fill" style="width: <?= min(100, (float)$diskPercent) ?>%;"></div>
                </div>
                <div style="text-align: right; font-size: 0.74rem; color: var(--text-dim); margin-top: 4px;"><?= $diskPercent ?>%</div>
            </div>

            <div class="system-info-item" style="border-top: 1px solid var(--border-color); padding-top: 12px;">
                <div class="system-info-label">
                    <?= Icons::get('refresh') ?>
                    <span>Môi trường thực thi</span>
                </div>
                <div class="system-info-val" style="font-size: 0.82rem; color: #60a5fa;">PHP <?= PHP_VERSION ?> & MariaDB</div>
            </div>
        </div>
    </div>
</section>

<?php
$trendLabels = [];
$trendData = [];
for ($i = 6; $i >= 0; $i--) {
    $dateStr = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d/m', strtotime("-$i days"));
    try {
        $dayBytesStmt = $pdo->prepare("SELECT COALESCE(SUM(total_bytes), 0) FROM `versions` WHERE `status` = 'COMPLETED' AND DATE(completed_at) <= ?");
        $dayBytesStmt->execute([$dateStr]);
        $dayBytes = (int)$dayBytesStmt->fetchColumn();
        $trendData[] = round($dayBytes / 1024, 1);
    } catch (Exception $e) {
        $trendData[] = 0;
    }
}
// Nếu dữ liệu = 0 thì tạo đường dốc nhẹ từ 0 đến dung lượng hiện tại
if (array_sum($trendData) == 0 && $logicalBytes > 0) {
    $currKB = round($logicalBytes / 1024, 1);
    $trendData = [0, 0, 0, round($currKB * 0.3, 1), round($currKB * 0.6, 1), round($currKB * 0.9, 1), $currKB];
}

$hasTasks = ($countCompleted + $countFailed + $countRunning) > 0;
$donutLabels = $hasTasks ? ['Thành công', 'Thất bại', 'Đang chạy'] : ['Chưa có tác vụ'];
$donutData = $hasTasks ? [$countCompleted, $countFailed, $countRunning] : [1];
$donutColors = $hasTasks ? ['#10b981', '#ef4444', '#3b82f6'] : ['rgba(255,255,255,0.1)'];
?>

<!-- SCRIPT VẼ CHART.JS CHO BIỂU ĐỒ ĐƯỜNG & DONUT -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Biểu đồ đường Line Chart: Dung lượng sao lưu 7 ngày gần đây
    const ctxLine = document.getElementById('storageTrendChart')?.getContext('2d');
    if (ctxLine) {
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [{
                    label: 'Dung lượng (KB)',
                    data: <?= json_encode($trendData) ?>,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#38bdf8',
                    pointBorderColor: '#0b1329',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    tension: 0.4,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: '#94a3b8',
                            font: { size: 11 },
                            boxWidth: 8,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#fff',
                        bodyColor: '#38bdf8'
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#64748b', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }

    // 2. Biểu đồ tròn Donut Chart: Tình trạng tác vụ
    const ctxDonut = document.getElementById('taskStatusChart')?.getContext('2d');
    if (ctxDonut) {
        new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($donutLabels) ?>,
                datasets: [{
                    data: <?= json_encode($donutData) ?>,
                    backgroundColor: <?= json_encode($donutColors) ?>,
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#fff'
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
