<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Icons.php';
require_once __DIR__ . '/../core/Auth.php';

// Bắt buộc xác thực người dùng - Chống truy cập trái phép
Auth::requireAuth();
$currentUser = Auth::user();

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'BackupTool - Sao lưu tăng dần & Khôi phục dữ liệu theo phiên bản') ?></title>
    <link rel="stylesheet" href="public/css/style.css">
    <!-- Chart.js cho các biểu đồ chuyên nghiệp -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="app-container">
    <!-- SIDEBAR BÊN TRÁI (DARK BLUE) -->
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo-icon">
                <?= Icons::get('shield') ?>
            </div>
            <div class="sidebar-logo-text">
                <h2>BackupTool</h2>
                <p>Sao lưu tăng dần & Khôi phục<br>dữ liệu theo phiên bản</p>
            </div>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section-label">Tổng Quan</div>
            <ul class="sidebar-menu">
                <li class="menu-item <?= ($currentPage === 'index.php') ? 'active' : '' ?>">
                    <a href="index.php">
                        <?= Icons::get('database') ?>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'sources.php') ? 'active' : '' ?>">
                    <a href="sources.php">
                        <?= Icons::get('folder') ?>
                        <span>Nguồn dữ liệu</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'backup.php') ? 'active' : '' ?>">
                    <a href="backup.php">
                        <?= Icons::get('play') ?>
                        <span>Sao lưu</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'versions.php') ? 'active' : '' ?>">
                    <a href="versions.php">
                        <?= Icons::get('branch') ?>
                        <span>Phiên bản</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'restore.php') ? 'active' : '' ?>">
                    <a href="restore.php">
                        <?= Icons::get('restore') ?>
                        <span>Khôi phục</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'logs.php') ? 'active' : '' ?>">
                    <a href="logs.php">
                        <?= Icons::get('logs') ?>
                        <span>Lịch sử tác vụ</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'schedules.php') ? 'active' : '' ?>">
                    <a href="schedules.php">
                        <?= Icons::get('refresh') ?>
                        <span>Lịch biểu</span>
                    </a>
                </li>
                <li class="menu-item <?= ($currentPage === 'test_suite.php') ? 'active' : '' ?>">
                    <a href="test_suite.php">
                        <?= Icons::get('test') ?>
                        <span>Kiểm thử Rubric (5 Tests)</span>
                    </a>
                </li>
            </ul>

            <div class="nav-section-label">Cài Đặt & Tài Khoản</div>
            <ul class="sidebar-menu">
                <li class="menu-item <?= ($currentPage === 'settings.php') ? 'active' : '' ?>">
                    <a href="settings.php">
                        <?= Icons::get('bolt') ?>
                        <span>Cấu hình & Quản lý User</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- THẺ GIỚI THIỆU ĐỀ TÀI DƯỚI GÓC SIDEBAR -->
        <div class="sidebar-project-card">
            <h4>
                <?= Icons::get('shield') ?>
                <span>Giới thiệu đề tài</span>
            </h4>
            <p>Xây dựng công cụ sao lưu tăng dần và khôi phục dữ liệu theo phiên bản nhằm đảm bảo an toàn dữ liệu, tối ưu dung lượng lưu trữ và cho phép khôi phục linh hoạt tại bất kỳ phiên bản nào.</p>
            <div class="sidebar-project-meta">
                <div>Sinh viên thực hiện: <strong>Nhóm 01</strong></div>
                <div style="margin-top: 2px;">Giảng viên hướng dẫn: <strong>ThS. Giảng viên</strong></div>
            </div>
        </div>
    </aside>

    <!-- KHU VỰC NỘI DUNG CHÍNH (MAIN CONTENT) -->
    <div class="main-content">
        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle-btn" id="menuToggle" title="Thu gọn / Mở rộng Menu">
                    <?= Icons::get('list') ?>
                </button>
            </div>

