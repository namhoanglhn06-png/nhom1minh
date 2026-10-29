<?php
// Script kiểm thử toàn bộ giao diện và các trang của hệ thống
// Đảm bảo không có bất kỳ lỗi Warning, Notice hay Fatal Error nào khi người dùng/giáo viên duyệt web
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Giả lập phiên đăng nhập Admin
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'admin';
$_SESSION['role'] = 'admin';

chdir(__DIR__ . '/..');

$pages = [
    'index.php',
    'sources.php',
    'backup.php',
    'versions.php',
    'restore.php',
    'preview.php',
    'schedules.php',
    'logs.php',
    'settings.php',
    'test_suite.php',
    'login.php',
    'logout.php'
];

$errors = [];
$successCount = 0;

echo "====================================================\n";
echo "   KIỂM THỬ RENDER TOÀN DIỆN CÁC TRANG WEB BTL     \n";
echo "====================================================\n\n";

foreach ($pages as $page) {
    if ($page === 'logout.php') {
        // Không chạy logout vì sẽ hủy session
        continue;
    }
    
    // Giả lập biến $_SERVER
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . $page;
    $_SERVER['PHP_SELF'] = '/' . $page;
    $_SERVER['HTTP_HOST'] = 'localhost:8080';
    $_SERVER['SERVER_PORT'] = '8080';
    
    // Bắt đầu buffer để hứng output và lỗi
    ob_start();
    
    try {
        include $page;
        $content = ob_get_clean();
        
        // Kiểm tra xem có Warning, Notice, Fatal error trong output không
        if (preg_match('/(Fatal error|Warning|Notice|Parse error):/i', $content, $matches)) {
            $errors[$page] = "Chứa cảnh báo/lỗi: " . substr($content, 0, 300);
            echo "[FAIL] $page: Phát hiện PHP Warning/Error!\n";
        } else {
            $len = strlen($content);
            echo "[PASS] $page: Render thành công ({$len} bytes)\n";
            $successCount++;
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        $errors[$page] = "Ngoại lệ: " . $e->getMessage() . " tại " . $e->getFile() . ":" . $e->getLine();
        echo "[FAIL] $page: " . $e->getMessage() . "\n";
    }
}

echo "\n----------------------------------------------------\n";
echo "KẾT QUẢ: $successCount/" . (count($pages) - 1) . " trang PASSED hoàn hảo.\n";

if (!empty($errors)) {
    echo "\nCHI TIẾT LỖI CẦN XỬ LÝ:\n";
    foreach ($errors as $p => $err) {
        echo "- $p: $err\n";
    }
    exit(1);
} else {
    echo "TẤT CẢ CÁC TRANG ĐỀU SẠCH 100%, KHÔNG CÓ LỖI/WARNING NÀO!\n";
    exit(0);
}
