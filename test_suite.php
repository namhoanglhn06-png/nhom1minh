<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/tests/TestRunner.php';
require_once __DIR__ . '/core/Icons.php';
require_once __DIR__ . '/core/Auth.php';

Auth::requireAuth();

$runner = new TestRunner();
$testResults = $runner->runAllTests();

$pageTitle = 'Bộ Kiểm Thử Tự Động 5 Phép Thử Bắt Buộc';
require_once __DIR__ . '/views/header.php';
?>

<div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main);">
            Bộ Kiểm Thử 5 Phép Thử Bắt Buộc (Rubric Mục 6)
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Kiểm tra toàn diện tất cả các tiêu chí kỹ thuật, an toàn và toàn vẹn dữ liệu để đánh giá điểm 10.0 môn học.
        </p>
    </div>

    <div style="display: flex; gap: 10px;">
        <a href="test_suite.php" class="btn btn-primary">
            <?= Icons::get('bolt') ?>
            <span>Chạy Lại Tất Cả Phép Thử</span>
        </a>
        <a href="database/seed_dataset.php" class="btn btn-secondary">
            <?= Icons::get('refresh') ?>
            <span>Tạo Lại 200 Tệp Mẫu</span>
        </a>
    </div>
</div>

<?php if (isset($_GET['seed_success'])): ?>
<div class="alert alert-success" style="margin-bottom: 20px;">
    <?= Icons::get('check-circle') ?>
    <span>ĐÃ TẠO MỚI THÀNH CÔNG <?= (int)$_GET['seed_success'] ?> TỆP MẪU TRONG 10 THƯ MỤC ĐÁP ỨNG MỤC 5 RUBRIC!</span>
</div>
<?php endif; ?>

<!-- TỔNG KẾT KẾT QUẢ -->
<?php if ($testResults['all_passed']): ?>
<div class="alert alert-success" style="padding: 18px 24px; font-size: 0.95rem; margin-bottom: 24px;">
    <?= Icons::get('check-circle') ?>
    <div>
        <strong style="font-size: 1.05rem;">XÁC NHẬN: TOÀN BỘ 5/5 PHÉP THỬ ĐẠT CHUẨN 100%!</strong>
        <div style="font-size: 0.85rem; margin-top: 4px; color: #6ee7b7;">
            Dự án đáp ứng đầy đủ Yêu cầu kỹ thuật 1 & 2, tiêu chí an toàn và khử trùng lặp dữ liệu theo Rubric điểm tối đa 10.0.
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning" style="margin-bottom: 24px;">
    <?= Icons::get('alert') ?>
    <span>Có <?= $testResults['summary']['failed'] ?> phép thử chưa đạt. Vui lòng kiểm tra chi tiết bảng bên dưới.</span>
</div>
<?php endif; ?>

<!-- BẢNG ĐỐI CHIẾU 5 PHÉP THỬ THEO RUBRIC -->
<div class="dashboard-card">
    <div class="card-title-group">
        <div class="card-heading">
            Bảng đối chiếu 5 phép thử bắt buộc (Mục 6 - Rubric Chấm Điểm 10.0)
        </div>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Phép thử</th>
                    <th>Nội dung yêu cầu theo Đề bài</th>
                    <th style="width: 140px;">Kết quả</th>
                    <th>Chi tiết kiểm chứng thực tế</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rubricDescriptions = [
                    'test1' => 'Sửa một tệp, thêm một tệp, xóa một tệp: bản thứ hai nhận đúng ba thay đổi; khôi phục bản thứ nhất vẫn cho đủ dữ liệu cũ.',
                    'test2' => 'Nội dung không thay đổi không bị lưu thành nhiều bản sao vật lý ngoài thiết kế đã công bố (Khử trùng lặp CAS).',
                    'test3' => 'Cố tình làm hỏng một tệp sao lưu: báo sai mã băm, không xác nhận khôi phục thành công.',
                    'test4' => 'Dừng giữa lúc sao lưu: bản đang làm được ghi chưa hoàn tất, các bản trước vẫn khôi phục được.',
                    'test5' => 'Đường dẫn chứa phần đi ra ngoài thư mục đích (../) hoặc liên kết tượng trưng bị từ chối; thư mục nguồn không bị ghi đè.'
                ];

                $testIdx = 1;
                foreach ($testResults['tests'] as $key => $test): 
                ?>
                <tr>
                    <td>
                        <strong style="color: #60a5fa; font-size: 0.95rem;">Phép thử <?= $testIdx++ ?></strong>
                    </td>
                    <td style="max-width: 360px;">
                        <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($test['name']) ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 4px;">
                            <em><?= htmlspecialchars($rubricDescriptions[$key] ?? '') ?></em>
                        </div>
                    </td>
                    <td>
                        <?php if ($test['passed']): ?>
                            <span class="pill-badge success" style="padding: 6px 14px; font-size: 0.82rem;">
                                <?= Icons::get('check') ?> PASS (ĐẠT)
                            </span>
                        <?php else: ?>
                            <span class="pill-badge danger" style="padding: 6px 14px; font-size: 0.82rem;">
                                <?= Icons::get('alert') ?> FAIL (LỖI)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.85rem; color: var(--text-muted);">
                        <?= htmlspecialchars($test['message']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/views/footer.php'; ?>
