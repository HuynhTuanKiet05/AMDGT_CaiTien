# 💻 Hướng Dẫn Cài Đặt Môi Trường Hệ Thống Cục Bộ (Local Machine Setup)

Tài liệu này hướng dẫn chi tiết các bước thiết lập môi trường để chạy hệ thống **AMDGT_CaiTien** (Mô hình HGT cải tiến dự đoán liên kết Thuốc - Bệnh lý) từ đầu trên hệ điều hành **Windows**. 

Tài liệu này hỗ trợ song song 2 cách thiết lập môi trường Python: **Python Virtual Environment (venv)** (Khuyên dùng khi triển khai/chấm điểm gọn nhẹ) và **Miniconda/Conda** (Khuyên dùng khi cần huấn luyện/tối ưu GPU CUDA).

---

## 🗂️ Tổng Quan Quy Trình Cài Đặt
1. **Cài đặt các công cụ cơ bản** (PHP/XAMPP, Python, Git)
2. **Cấu hình Cơ sở dữ liệu (MySQL)** và import dữ liệu mẫu
3. **Thiết lập Môi trường Python** (chọn 1 trong 2 cách: Venv hoặc Conda)
4. **Cài đặt thư viện Deep Learning** (PyTorch & DGL)
5. **Cài đặt các thư viện bổ trợ** từ `requirements.txt`
6. **Cấu hình Web PHP** kết nối Database
7. **Khởi chạy hệ thống** và kiểm tra kết quả

---

## 🛠️ Bước 1: Cài đặt các công cụ cơ bản

