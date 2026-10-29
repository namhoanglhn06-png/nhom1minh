<?php
declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Icons.php';

Auth::startSession();

// Nếu đã đăng nhập rồi thì vào luôn Dashboard
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Khách hàng / Người ngoài đăng ký luôn mặc định là OPERATOR (Không thể tự cấp quyền Admin)
    $result = Auth::register([
        'full_name'        => $_POST['full_name'] ?? '',
        'username'         => $_POST['username'] ?? '',
        'email'            => $_POST['email'] ?? '',
        'password'         => $_POST['password'] ?? '',
        'password_confirm' => $_POST['password_confirm'] ?? '',
        'role'             => 'OPERATOR'
    ]);

    if ($result['success']) {
        header('Location: login.php?success=' . urlencode('Đăng ký tài khoản thành công! Vui lòng đăng nhập với thông tin vừa tạo.'));
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
    <title>Đăng ký tài khoản - BackupTool Enterprise</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 50% 20%, #1e293b 0%, #0f172a 100%);
            padding: 30px 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 480px;
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
            margin-bottom: 24px;
        }

        .auth-logo {
            width: 58px;
            height: 58px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            margin-bottom: 12px;
            box-shadow: 0 8px 16px rgba(16, 185, 129, 0.35);
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-group {
            margin-bottom: 16px;
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

        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px 10px 40px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            color: #f8fafc;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-select {
            padding-left: 14px;
            cursor: pointer;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #10b981;
            background: rgba(15, 23, 42, 0.85);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
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

        .password-strength-bar {
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
            display: flex;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }

        .strength-text {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
            text-align: right;
        }

        .btn-auth-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            margin-top: 8px;
        }

        .btn-auth-submit:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }

        .auth-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 16px;
        }

        .auth-footer a {
            color: #38bdf8;
            font-weight: 500;
            text-decoration: none;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .alert-auth-danger {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <?= Icons::get('user') ?>
            </div>
            <h1 class="auth-title">Đăng Ký Quyền Truy Cập</h1>
            <p class="auth-subtitle">Tạo tài khoản chính thức để thao tác sao lưu & khôi phục</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-auth-danger">
                <?= Icons::get('alert') ?>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" onsubmit="return validateForm()">
            <div class="form-group">
                <label class="form-label" for="full_name">Họ và tên người dùng *</label>
                <div class="input-group">
                    <span class="input-icon"><?= Icons::get('user') ?></span>
                    <input type="text" id="full_name" name="full_name" class="form-input" 
                           placeholder="Nguyễn Văn A" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="username">Tên đăng nhập (Username) *</label>
                <div class="input-group">
                    <span class="input-icon">@</span>
                    <input type="text" id="username" name="username" class="form-input" 
                           placeholder="user123" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
                <div style="font-size: 11px; color: #94a3b8; margin-top: 4px;">
                    🛡️ Tài khoản được tạo với quyền <strong>Khách hàng / Vận hành viên (Operator)</strong>. Chỉ Admin mới có quyền quản trị toàn hệ thống.
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Địa chỉ Email *</label>
                <div class="input-group">
                    <span class="input-icon">✉</span>
                    <input type="email" id="email" name="email" class="form-input" 
                           placeholder="user@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Mật khẩu (Tối thiểu 6 ký tự) *</label>
                <div class="input-group">
                    <span class="input-icon"><?= Icons::get('key') ?></span>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="••••••••" required oninput="checkStrength(this.value)">
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="password-strength-bar">
                    <div id="strengthFill" class="strength-fill"></div>
                </div>
                <div id="strengthText" class="strength-text">Độ mạnh: Chưa nhập</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Xác nhận mật khẩu *</label>
                <div class="input-group">
                    <span class="input-icon"><?= Icons::get('check') ?></span>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input" 
                           placeholder="••••••••" required>
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password_confirm', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-auth-submit">
                Tạo Tài Khoản Chính Thức
            </button>
        </form>

        <div class="auth-footer">
            Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a>
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

function checkStrength(pwd) {
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');
    if (!pwd) {
        fill.style.width = '0%';
        text.innerText = 'Độ mạnh: Chưa nhập';
        return;
    }
    let score = 0;
    if (pwd.length >= 6) score += 25;
    if (pwd.length >= 10) score += 25;
    if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score += 25;
    if (/[0-9]/.test(pwd) || /[^A-Za-z0-9]/.test(pwd)) score += 25;

    fill.style.width = score + '%';
    if (score <= 25) {
        fill.style.background = '#ef4444';
        text.innerText = 'Độ mạnh: Yếu (tối thiểu 6 ký tự)';
        text.style.color = '#ef4444';
    } else if (score <= 50) {
        fill.style.background = '#f59e0b';
        text.innerText = 'Độ mạnh: Trung bình';
        text.style.color = '#f59e0b';
    } else {
        fill.style.background = '#10b981';
        text.innerText = 'Độ mạnh: Rất an toàn';
        text.style.color = '#10b981';
    }
}

function validateForm() {
    const pwd = document.getElementById('password').value;
    const pwdConfirm = document.getElementById('password_confirm').value;
    if (pwd !== pwdConfirm) {
        alert('Mật khẩu xác nhận không trùng khớp! Vui lòng kiểm tra lại.');
        return false;
    }
    return true;
}
</script>

</body>
</html>
