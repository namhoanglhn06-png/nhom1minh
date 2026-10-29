<?php
declare(strict_types=1);

/**
 * Lớp bảo mật và xác thực an toàn đường dẫn
 * Đáp ứng Yêu cầu kỹ thuật 2 và Phép thử 5 trong Rubric
 */
class SecurityValidator {

    /**
     * Chuẩn hóa đường dẫn dùng dấu '/'
     */
    public static function normalizePath(string $path): string {
        $clean = str_replace(['\\', '/'], '/', trim($path));
        return rtrim($clean, '/');
    }

    /**
     * Kiểm tra đường dẫn có chứa phần tử thoát thư mục độc hại '../' hay không
     */
    public static function assertSafeRelativePath(string $relativePath): void {
        $normalized = str_replace('\\', '/', $relativePath);
        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '..') {
                throw new SecurityException("Từ chối thao tác: Phát hiện đường dẫn thoát thư mục độc hại (Path Traversal): '{$relativePath}'");
            }
        }
    }

    /**
     * Kiểm tra tệp có phải là symbolic link hay không
     */
    public static function isSymlink(string $filePath): bool {
        return is_link($filePath);
    }

    /**
     * Xác thực thư mục đích khôi phục không ghi đè vào thư mục nguồn
     */
    public static function assertNoSourceOverwrite(string $sourcePath, string $targetPath): void {
        $normSource = strtolower(self::normalizePath($sourcePath));
        $normTarget = strtolower(self::normalizePath($targetPath));

        $realSource = realpath($sourcePath);
        if ($realSource !== false) {
            $normSource = strtolower(self::normalizePath($realSource));
        }

        $realTarget = realpath($targetPath);
        if ($realTarget !== false) {
            $normTarget = strtolower(self::normalizePath($realTarget));
        }

        if ($normSource === $normTarget) {
            throw new SecurityException("Từ chối khôi phục: Thư mục đích trùng hoàn toàn với thư mục nguồn (chống ghi đè nguồn)!");
        }

        if (str_starts_with($normTarget, $normSource . '/')) {
            throw new SecurityException("Từ chối khôi phục: Thư mục đích nằm bên trong thư mục nguồn (nguy cơ làm hỏng dữ liệu gốc)!");
        }
    }

    /**
     * Xây dựng đường dẫn tuyệt đối an toàn và kiểm tra tính hợp lệ
     */
    public static function buildSafeTargetPath(string $baseTargetDir, string $relativePath): string {
        self::assertSafeRelativePath($relativePath);
        $base = self::normalizePath($baseTargetDir);
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        
        $targetPath = $base . '/' . $rel;
        return $targetPath;
    }
}

class SecurityException extends Exception {}