### 1. XAMPP (chạy Web PHP & MySQL Database)
* Tải bản **XAMPP (PHP 8.x trở lên)** tại [Apache Friends](https://www.apachefriends.org/).
* Cài đặt XAMPP vào ổ đĩa mặc định `C:\xampp`.

### 2. Python
* Tùy thuộc vào việc bạn chọn dùng **Venv** hay **Conda**:
  * **Nếu dùng Venv:** Tải và cài đặt **Python 3.10** (hoặc 3.9) tại [Python.org](https://www.python.org/downloads/). *Lưu ý tích chọn "Add Python to PATH" khi cài đặt.*
  * **Nếu dùng Conda:** Tải và cài đặt **Miniconda** tại [Conda Docs](https://docs.anaconda.com/miniconda/).

---

## 🗄️ Bước 2: Thiết lập Cơ sở dữ liệu (MySQL)

Hệ thống cần database MySQL để lưu thông tin tài khoản, danh sách thực thể sinh học (thuốc, bệnh) và lịch sử dự đoán.

1. Khởi động **XAMPP Control Panel**, nhấn **Start** cho cả **Apache** và **MySQL**.
2. Mở trình duyệt, truy cập trang quản lý cơ sở dữ liệu: `http://localhost/phpmyadmin`
3. Nhấp vào **New** (Mới) ở menu bên trái để tạo Database:
   * **Tên Database:** `amdgt_db` (hoặc tên tùy ý của bạn).
   * **Bảng mã (Collation):** Chọn `utf8mb4_general_ci`.
   * Nhấn **Create** (Tạo).
4. Chọn database `amdgt_db` vừa tạo, nhấp vào thẻ **Import** (Nhập) ở menu phía trên.
5. Nhấn **Choose File** (Chọn tệp) và trỏ tới file SQL cấu trúc trong dự án:
   `d:\LapTrinh\Đồ án cơ sở\AMDGT_CaiTien\database\database_schema.sql`
6. Cuộn xuống dưới cùng và nhấn **Import** (Nhập). Hệ thống sẽ tự động tạo các bảng dữ liệu cần thiết.

---

## 🐍 Bước 3: Cài đặt Môi trường Python (Chọn 1 trong 2 cách)

Chọn cách cài đặt phù hợp với yêu cầu của thầy giáo hoặc cấu hình phần cứng của bạn.

### 👉 CÁCH A: Sử dụng Python Virtual Environment (venv) - Khuyên dùng cho chấm điểm/gọn nhẹ
*Cách này sử dụng công cụ mặc định đi kèm của Python, tạo ra thư mục môi trường ảo `.venv` ngay trong thư mục dự án.*

1. Mở **PowerShell** hoặc **Command Prompt** (CMD).
2. Di chuyển đến thư mục gốc của dự án:
   ```powershell
   cd "d:\LapTrinh\Đồ án cơ sở\AMDGT_CaiTien"
   ```
3. Tạo môi trường ảo có tên `.venv`:
   ```powershell
   python -m venv .venv
   ```
4. Kích hoạt môi trường ảo:
   * **Trên PowerShell:**
     ```powershell
     .\.venv\Scripts\Activate.ps1
     ```
     *(Nếu bị lỗi quyền thực thi script, chạy lệnh `Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope Process` rồi thử lại).*
   * **Trên CMD:**
     ```cmd
     .\.venv\Scripts\activate.bat
     ```
5. Khi kích hoạt thành công, bạn sẽ thấy ký tự `(.venv)` xuất hiện ở đầu dòng lệnh.

---

### 👉 CÁCH B: Sử dụng Miniconda/Conda - Khuyên dùng cho máy GPU/Huấn luyện sâu
*Cách này quản lý môi trường ảo thông qua Conda, rất tối ưu khi cần cấu hình CUDA để chạy mô hình AI bằng GPU.*

1. Mở ứng dụng **Anaconda Prompt** hoặc **Miniconda Prompt** đã cài ở Bước 1.
2. Tạo môi trường ảo mới tên là `amdgt_env` chạy Python 3.10:
   ```bash
   conda create -n amdgt_env python=3.10 -y
   ```
3. Kích hoạt môi trường vừa tạo:
   ```bash
   conda activate amdgt_env
   ```
4. Khi kích hoạt thành công, bạn sẽ thấy ký tự `(amdgt_env)` xuất hiện ở đầu dòng lệnh.

---

## 🚀 Bước 4: Cài đặt các thư viện AI (PyTorch & DGL)

> [!IMPORTANT]
> PyTorch và DGL (Deep Graph Library) là hai thư viện nền tảng chứa kiến trúc mạng HGT (Heterogeneous Graph Transformer). Bạn cần cài đặt chúng đúng phiên bản tương thích với phần cứng (CPU hoặc GPU CUDA).

**Đảm bảo bạn đã kích hoạt môi trường ảo (`.venv` hoặc `amdgt_env`) trước khi chạy các lệnh sau:**

### Lựa chọn 1: Cài đặt cho máy CHỈ CHẠY CPU (Thông thường/Chấm điểm nhẹ)
Chạy các lệnh sau để cài đặt phiên bản PyTorch và DGL chạy trên CPU:
```bash
# 1. Cài đặt PyTorch CPU
pip install torch==2.4.1 --index-url https://download.pytorch.org/whl/cpu

# 2. Cài đặt DGL CPU
pip install dgl==2.4.0 -f https://data.dgl.ai/wheels/torch-2.4/cpu/repo.html
```

### Lựa chọn 2: Cài đặt cho máy có GPU NVIDIA (Có hỗ trợ tăng tốc CUDA)
*Yêu cầu máy bạn có card đồ họa NVIDIA và đã cài CUDA Toolkit (ví dụ bản CUDA 12.1 hoặc tương thích).*
Chạy các lệnh sau để tận dụng tối đa sức mạnh GPU:
```bash
# 1. Cài đặt PyTorch với CUDA 12.1
pip install torch==2.4.1 --index-url https://download.pytorch.org/whl/cu121

# 2. Cài đặt DGL với CUDA 12.1 tương ứng
pip install dgl==2.4.0+cu121 -f https://data.dgl.ai/wheels/torch-2.4/cu121/repo.html
```

---

## 📦 Bước 5: Cài đặt các thư viện bổ trợ từ `requirements.txt`

Sau khi đã có PyTorch và DGL, chúng ta sẽ cài đặt toàn bộ các thư viện khoa học và FastAPI API còn lại bằng file `requirements.txt` ở gốc thư mục dự án:

```bash
# Đảm bảo terminal đang ở thư mục gốc của dự án: d:\LapTrinh\Đồ án cơ sở\AMDGT_CaiTien
pip install -r requirements.txt
```

*(Lệnh này sẽ tự động cài đặt `numpy`, `pandas`, `scipy`, `scikit-learn`, `networkx`, `fastapi`, `uvicorn`, `pydantic`... với phiên bản đồng nhất đã được kiểm thử).*

---

## 🔑 Bước 6: Cấu hình Web PHP kết nối Cơ sở dữ liệu

1. Di chuyển vào thư mục `app/` của dự án.
2. Tạo file cấu hình cục bộ `config.local.php` bằng cách copy từ file mẫu:
   * Bạn có thể copy thủ công hoặc chạy lệnh sau ở terminal:
     ```powershell
     copy app\config.example.php app\config.local.php
     ```
3. Mở file `app/config.local.php` bằng công cụ soạn thảo (VS Code, Notepad, v.v.) và điền thông tin kết nối MySQL của bạn (nếu dùng XAMPP mặc định thì thông số như dưới đây):
   ```php
   <?php
   return [
       'db' => [
           'host' => '127.0.0.1',
           'database' => 'amdgt_db',   // Tên database bạn đã tạo ở Bước 2
           'username' => 'root',        // User mặc định của XAMPP
           'password' => '',            // Password mặc định của XAMPP để trống
           'port' => 3306,
       ],
       // ... các cấu hình khác giữ nguyên
   ];
   ```

---

## 🚦 Bước 7: Khởi chạy và Trải nghiệm Hệ thống

Bây giờ toàn bộ hệ thống đã sẵn sàng hoạt động. Bạn thực hiện khởi chạy theo đúng thứ tự:

### 1. Chạy Backend Python API trước:
Để chạy nhanh, bạn chỉ cần click đúp chuột vào file:
👉 **`restart_api.bat`** (nằm ở thư mục gốc dự án).

> [!TIP]
> File script `restart_api.bat` đã được lập trình thông minh để **tự động kiểm tra**:
> - Nếu máy có thư mục ảo `.venv`, nó sẽ ưu tiên sử dụng `.venv` để khởi chạy.
> - Nếu không có `.venv`, nó sẽ tự động tìm và kích hoạt môi trường Conda `amdgt_env` của bạn để chạy.
> - Đồng thời nó sẽ dọn sạch cổng mạng `8000` đang bị treo trước đó nếu có.

API khởi chạy thành công sẽ hiển thị dòng chữ:
`[OK] API dang chay tai http://127.0.0.1:8000`

### 2. Chạy Frontend Web PHP:
1. Mở một terminal mới.
2. Chạy PHP Development Server cục bộ bằng lệnh:
   ```powershell
   # Đứng tại thư mục gốc d:\LapTrinh\Đồ án cơ sở\AMDGT_CaiTien
   php -S localhost:8080 -t public
   ```
3. Mở trình duyệt Web (Chrome/Edge) và truy cập địa chỉ:
   `http://localhost:8080`
4. Đăng nhập bằng tài khoản Quản trị viên mặc định:
   * **Tài khoản:** `admin@hgt.com`
   * **Mật khẩu:** `password`

*Chúc bạn cài đặt thành công và có những trải nghiệm tuyệt vời với hệ thống dự đoán HGT AI Dashboard!*
