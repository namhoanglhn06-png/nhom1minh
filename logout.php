<?php
declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';

Auth::logout();
header('Location: login.php?success=' . urlencode('Bạn đã đăng xuất an toàn khỏi hệ thống!'));
exit;