<?php
$warningCount = 0;
try {
    $pdoHeader = Database::getConnection();
    $warningCount = (int)$pdoHeader->query("SELECT COUNT(*) FROM `run_logs` WHERE `log_level` IN ('WARNING', 'ERROR')")->fetchColumn();
} catch (Exception $e) {}
?>
            <div class="topbar-right">
                <a href="logs.php" class="notification-btn" title="Nhật ký & Cảnh báo (<?= $warningCount ?> sự kiện)">
                    <?= Icons::get('alert') ?>
                    <?php if ($warningCount > 0): ?>
                        <span class="notification-badge"><?= min($warningCount, 99) ?></span>
                    <?php endif; ?>
                </a>

                <!-- MENU TÀI KHOẢN NGƯỜI DÙNG CHUYÊN NGHIỆP -->
                <div class="user-dropdown-container" style="position: relative;">
                    <div class="user-profile" id="userMenuToggleBtn" style="cursor: pointer; user-select: none;">
                        <div class="user-avatar" style="background: <?= (($currentUser['role'] ?? '') === 'ADMIN') ? 'linear-gradient(135deg, #3b82f6, #1d4ed8)' : 'linear-gradient(135deg, #10b981, #059669)' ?>;">
                            <?= htmlspecialchars(strtoupper(substr($currentUser['username'] ?? 'U', 0, 1))) ?>
                        </div>
                        <div class="user-details">
                            <span class="user-name"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'User') ?></span>
                            <span class="user-role"><?= (($currentUser['role'] ?? '') === 'ADMIN') ? 'Quản trị viên ▾' : 'Nhân viên vận hành ▾' ?></span>
                        </div>
                    </div>

                    <div class="user-dropdown-panel" id="userDropdownPanel" style="display: none; position: absolute; right: 0; top: calc(100% + 8px); width: 230px; background: #1e293b; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; box-shadow: 0 12px 28px rgba(0,0,0,0.5); z-index: 1000; overflow: hidden; animation: fadeIn 0.2s ease;">
                        <div style="padding: 12px 16px; background: rgba(15,23,42,0.6); border-bottom: 1px solid rgba(255,255,255,0.08);">
                            <div style="font-size: 13px; font-weight: 600; color: #f8fafc;"><?= htmlspecialchars($currentUser['full_name'] ?? '') ?></div>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">@<?= htmlspecialchars($currentUser['username'] ?? '') ?> &bull; <span style="color: #38bdf8;"><?= htmlspecialchars($currentUser['role'] ?? '') ?></span></div>
                        </div>
                        <a href="settings.php#accounts-section" style="display: flex; align-items: center; gap: 10px; padding: 10px 16px; color: #cbd5e1; text-decoration: none; font-size: 13px; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                            <?= Icons::get('user') ?> <span>Quản lý & Sao lưu User</span>
                        </a>
                        <a href="settings.php" style="display: flex; align-items: center; gap: 10px; padding: 10px 16px; color: #cbd5e1; text-decoration: none; font-size: 13px; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                            <?= Icons::get('bolt') ?> <span>Cài đặt hệ thống</span>
                        </a>
                        <div style="border-top: 1px solid rgba(255,255,255,0.08);">
                            <a href="logout.php" style="display: flex; align-items: center; gap: 10px; padding: 10px 16px; color: #ef4444; text-decoration: none; font-size: 13px; transition: background 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                                <?= Icons::get('trash') ?> <span style="font-weight: 500;">Đăng xuất an toàn</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('userMenuToggleBtn');
            const panel = document.getElementById('userDropdownPanel');
            if (toggleBtn && panel) {
                toggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
                });
                document.addEventListener('click', function(e) {
                    if (!panel.contains(e.target) && !toggleBtn.contains(e.target)) {
                        panel.style.display = 'none';
                    }
                });
            }
        });
        </script>

        <!-- PAGE BODY -->
        <div class="page-body">
