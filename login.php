<?php
declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Icons.php';

Auth::startSession();

// Nếu đã đăng nhập rồi thì chuyển hướng thẳng vào Dashboard
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
$redirect = $_GET['redirect'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = $_POST['identity'] ?? '';
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? 'index.php';

    $result = Auth::login($identity, $password);
    if ($result['success']) {
        // Chuyển hướng tới trang yêu cầu trước đó hoặc index.php
        $target = !empty($redirect) ? urldecode($redirect) : 'index.php';
        // Bảo vệ chống open redirect
        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            $target = 'index.php';
        }
        header("Location: {$target}");
        exit;
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập hệ thống - BackupTool Enterprise</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 50% 20%, #1e293b 0%, #0f172a 100%);
            padding: 24px;
        }

        .auth-card {
            width: 100%;
            max-width: 440px;
            background: rgba(30, 41, 59, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 36px 32px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5), 0 0 30px rgba(59, 130, 246, 0.15);
            animation: fadeIn 0.4s ease-out;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .auth-logo {
            width: 58px;
            height: 58px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            margin-bottom: 14px;
            box-shadow: 0 8px 16px rgba(59, 130, 246, 0.35);
        }

        .auth-logo svg {
            width: 32px;
            height: 32px;
        }

        .auth-title {
            font-size: 22px;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 6px;
        }

        .auth-subtitle {
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #cbd5e1;
            margin-bottom: 6px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            pointer-events: none;
        }

        .input-icon svg {
            width: 18px;
            height: 18px;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            color: #f8fafc;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            background: rgba(15, 23, 42, 0.85);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
        }

        .toggle-password:hover {
            color: #94a3b8;
        }

        .toggle-password svg {
            width: 18px;
            height: 18px;
        }

        .btn-auth-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            margin-top: 6px;
        }

        .btn-auth-submit:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
        }

        .auth-footer {
            margin-top: 22px;
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 18px;
        }

        .auth-footer a {
            color: #38bdf8;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .auth-footer a:hover {
            color: #60a5fa;
            text-decoration: underline;
        }

        .alert-auth {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-auth-danger {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }

        .alert-auth-success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
        }

        .demo-credentials-badge {
            margin-top: 18px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px dashed rgba(59, 130, 246, 0.35);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12px;
            color: #93c5fd;
            text-align: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <?= Icons::get('shield') ?>
            </div>
            <h1 class="auth-title">Đăng Nhập Hệ Thống</h1>
            <p class="auth-subtitle">Công cụ sao lưu tăng dần & Khôi phục dữ liệu theo phiên bản</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-auth alert-auth-danger">
                <?= Icons::get('alert') ?>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-auth alert-auth-success">
                <?= Icons::get('check') ?>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="form-group">
                <label class="form-label" for="identity">Tên đăng nhập hoặc Email</label>
                <div class="input-group">
                    <span class="input-icon"><?= Icons::get('user') ?></span>
                    <input type="text" id="identity" name="identity" class="form-input" 
                           placeholder="Nhập username hoặc email..." required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-icon"><?= Icons::get('key') ?></span>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="••••••••" required>
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password', this)" title="Hiện / Ẩn mật khẩu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-auth-submit">
                Đăng Nhập Hệ Thống
            </button>
        </form>

        <div class="demo-credentials-badge" style="cursor: pointer; transition: transform 0.2s, background 0.2s;" 
             onclick="document.getElementById('identity').value='admin'; document.getElementById('password').value='admin123';" 
             title="Bấm vào đây để tự động điền tài khoản mẫu">
            🔑 <strong>Tài khoản Quản trị mặc định (Bấm để điền nhanh):</strong><br>
            Tên đăng nhập: <code>admin</code> &nbsp;|&nbsp; Mật khẩu: <code>admin123</code>
        </div>

        <div class="auth-footer">
            Chưa có tài khoản? <a href="register.php">Đăng ký tài khoản mới</a>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(fieldId, btn) {
    const input = document.getElementById(fieldId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    } else {
        input.type = 'password';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    }
}
</script>

</body>
</html>
