<?php

declare(strict_types=1);

echo "========================================================\n";
echo "🤖 HGT AI Dashboard - TỰ ĐỘNG THIẾT LẬP CƠ SỞ DỮ LIỆU\n";
echo "========================================================\n\n";

// 1. Tìm file cấu hình
$configFile = __DIR__ . '/app/config.local.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/app/config.php';
}
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/app/config.example.php';
}

$config = require $configFile;
$dbConfig = $config['db'];

$host = $dbConfig['host'] ?? '127.0.0.1';
$port = $dbConfig['port'] ?? 3306;
$dbname = $dbConfig['dbname'] ?? 'drug_disease_ai';
$username = $dbConfig['username'] ?? 'root';
$password = $dbConfig['password'] ?? '';

echo "[INFO] Dang ket noi toi MySQL Server tai $host:$port...\n";

try {
    // Kết nối tới MySQL mà không chọn database trước (để đề phòng database chưa được tạo)
    $dsnWithoutDb = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $pdo = new PDO($dsnWithoutDb, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "[OK] Ket noi MySQL Server thanh cong!\n\n";

    // 2. Đọc file database_schema.sql
    $sqlFile = __DIR__ . '/database/database_schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Khong tim thay file SQL tai: $sqlFile");
    }

    echo "[INFO] Dang doc file schema: database/database_schema.sql...\n";
    $sqlContent = file_get_contents($sqlFile);

    // Thay thế tên database mặc định trong file SQL nếu trong config người dùng đặt tên khác
    if ($dbname !== 'drug_disease_ai') {
        echo "[INFO] Chuyen doi ten database trong SQL tu 'drug_disease_ai' thanh '$dbname'...\n";
        $sqlContent = str_replace('`drug_disease_ai`', "`$dbname`", $sqlContent);
    }

    echo "[INFO] Dang thuc thi cac cau lenh SQL de tao database va bang...\n";
    
    // Thực thi toàn bộ file SQL (chứa nhiều câu lệnh)
    $pdo->exec($sqlContent);
    echo "[OK] Khoi tao Database va Bieu mau (Schema) thanh cong!\n\n";

    // Reconnect với database cụ thể để chuẩn bị import dữ liệu sinh học
    $pdo = null;
    $dsnWithDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
    $pdo = new PDO($dsnWithDb, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // 3. Tự động Import dữ liệu mẫu (Drugs, Diseases, Proteins)
    echo "[INFO] Dang tu dong import du lieu thuc the sinh hoc mau tu C-dataset...\n";
    $importScriptFile = __DIR__ . '/import_sample_data.php';
    if (file_exists($importScriptFile)) {
        // Định nghĩa các hàm import nếu chưa chạy
        require_once $importScriptFile;
        
        $datasetDir = __DIR__ . '/ductri_hgt_update/data/C-dataset';
        if (is_dir($datasetDir)) {
            importDrugs($pdo, $datasetDir . '/DrugInformation.csv');
            importDiseases($pdo, $datasetDir . '/DiseaseFeature.csv');
            importProteins($pdo, $datasetDir . '/ProteinInformation.csv');
            echo "[OK] Import du lieu sinh hoc vao cac bang thanh cong!\n\n";
        } else {
            echo "[WARNING] Thu muc C-dataset khong ton tai tai: $datasetDir. Bo qua buoc import du lieu mau.\n\n";
        }
    } else {
        echo "[WARNING] Khong tim thay file import_sample_data.php. Bo qua buoc import du lieu mau.\n\n";
    }

    echo "========================================================\n";
    echo "🎉 CHUC MUNG! HE THONG DA DUOC THIET LAP DATABASE HOAN HAO!\n";
    echo "Tài khoản đăng nhập mặc định:\n";
    echo " - Admin: admin / password\n";
    echo " - User: user1 / password\n";
    echo "========================================================\n";

} catch (PDOException $e) {
    echo "\n[LOI CHI TIET] Ket noi hoac thuc thi SQL that bai: " . $e->getMessage() . "\n";
    echo "Vui ly kiem tra lai:\n";
    echo " 1. MySQL Server da duoc bat tren XAMPP/Laragon chua?\n";
    echo " 2. Thong tin tai khoan/mat khau tai app/config.local.php co dung khong?\n";
} catch (Exception $e) {
    echo "\n[LOI] " . $e->getMessage() . "\n";
}
