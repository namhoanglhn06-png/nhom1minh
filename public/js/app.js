/**
 * Xử lý tương tác giao diện Frontend BTL Đề tài 9
 */

document.addEventListener('DOMContentLoaded', function() {
    // Tự động đóng alert sau 6 giây nếu có
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.4s ease';
            setTimeout(() => alert.remove(), 400);
        }, 6000);
    });
});
