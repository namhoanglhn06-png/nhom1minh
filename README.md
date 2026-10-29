# BTL PTPMMNM - ĐỀ TÀI 9: CÔNG CỤ SAO LƯU TĂNG DẦN VÀ KHÔI PHỤC DỮ LIỆU THEO PHIÊN BẢN

> **Môn học**: Phát triển phần mềm mã nguồn mở  
> **Công nghệ**: PHP 8.2 (Backend) + MariaDB / MySQL (XAMPP Server) + HTML5 / CSS3 / JavaScript (Frontend UI)  
> **Chuẩn đánh giá**: Đáp ứng trọn vẹn 5 chức năng, 2 yêu cầu kỹ thuật và 5 phép thử bắt buộc trong Rubric chấm điểm 10.0.

---

## 1. TÍNH NĂNG NỔI BẬT

1. **Khử trùng lặp nội dung vật lý (Content-Addressable Storage - CAS)**:
   - Mọi tệp đều được băm theo mã `SHA-256`.
   - Các tệp có nội dung giống nhau chỉ lưu duy nhất 1 bản sao vật lý trong kho `storage/objects/`.
2. **Sao lưu tăng dần (Incremental Backup)**:
   - Tự động phát hiện 4 trạng thái: `ADDED` (+), `MODIFIED` (~), `DELETED` (-), `UNCHANGED` (=).
   - Tệp bị xóa ở bản mới vẫn được bảo toàn nguyên vẹn ở các bản cũ.
3. **Giao dịch phiên bản an toàn (Atomic Transaction)**:
   - Các bản sao lưu gián đoạn/lỗi được đánh dấu `FAILED` và không được công bố.
   - Các phiên bản cũ trước đó vẫn được bảo vệ an toàn để khôi phục.
4. **Khôi phục dữ liệu & Kiểm tra mã băm**:
   - Đối chiếu mã băm `SHA-256` trước khi xác nhận khôi phục thành công.
   - Phát hiện tệp bị hư hỏng hoặc chỉnh sửa trái phép (Phép thử 3).
5. **Bảo mật thư mục**:
   - Chặn đứng tấn công thoát thư mục đích (`Path Traversal: ../`).
   - Tự động bỏ qua liên kết tượng trưng (Symbolic links).
   - Ngăn chặn tuyệt đối hành vi ghi đè vào thư mục nguồn.
6. **Bộ kiểm thử tự động 5 Phép thử bắt buộc**:
   - Bấm 1 nút tại `/test_suite.php` hoặc chạy `php tests/run_tests_cli.php` để kiểm chứng 5/5 test case đạt chuẩn.

---

## 2. CẤU TRÚC DỰ ÁN

```text
├── config/
│   └── database.php             # Kết nối PDO MySQL bảo mật
├── core/
│   ├── BackupEngine.php         # Quét thư mục, băm SHA-256, so sánh tăng dần
│   ├── DeduplicationStorage.php # Quản lý kho CAS và khử trùng lặp
│   ├── RestoreEngine.php        # Khôi phục file và kiểm tra tính toàn vẹn
│   ├── SecurityValidator.php    # Chặn Path Traversal, symlink, chặn ghi đè nguồn
│   ├── Icons.php                # Thư viện SVG Icons chuyên nghiệp (no stickers)
│   └── Logger.php               # Ghi nhật ký vào MySQL (run_logs)
├── database/
│   ├── schema.sql               # Script DDL 5 bảng MySQL
│   └── seed_dataset.php         # Sinh tự động 200 tệp test (Unicode, rỗng, trùng)
├── public/
│   ├── css/style.css            # Giao diện Responsive (PC & Mobile)
│   └── js/app.js                # Tương tác giao diện người dùng
├── storage/
│   ├── objects/                 # Kho lưu trữ tệp vật lý theo SHA-256
│   └── test_restored_output/    # Thư mục đích khôi phục mẫu
├── test_data/
│   └── dataset_200_files/       # 200 tệp trong 10 thư mục phục vụ kiểm thử
├── tests/
│   ├── TestRunner.php           # Bộ kiểm thử tự động 5 Phép thử bắt buộc
│   └── run_tests_cli.php        # Chạy kiểm thử từ dòng lệnh
├── views/
│   ├── header.php               # Thanh điều hướng và icon
│   └── footer.php               # Chân trang thông tin
├── index.php                    # Trang 1: Tổng quan & Khai báo tác vụ
├── preview.php                  # Trang 2: Xem trước phạm vi & Thay đổi tăng dần
├── run_backup.php               # Endpoint thực thi sao lưu
├── versions.php                 # Trang 3: Lịch sử phiên bản & Danh mục tệp
├── restore.php                  # Trang 4: Khôi phục dữ liệu & Kiểm tra mã băm
├── test_suite.php               # Trang 5: Giao diện trực quan 5 Phép thử Rubric
├── logs.php                     # Trang 6: Nhật ký thực thi hệ thống
└── CHAY_DU_AN.bat               # File chạy 1-Click mở ngay dự án
```

---

## 3. HƯỚNG DẪN KHỞI CHẠY (1-CLICK)

1. Mở **XAMPP Control Panel**:
   - Bấm **Start** Apache (cổng `8080`) và MySQL (cổng `3306`).
2. Mở trình duyệt truy cập:
   - **Trang chủ ứng dụng**: [http://localhost:8080/btl/](http://localhost:8080/btl/)
   - **Bộ kiểm thử 5 phép thử**: [http://localhost:8080/btl/test_suite.php](http://localhost:8080/btl/test_suite.php)
   - **Quản lý CSDL phpMyAdmin**: [http://localhost:8080/phpmyadmin/](http://localhost:8080/phpmyadmin/)
3. Hoặc chỉ cần **click đúp file `Chay_Cong_Cu_Sao_Luu.bat` trên màn hình Desktop**.
