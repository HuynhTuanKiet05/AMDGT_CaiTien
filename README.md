# AMDGT_CaiTien - Drug Disease AI Predictor

## Current Repository Layout

- `app/`: PHP application services and configuration.
- `public/`: PHP web entry points and assets.
- `python_api/`: FastAPI prediction service.
- `model/`: model implementations used by the improved pipeline.
- `AMDGT/`: original baseline code kept for comparison.
- `scripts/`: setup, training, and metadata utilities.
- `database/database_schema.sql`: MySQL schema.
- `docs/`: guides, reports, and planning notes.
- `samples/test.json`: sample payload/data file.
- `logs/`: local runtime logs.

Runtime Python files such as `train_final.py`, `data_preprocess_improved.py`, `topology_features.py`, and `metric.py` remain at the project root because existing imports and launcher scripts expect them there.

Ứng dụng web PHP + MySQL tích hợp Python API để dự đoán liên kết giữa **thuốc** và **bệnh lý** bằng mô hình HGT cải tiến.

## Tổng quan
Dự án gồm 3 phần chính:

- **Web PHP**: giao diện người dùng, trang admin, lịch sử tra cứu
- **MySQL**: lưu tài khoản, dữ liệu thực thể, liên kết, lịch sử dự đoán
- **Python API**: phục vụ suy luận AI và trả kết quả cho web qua HTTP

## Tính năng chính

- Đăng nhập / đăng xuất
- Tra cứu **Thuốc -> Bệnh**
- Tra cứu **Bệnh -> Thuốc**
- Chọn dataset và top-k
- Hiển thị đồ thị 3D tương tác
- Lưu lịch sử dự đoán
- Trang admin quản lý:
  - thuốc
  - bệnh lý
  - liên kết sinh học
- Giao diện hiện đại, tối ưu cho màn hình desktop

## Cấu trúc thư mục

```text
AMDGT_CaiTien/
├─ app/
├─ public/
│  ├─ assets/
│  ├─ index.php
│  ├─ login.php
│  ├─ history.php
│  ├─ admin.php
│  ├─ admin_drugs.php
│  ├─ admin_diseases.php
│  └─ admin_links.php
├─ python_api/
├─ model/
├─ data/
└─ README.md
```

## Yêu cầu môi trường

### Web
- Windows 10/11
- XAMPP
- PHP 8.x
- MySQL / MariaDB

### Python
- Python 3.10+ khuyến nghị
- `pip`
- Các thư viện theo `requirements.txt` của phần Python API / training

## Hướng dẫn cài đặt môi trường và Khởi chạy

Để đảm bảo hệ thống hoạt động ổn định và chính xác trên máy tính mới, vui lòng tham khảo các hướng dẫn chi tiết sau đây:

1. 💻 **Hướng dẫn thiết lập môi trường từ đầu (Cài MySQL, Conda & Venv, PyTorch/DGL):**
   👉 Xem chi tiết tại: [docs/guides/LOCAL_SETUP.md](file:///d:/LapTrinh/%C4%90%E1%BB%93%20%C3%A1n%20c%C6%A1%20s%E1%BB%9F/AMDGT_CaiTien/docs/guides/LOCAL_SETUP.md)

2. 🚀 **Hướng dẫn khởi chạy hệ thống Web PHP và FastAPI Backend:**
   👉 Xem chi tiết tại: [docs/guides/HUONG_DAN_CHAY_WEB.md](file:///d:/LapTrinh/%C4%90%E1%BB%93%20%C3%A1n%20c%C6%A1%20s%E1%BB%9F/AMDGT_CaiTien/docs/guides/HUONG_DAN_CHAY_WEB.md)

---

### Tóm tắt nhanh cách khởi chạy:
* **Backend AI (FastAPI):** Kích hoạt môi trường ảo của bạn (`amdgt_env` hoặc `.venv`), di chuyển vào thư mục `python_api` và chạy `uvicorn main:app --port 8000` (hoặc chỉ cần click đúp vào file chạy nhanh `restart_api.bat` ở gốc).
* **Frontend Web (PHP):** Khởi động Apache/MySQL trên XAMPP và chạy lệnh `php -S localhost:8080 -t public` tại thư mục gốc của dự án, sau đó truy cập `http://localhost:8080` trên trình duyệt.

## Tài khoản mặc định

Nếu hệ thống đã có seed dữ liệu tài khoản, thông tin mặc định thường là:

- `admin / password`
- `user1 / password`

> Nếu mật khẩu khác, kiểm tra dữ liệu seed hoặc bảng `users` trong database.

## Cách sử dụng

### Trang người dùng
1. Đăng nhập
2. Vào trang Dashboard
3. Nhập tên thuốc / bệnh / ID
4. Chọn dataset
5. Chọn top-k
6. Nhấn **Dự đoán**
7. Xem:
   - bảng kết quả
   - đồ thị 3D
   - lịch sử tra cứu

### Trang quản trị
1. Đăng nhập bằng tài khoản admin
2. Vào `Admin`
3. Quản lý:
   - thuốc
   - bệnh
   - liên kết
4. Theo dõi thống kê hệ thống

## Ghi chú về AI / mô hình
- Web PHP gọi Python API qua HTTP.
- Python API là lớp trung gian để phục vụ dự đoán.
- Nếu muốn đổi sang model train thật, cần đảm bảo API Python load được checkpoint / pipeline suy luận tương ứng.

## Lỗi thường gặp

### 1) Web báo API offline
- Kiểm tra Python API đã chạy chưa
- Kiểm tra cổng `8000`
- Kiểm tra file cấu hình endpoint trong `PredictionService.php`

### 2) Không đăng nhập được
- Kiểm tra bảng `users`
- Kiểm tra seed data
- Kiểm tra kết nối MySQL

### 3) Không load được dữ liệu hoặc đồ thị
- Kiểm tra database đã import đủ chưa
- Kiểm tra dữ liệu đầu vào của dataset
- Kiểm tra API trả JSON đúng định dạng

## Phát triển thêm
Bạn có thể tiếp tục mở rộng dự án theo các hướng:

- tích hợp model inference thật cho Python API
- thêm validation split / model selection
- thêm contrastive learning / multi-view fusion
- thêm trang báo cáo kết quả train

## License
Chưa xác định.

## Tác giả
Dự án phát triển bởi nhóm/ cá nhân thực hiện luận văn / đồ án.
