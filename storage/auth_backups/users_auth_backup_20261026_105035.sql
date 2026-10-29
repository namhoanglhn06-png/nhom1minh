-- ========================================================
-- SAO LƯU TÀI KHOẢN NGƯỜI DÙNG & XÁC THỰC (BTL PTPMMNM)
-- Xuất lúc: 2026-10-26 10:50:35
-- Tổng số tài khoản: 3
-- ========================================================

USE `btl_backup_db`;

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `status`, `created_at`, `last_login`)
VALUES
  (1, 'admin', 'admin@backuptool.local', '$2y$10$cKDjT8/tow3pTmjBYZzPn.ZRkxk0v0ovyUSEmXlCD47EeswJk0TjW', 'Quản Trị Viên Hệ Thống', 'ADMIN', 'ACTIVE', '2026-10-26 16:33:32', '2026-10-26 16:42:58'),
  (2, 'dev_1793007582', 'dev_1793007582@company.local', '$2y$10$TiJVVoga3hqOhQpLdJOx.uNn2EGHBFgYPpR6UvT2oKJfSyvVjM/0a', 'Kỹ sư Phần mềm', 'OPERATOR', 'ACTIVE', '2026-10-26 16:39:42', '2026-10-26 16:39:42'),
  (5, 'dev_1793007614', 'dev_1793007614@company.local', '$2y$10$mDQnbbDY5kYmTaVg/Skjhu8IvJYtcQvAPNWthx/ZYlEYwYod5QCg6', 'Kỹ sư Phần mềm', 'OPERATOR', 'ACTIVE', '2026-10-26 16:40:14', '2026-10-26 16:40:14')
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `full_name` = VALUES(`full_name`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);

