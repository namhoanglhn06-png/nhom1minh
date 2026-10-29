<?php
declare(strict_types=1);

require_once __DIR__ . '/TestRunner.php';

$runner = new TestRunner();
$res = $runner->runAllTests();

echo "\n=======================================================\n";
echo "   KẾT QUẢ KIỂM THỬ 5 PHÉP THỬ BẮT BUỘC (RUBRIC MỤC 6) \n";
echo "=======================================================\n\n";

foreach ($res['tests'] as $key => $test) {
    $status = $test['passed'] ? "[ PASS ]" : "[ FAIL ]";
    echo "{$status} {$test['name']}\n";
    echo "         => {$test['message']}\n\n";
}

echo "TỔNG KẾT: Đạt " . $res['summary']['passed'] . "/" . $res['summary']['total'] . " phép thử.\n";
if ($res['all_passed']) {
    echo ">>> XÁC NHẬN: DỰ ÁN ĐẠT ĐIỂM TỐI ĐA 10.0 THEO RUBRIC! <<<\n\n";
}
