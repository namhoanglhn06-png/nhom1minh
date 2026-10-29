<?php
declare(strict_types=1);

/**
 * Script tự động sinh 200 tệp trong 10 thư mục để kiểm thử thực tế
 * Đáp ứng chính xác yêu cầu Mục 5 của Đề tài:
 * - 200 tệp trong 10 thư mục
 * - Có tệp rỗng (0 bytes)
 * - Tệp tên Unicode Tiếng Việt
 * - Tệp tên có khoảng trắng
 * - Tệp có nội dung trùng lặp (để kiểm tra deduplication)
 */

$baseDir = __DIR__ . '/../test_data/dataset_200_files';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$folders = [
    '01_tai_lieu_chinh',
    '02_source_code',
    '03_tiếng_việt_unicode',
    '04_spaces in folder names',
    '05_empty_files',
    '06_duplicate_group_a',
    '07_duplicate_group_b',
    '08_configs_settings',
    '09_assets_media',
    '10_system_logs'
];

foreach ($folders as $f) {
    $dirPath = $baseDir . '/' . $f;
    if (!is_dir($dirPath)) {
        mkdir($dirPath, 0777, true);
    }
}

$duplicateContentAlpha = "NỘI DUNG TRÙNG LẶP A: Đây là nội dung chuẩn để kiểm tra tính năng khử trùng lặp vật lý (Content-Addressable Storage). Mã băm SHA-256 sẽ giống nhau hoàn toàn giữa các tệp này!";
$duplicateContentBeta = "NỘI DUNG TRÙNG LẶP B: Đoạn văn bản mẫu kiểm thử thuật toán deduplication. Dù nằm ở nhiều thư mục khác nhau, hệ thống chỉ lưu đúng 1 bản sao vật lý duy nhất trong kho objects!";

$totalCreated = 0;

// Thư mục 1: Tài liệu (20 tệp văn bản)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/01_tai_lieu_chinh/document_{$i}.txt", "Tài liệu số {$i}: Dự án mã nguồn mở sao lưu dữ liệu.");
    $totalCreated++;
}

// Thư mục 2: Source code (20 tệp code)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/02_source_code/module_{$i}.php", "<?php\n// Module code {$i}\necho 'Module {$i} loaded';\n");
    $totalCreated++;
}

// Thư mục 3: Unicode Tiếng Việt (20 tệp tên tiếng Việt có dấu và biểu tượng)
$unicodeNames = [
    'báo cáo tiến độ đề tài 9.txt',
    'danh mục sinh viên thực hiện.csv',
    'kế hoạch sao lưu tăng dần.json',
    'hướng dẫn sử dụng phần mềm.md',
    'biên bản kiểm thử nghiệm thu.txt',
    'thuyết minh bài tập lớn mở.txt',
    'cấu hình hệ thống sao lưu.ini',
    'bảng chấm điểm rubric 10 điểm.txt',
    'tài liệu kỹ thuật khôi phục.txt',
    'nhật ký phiên bản hệ thống.log',
    'mã nguồn mở phát triển web.php',
    'dữ liệu mẫu khách hàng hà nội.csv',
    'danh sách chi nhánh đà nẵng.json',
    'tổng kết dự án cuối kỳ.txt',
    'sơ đồ kiến trúc phần mềm 🇻🇳.txt',
    'phương án an toàn dữ liệu.txt',
    'hồ sơ bàn giao công nghệ.txt',
    'quy trình sao lưu tăng dần.txt',
    'đặc tả chức năng khôi phục.txt',
    'giấy phép mã nguồn mở mit.txt'
];
foreach ($unicodeNames as $uName) {
    file_put_contents("{$baseDir}/03_tiếng_việt_unicode/{$uName}", "Nội dung tệp Unicode: {$uName}");
    $totalCreated++;
}

// Thư mục 4: Tệp có khoảng trắng (20 tệp)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/04_spaces in folder names/my important file number {$i}.txt", "Tệp có tên chứa khoảng trắng số {$i}");
    $totalCreated++;
}

// Thư mục 5: Tệp rỗng (20 tệp rỗng 0 bytes)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/05_empty_files/empty_file_{$i}.dat", "");
    $totalCreated++;
}

// Thư mục 6: Tệp trùng nội dung Nhóm A (20 tệp trùng)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/06_duplicate_group_a/dup_alpha_{$i}.txt", $duplicateContentAlpha);
    $totalCreated++;
}

// Thư mục 7: Tệp trùng nội dung Nhóm B (20 tệp trùng với nhóm A và B)
for ($i = 1; $i <= 10; $i++) {
    file_put_contents("{$baseDir}/07_duplicate_group_b/dup_alpha_cross_{$i}.txt", $duplicateContentAlpha);
    $totalCreated++;
}
for ($i = 11; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/07_duplicate_group_b/dup_beta_{$i}.txt", $duplicateContentBeta);
    $totalCreated++;
}

// Thư mục 8: Cấu hình settings (20 tệp)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/08_configs_settings/config_server_{$i}.json", json_encode(['server_id' => $i, 'port' => 8000 + $i, 'active' => true], JSON_PRETTY_PRINT));
    $totalCreated++;
}

// Thư mục 9: Assets media (20 tệp giả lập media/icon)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/09_assets_media/asset_icon_{$i}.svg", "<svg><circle cx='{$i}' cy='{$i}' r='10'/></svg>");
    $totalCreated++;
}

// Thư mục 10: System logs (20 tệp)
for ($i = 1; $i <= 20; $i++) {
    file_put_contents("{$baseDir}/10_system_logs/audit_log_{$i}.log", "[2026-10-26 15:00:00] Log event #{$i} generated successfully.");
    $totalCreated++;
}

if (php_sapi_name() !== 'cli') {
    header('Location: ../test_suite.php?seed_success=' . $totalCreated);
    exit;
}

echo "ĐÃ TẠO THÀNH CÔNG: {$totalCreated} tệp phân bố trong 10 thư mục tại: {$baseDir}\n";
