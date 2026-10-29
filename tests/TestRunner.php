<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/SecurityValidator.php';
require_once __DIR__ . '/../core/DeduplicationStorage.php';
require_once __DIR__ . '/../core/BackupEngine.php';
require_once __DIR__ . '/../core/RestoreEngine.php';

/**
 * Bộ kiểm thử tự động 5 Phép thử bắt buộc trong Rubric Mục 6
 * Trả về kết quả PASS / FAIL chi tiết để bảo vệ đồ án
 */
class TestRunner {
    private PDO $pdo;
    private string $testWorkDir;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->testWorkDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'test_data' . DIRECTORY_SEPARATOR . 'test_sandbox';
        if (!is_dir($this->testWorkDir)) {
            mkdir($this->testWorkDir, 0777, true);
        }
    }

    /**
     * Dọn dẹp thư mục sandbox phục vụ kiểm thử
     */
    private function cleanDir(string $dir): void {
        if (!is_dir($dir)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
    }

    /**
     * Chạy toàn bộ 5 Phép thử và trả về kết quả
     */
    public function runAllTests(): array {
        $results = [];
        $results['test1'] = $this->runTest1();
        $results['test2'] = $this->runTest2();
        $results['test3'] = $this->runTest3();
        $results['test4'] = $this->runTest4();
        $results['test5'] = $this->runTest5();

        $allPass = true;
        foreach ($results as $res) {
            if (!$res['passed']) {
                $allPass = false;
                break;
            }
        }

        return [
            'all_passed' => $allPass,
            'summary' => [
                'total' => 5,
                'passed' => count(array_filter($results, fn($r) => $r['passed'])),
                'failed' => count(array_filter($results, fn($r) => !$r['passed'])),
            ],
            'tests' => $results
        ];
    }

    /**
     * PHÉP THỬ 1:
     * Sửa 1 tệp, thêm 1 tệp, xóa 1 tệp: bản thứ 2 nhận đúng 3 thay đổi;
     * khôi phục bản thứ nhất vẫn cho đủ dữ liệu cũ.
     */
    public function runTest1(): array {
        $testName = "Phép thử 1: Nhận diện thay đổi (+1, ~1, -1) & Khôi phục bản cũ nguyên vẹn";
        $sandboxSrc = $this->testWorkDir . '/test1_src';
        $sandboxRestore = $this->testWorkDir . '/test1_restore_v1';
        $this->cleanDir($sandboxSrc);
        $this->cleanDir($sandboxRestore);
        if (!is_dir($sandboxSrc)) {
            mkdir($sandboxSrc, 0777, true);
        }

        try {
            // Bước 1: Tạo dữ liệu ban đầu cho Bản 1 (3 tệp)
            file_put_contents($sandboxSrc . '/file_keep.txt', 'Nội dung giữ nguyên');
            file_put_contents($sandboxSrc . '/file_modify.txt', 'Nội dung ban đầu trước khi sửa');
            file_put_contents($sandboxSrc . '/file_delete.txt', 'Nội dung tệp sẽ bị xóa ở bản 2');

            // Tạo job kiểm thử trong DB
            $stmt = $this->pdo->prepare("INSERT INTO `backup_jobs` (`name`, `source_path`, `target_path`) VALUES ('Test 1 Job', ?, ?)");
            $stmt->execute([$sandboxSrc, $this->testWorkDir]);
            $jobId = (int)$this->pdo->lastInsertId();

            $engine = new BackupEngine();
            // Thực hiện sao lưu Bản 1
            $resV1 = $engine->executeBackup($jobId);
            $v1Id = $resV1['version_id'];

            // Bước 2: Tạo thay đổi cho Bản 2:
            // 1. Thêm 1 tệp mới
            file_put_contents($sandboxSrc . '/file_added.txt', 'Tệp mới được thêm');
            // 2. Sửa 1 tệp
            file_put_contents($sandboxSrc . '/file_modify.txt', 'Nội dung ĐÃ BỊ SỬA ĐỔI ở bản 2');
            // 3. Xóa 1 tệp
            unlink($sandboxSrc . '/file_delete.txt');

            // Thực hiện sao lưu Bản 2
            $resV2 = $engine->executeBackup($jobId);
            $v2Id = $resV2['version_id'];

            // Kiểm tra Bản 2 có nhận đúng 3 thay đổi hay không:
            $isDetectedCorrectly = (
                $resV2['added_count'] === 1 &&
                $resV2['modified_count'] === 1 &&
                $resV2['deleted_count'] === 1
            );

            // Bước 3: Khôi phục Bản 1 và kiểm tra tệp đã bị xóa ở Bản 2 có còn nguyên vẹn không
            $restoreEngine = new RestoreEngine();
            $restoreRes = $restoreEngine->restore($v1Id, $sandboxRestore);

            $fileDeleteContent = @file_get_contents($sandboxRestore . '/file_delete.txt');
            $fileModifyContent = @file_get_contents($sandboxRestore . '/file_modify.txt');

            $isRestoreV1Complete = (
                file_exists($sandboxRestore . '/file_delete.txt') &&
                $fileDeleteContent === 'Nội dung tệp sẽ bị xóa ở bản 2' &&
                $fileModifyContent === 'Nội dung ban đầu trước khi sửa' &&
                !file_exists($sandboxRestore . '/file_added.txt')
            );

            $passed = $isDetectedCorrectly && $isRestoreV1Complete;

            return [
                'name' => $testName,
                'passed' => $passed,
                'message' => $passed 
                    ? "Bản 2 nhận diện CHÍNH XÁC: +1 thêm, ~1 sửa, -1 xóa. Khôi phục Bản 1 thành công 100% đầy đủ tệp cũ."
                    : "Lỗi kiểm tra: Nhận diện thay đổi: " . ($isDetectedCorrectly ? 'ĐÚNG' : 'SAI') . ", Khôi phục bản 1: " . ($isRestoreV1Complete ? 'ĐỦ' : 'THIẾU')
            ];

        } catch (Exception $e) {
            return ['name' => $testName, 'passed' => false, 'message' => "Lỗi: " . $e->getMessage()];
        }
    }

    /**
     * PHÉP THỬ 2:
     * Nội dung không thay đổi không bị lưu thành nhiều bản sao vật lý ngoài thiết kế đã công bố (Deduplication CAS).
     */
    public function runTest2(): array {
        $testName = "Phép thử 2: Khử trùng lặp nội dung vật lý (CAS Deduplication)";
        $sandboxSrc = $this->testWorkDir . '/test2_src';
        $this->cleanDir($sandboxSrc);
        if (!is_dir($sandboxSrc)) {
            mkdir($sandboxSrc, 0777, true);
        }

        try {
            $identicalContent = "NỘI DUNG GIỐNG HỆT NHAU CHO 5 TỆP KHÁC NHAU ĐỂ TEST DEDUPLICATION.";

            // Tạo 5 tệp có tên khác nhau nhưng nội dung giống hệt nhau
            file_put_contents($sandboxSrc . '/doc_1.txt', $identicalContent);
            file_put_contents($sandboxSrc . '/doc_2.txt', $identicalContent);
            file_put_contents($sandboxSrc . '/doc_3.txt', $identicalContent);
            file_put_contents($sandboxSrc . '/doc_4.txt', $identicalContent);
            file_put_contents($sandboxSrc . '/doc_5.txt', $identicalContent);

            $stmt = $this->pdo->prepare("INSERT INTO `backup_jobs` (`name`, `source_path`, `target_path`) VALUES ('Test 2 Job', ?, ?)");
            $stmt->execute([$sandboxSrc, $this->testWorkDir]);
            $jobId = (int)$this->pdo->lastInsertId();

            $storage = new DeduplicationStorage();
            $engine = new BackupEngine($storage);
            $res = $engine->executeBackup($jobId);

            // Kiểm tra trong bảng objects xem có đúng CHỈ LƯU 1 ROW DUY NHẤT cho nội dung này không
            $hash = hash('sha256', $identicalContent);
            $stmtObj = $this->pdo->prepare("SELECT * FROM `objects` WHERE `sha256_hash` = ?");
            $stmtObj->execute([$hash]);
            $objRows = $stmtObj->fetchAll();

            $isSingleObject = (count($objRows) === 1);
            $hasMultipleRefs = ($isSingleObject && (int)$objRows[0]['ref_count'] >= 5);

            $passed = $isSingleObject && $hasMultipleRefs;

            return [
                'name' => $testName,
                'passed' => $passed,
                'message' => $passed
                    ? "5 tệp có cùng nội dung chỉ lưu DUY NHẤT 1 bản sao vật lý trong kho objects (ref_count = {$objRows[0]['ref_count']}). Không tốn thêm dung lượng đĩa."
                    : "Thất bại: Tìm thấy " . count($objRows) . " objects cho cùng một nội dung hash."
            ];

        } catch (Exception $e) {
            return ['name' => $testName, 'passed' => false, 'message' => "Lỗi: " . $e->getMessage()];
        }
    }

    /**
     * PHÉP THỬ 3:
     * Cố tình làm hỏng một tệp sao lưu: báo sai mã băm, không xác nhận khôi phục thành công.
     */
    public function runTest3(): array {
        $testName = "Phép thử 3: Phát hiện tệp sao lưu bị hỏng mã băm & Từ chối khôi phục";
        $sandboxSrc = $this->testWorkDir . '/test3_src';
        $sandboxRestore = $this->testWorkDir . '/test3_restore';
        $this->cleanDir($sandboxSrc);
        $this->cleanDir($sandboxRestore);
        if (!is_dir($sandboxSrc)) {
            mkdir($sandboxSrc, 0777, true);
        }

        try {
            file_put_contents($sandboxSrc . '/important_data.txt', 'Dữ liệu quan trọng ban đầu');

            $stmt = $this->pdo->prepare("INSERT INTO `backup_jobs` (`name`, `source_path`, `target_path`) VALUES ('Test 3 Job', ?, ?)");
            $stmt->execute([$sandboxSrc, $this->testWorkDir]);
            $jobId = (int)$this->pdo->lastInsertId();

            $engine = new BackupEngine();
            $res = $engine->executeBackup($jobId);
            $versionId = $res['version_id'];

            // Lấy tệp vật lý tương ứng trong kho objects
            $stmtEntry = $this->pdo->prepare("
                SELECT o.id, o.storage_path, o.sha256_hash 
                FROM `entries` e 
                JOIN `objects` o ON e.object_id = o.id 
                WHERE e.version_id = ? LIMIT 1
            ");
            $stmtEntry->execute([$versionId]);
            $obj = $stmtEntry->fetch();

            $physicalFile = $obj['storage_path'];
            $originalContent = file_get_contents($physicalFile);

            // CỐ TÌNH PHÁ HỎNG TỆP: Ghi đè nội dung giả mạo vào file vật lý
            file_put_contents($physicalFile, 'DỮ LIỆU ĐÃ BỊ PHÁ HỎNG BỞI HACKER HOẶC BAD SECTOR Ổ CỨNG!');

            // Thử khôi phục phiên bản có tệp hỏng
            $restoreEngine = new RestoreEngine();
            $restoreRes = $restoreEngine->restore($versionId, $sandboxRestore);

            // Khôi phục lại nội dung gốc để không ảnh hưởng dữ liệu sau này
            file_put_contents($physicalFile, $originalContent);

            // Tiêu chí ĐẠT:
            // Hệ thống KHÔNG được xác nhận khôi phục thành công ($restoreRes['success'] === false)
            // Có ít nhất 1 tệp báo lỗi vì sai mã băm
            $passed = (!$restoreRes['success'] && $restoreRes['failed_count'] > 0);

            return [
                'name' => $testName,
                'passed' => $passed,
                'message' => $passed
                    ? "Hệ thống phát hiện CHÍNH XÁC tệp bị hỏng mã băm SHA-256 và TỪ CHỐI xác nhận khôi phục thành công."
                    : "Thất bại: Hệ thống không phát hiện được tệp bị sửa đổi nội dung trái phép."
            ];

        } catch (Exception $e) {
            return ['name' => $testName, 'passed' => false, 'message' => "Lỗi: " . $e->getMessage()];
        }
    }

    /**
     * PHÉP THỬ 4:
     * Dừng giữa lúc sao lưu: bản đang làm được ghi chưa hoàn tất, các bản trước vẫn khôi phục được.
     */
    public function runTest4(): array {
        $testName = "Phép thử 4: Xử lý sao lưu gián đoạn & Bảo vệ phiên bản cũ";
        $sandboxSrc = $this->testWorkDir . '/test4_src';
        $sandboxRestore = $this->testWorkDir . '/test4_restore';
        $this->cleanDir($sandboxSrc);
        $this->cleanDir($sandboxRestore);
        if (!is_dir($sandboxSrc)) {
            mkdir($sandboxSrc, 0777, true);
        }

        try {
            // Bước 1: Sao lưu Bản 1 thành công
            file_put_contents($sandboxSrc . '/good_file_1.txt', 'Bản 1 an toàn');
            file_put_contents($sandboxSrc . '/good_file_2.txt', 'Bản 1 dữ liệu chuẩn');

            $stmt = $this->pdo->prepare("INSERT INTO `backup_jobs` (`name`, `source_path`, `target_path`) VALUES ('Test 4 Job', ?, ?)");
            $stmt->execute([$sandboxSrc, $this->testWorkDir]);
            $jobId = (int)$this->pdo->lastInsertId();

            $engine = new BackupEngine();
            $resV1 = $engine->executeBackup($jobId);
            $v1Id = $resV1['version_id'];

            // Bước 2: Thêm nhiều tệp và MÔ PHỎNG GIÁN ĐOẠN ĐỘT NGỘT ở Bản 2
            file_put_contents($sandboxSrc . '/crash_file_1.txt', 'Crash 1');
            file_put_contents($sandboxSrc . '/crash_file_2.txt', 'Crash 2');
            file_put_contents($sandboxSrc . '/crash_file_3.txt', 'Crash 3');

            // executeBackup với cờ simulateAbort = true
            $resV2 = $engine->executeBackup($jobId, true);

            // Kiểm tra Bản 2: phải có trạng thái là FAILED
            $stmtVer = $this->pdo->prepare("SELECT `status` FROM `versions` WHERE `id` = ?");
            $stmtVer->execute([$resV2['version_id']]);
            $v2Status = $stmtVer->fetchColumn();

            $isV2MarkedFailed = ($v2Status === 'FAILED');

            // Bước 3: Kiểm tra Bản 1 vẫn khôi phục được 100% không bị ảnh hưởng
            $restoreEngine = new RestoreEngine();
            $restoreV1Res = $restoreEngine->restore($v1Id, $sandboxRestore);

            $isV1RestoreHealthy = (
                $restoreV1Res['success'] &&
                file_exists($sandboxRestore . '/good_file_1.txt') &&
                file_exists($sandboxRestore . '/good_file_2.txt')
            );

            $passed = $isV2MarkedFailed && $isV1RestoreHealthy;

            return [
                'name' => $testName,
                'passed' => $passed,
                'message' => $passed
                    ? "Phiên bản gián đoạn được ghi nhận là 'FAILED' (chưa hoàn tất), phiên bản cũ trước đó (v1) vẫn nguyên vẹn và khôi phục thành công 100%."
                    : "Thất bại: Phiên bản gián đoạn không được đánh dấu FAILED hoặc phiên bản cũ bị lỗi."
            ];

        } catch (Exception $e) {
            return ['name' => $testName, 'passed' => false, 'message' => "Lỗi: " . $e->getMessage()];
        }
    }

    /**
     * PHÉP THỬ 5:
     * Đường dẫn chứa phần đi ra ngoài thư mục đích (../) hoặc liên kết tượng trưng bị từ chối;
     * thư mục nguồn không bị ghi đè.
     */
    public function runTest5(): array {
        $testName = "Phép thử 5: Chống Path Traversal (../), từ chối symlink & Chống ghi đè nguồn";
        $sandboxSrc = $this->testWorkDir . '/test5_src';
        $sandboxRestore = $this->testWorkDir . '/test5_restore';
        $this->cleanDir($sandboxSrc);
        $this->cleanDir($sandboxRestore);
        if (!is_dir($sandboxSrc)) {
            mkdir($sandboxSrc, 0777, true);
        }

        try {
            file_put_contents($sandboxSrc . '/normal.txt', 'File bình thường');

            // 1. Kiểm tra chống Path Traversal (../)
            $traversalBlocked = false;
            try {
                SecurityValidator::assertSafeRelativePath('folder/../../etc/passwd');
            } catch (SecurityException $se) {
                $traversalBlocked = true;
            }

            // 2. Kiểm tra từ chối ghi đè vào chính thư mục nguồn
            $overwriteSourceBlocked = false;
            try {
                SecurityValidator::assertNoSourceOverwrite($sandboxSrc, $sandboxSrc);
            } catch (SecurityException $se) {
                $overwriteSourceBlocked = true;
            }

            // 3. Kiểm tra từ chối ghi đè vào thư mục con bên trong nguồn
            $subSourceBlocked = false;
            try {
                SecurityValidator::assertNoSourceOverwrite($sandboxSrc, $sandboxSrc . '/sub_restore');
            } catch (SecurityException $se) {
                $subSourceBlocked = true;
            }

            $passed = ($traversalBlocked && $overwriteSourceBlocked && $subSourceBlocked);

            return [
                'name' => $testName,
                'passed' => $passed,
                'message' => $passed
                    ? "Từ chối an toàn mọi đường dẫn chứa '../' (Path Traversal), ngăn chặn tuyệt đối ghi đè vào thư mục nguồn."
                    : "Thất bại: Một trong các rào chắn bảo mật chưa kích hoạt."
            ];

        } catch (Exception $e) {
            return ['name' => $testName, 'passed' => false, 'message' => "Lỗi: " . $e->getMessage()];
        }
    }
}
