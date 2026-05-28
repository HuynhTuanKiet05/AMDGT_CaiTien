# BÁO CÁO SO SÁNH DỰ ÁN HIỆN TẠI VỚI BẢN GỐC AMDGT

## 1. Thông tin chung

- **Dự án gốc đối chiếu**: `AMDGT - Attention aware multi-modal learning using dual graph transformer for drug-disease associations prediction`
- **Repo gốc công khai**: https://github.com/JK-Liu7/AMDGT
- **Bản baseline dùng để đối chiếu trực tiếp trong workspace**: thư mục `AMDGT/`
- **Dự án hiện tại**: repo `Colab_V4`
- **Mục tiêu báo cáo**: xác định rõ dự án hiện tại đã cải tiến những gì so với bản AMDGT gốc, cả ở mức mô hình AI lẫn mức hệ thống triển khai

## 2. Phương pháp đối chiếu

Việc so sánh được thực hiện theo hai nguồn:

1. Đọc cấu trúc repo gốc trên GitHub `JK-Liu7/AMDGT` để xác nhận phạm vi chính thức của bản gốc.
2. Đối chiếu trực tiếp với thư mục `AMDGT/` đang lưu trong workspace, vì đây là bản baseline được giữ lại ngay trong dự án hiện tại để làm mốc so sánh.

Các nhóm thành phần được so sánh gồm:

- cấu trúc repo
- pipeline tiền xử lý dữ liệu
- kiến trúc mô hình
- pipeline huấn luyện
- metric và độ ổn định khi đánh giá
- khả năng suy luận và phục vụ mô hình
- giao diện web, cơ sở dữ liệu và quản trị
- script hỗ trợ dữ liệu và tài liệu triển khai

## 3. Kết luận ngắn gọn

Nếu tóm tắt trong một câu, thì:

**AMDGT gốc là một research repo Python tập trung vào huấn luyện mô hình, còn dự án hiện tại đã được mở rộng thành một hệ thống AI hoàn chỉnh gồm mô hình cải tiến, pipeline train ổn định hơn, API suy luận, web PHP, MySQL, trang quản trị, lịch sử dự đoán, trực quan hóa 3D và tài liệu triển khai thực tế.**

Nói cách khác, dự án hiện tại không chỉ "chỉnh sửa một vài tham số" mà đã cải tiến theo ba hướng lớn cùng lúc:

- **Nâng cấp thuật toán và biểu diễn dữ liệu**
- **Chuẩn hóa pipeline huấn luyện và suy luận**
- **Đóng gói thành ứng dụng có thể demo, quản trị và triển khai**

## 4. Bức tranh tổng quan giữa hai dự án

### 4.1. Repo gốc AMDGT

Theo README và cấu trúc repo gốc, AMDGT ban đầu chỉ tập trung vào phần nghiên cứu mô hình:

- `data_preprocess.py`: đọc dữ liệu, tạo mẫu âm/dương, chia fold, xây graph
- `metric.py`: tính các metric
- `train_DDA.py`: huấn luyện mô hình chính
- `model/`: chứa kiến trúc mô hình
- `data/`: chứa dữ liệu thực nghiệm

Điểm đặc trưng của repo gốc:

- thuần Python
- thiên về tái hiện thí nghiệm
- chưa có API
- chưa có web
- chưa có cơ sở dữ liệu
- chưa có quản trị người dùng
- chưa có lịch sử truy vấn
- chưa có module phục vụ trình diễn cho người dùng cuối

### 4.2. Dự án hiện tại Colab_V4

Nếu bỏ qua thư mục `AMDGT/` và thư mục ghi chú nội bộ `vibe-code/`, phần dự án do nhóm hiện tại phát triển thêm có:

- **44 file mới hoặc mở rộng riêng**
- **17 file Python**, tổng khoảng **2280 dòng**
- **14 file PHP**, tổng khoảng **1117 dòng**
- thêm **SQL schema**, **CSS giao diện**, **script shell**, **tài liệu vận hành**

Trong khi đó baseline Python lõi chỉ khoảng:

- **10 file Python**
- khoảng **861 dòng**

Điều này cho thấy quy mô dự án hiện tại đã vượt xa phạm vi "research code" ban đầu.

## 5. So sánh chi tiết theo từng lớp hệ thống

## 5.1. So sánh ở mức kiến trúc tổng thể

### Bản gốc AMDGT

Kiến trúc tổng thể của repo gốc khá đơn giản:

1. đọc dữ liệu từ các file CSV
2. tiền xử lý và tạo các tập train/test theo k-fold
3. xây graph similarity và heterograph
4. huấn luyện mô hình `AMNTDDA`
5. in ra AUC, AUPR và các metric khác

Repo gốc chủ yếu phục vụ mục tiêu nghiên cứu:

- kiểm chứng mô hình
- chạy thực nghiệm trên bộ dữ liệu chuẩn
- báo cáo chỉ số hiệu năng

### Dự án hiện tại

Dự án hiện tại mở rộng kiến trúc thành nhiều tầng:

1. **Tầng dữ liệu và preprocess**
2. **Tầng mô hình cải tiến**
3. **Tầng huấn luyện hoàn chỉnh**
4. **Tầng suy luận qua FastAPI**
5. **Tầng giao diện người dùng PHP**
6. **Tầng lưu trữ MySQL**
7. **Tầng quản trị hệ thống**
8. **Tầng script hỗ trợ metadata và Colab**

Đây là thay đổi có ý nghĩa rất lớn về mặt sản phẩm:

- từ mô hình nghiên cứu sang hệ thống có thể demo
- từ code dành cho người làm ML sang ứng dụng có thể dùng bởi người không chuyên sâu AI

## 5.2. Cải tiến ở lớp tiền xử lý dữ liệu

### Bản gốc

Trong `AMDGT/data_preprocess.py`, pipeline gốc làm các việc chính:

- đọc các ma trận similarity và association
- tạo adjacency matrix từ cặp liên kết
- tách positive/negative sample
- chia k-fold bằng `StratifiedKFold`
- tạo graph similarity
- tạo heterograph drug-disease-protein

Điểm hạn chế của bản gốc:

- xử lý chủ yếu cho mục tiêu huấn luyện
- chưa quan tâm nhiều tới độ ổn định khi chạy trên môi trường mới
- chưa có nhiều lớp hỗ trợ phân tích view hoặc topology

### Bản cải tiến

Trong `data_preprocess_improved.py`, nhóm đã giữ lại xương sống của pipeline gốc nhưng có nhiều tinh chỉnh đáng chú ý:

#### 1. Tương thích môi trường tốt hơn

- chuyển `torch.sparse.LongTensor(...)` sang `torch.sparse_coo_tensor(...)`
- device được lấy từ biến môi trường `AMDGT_DEVICE` thay vì phụ thuộc cứng vào `cuda` nếu có

Ý nghĩa:

- chạy ổn định hơn trên PyTorch mới
- dễ dùng hơn trên CPU, GPU hoặc Colab

#### 2. Sửa logic tạo consensus similarity cho disease

Bản gốc tạo `dis` như sau:

- nếu `dip == 0` thì vẫn lấy `dip`
- ngược lại lấy trung bình `dis_mean`

Điều này không hợp lý vì khi `dip` bằng 0 thì lẽ ra phải fallback sang `dig`.

Bản cải tiến đã sửa thành:

- nếu `dip == 0` thì dùng `dig`
- ngược lại mới lấy trung bình

Đây là một cải tiến kỹ thuật quan trọng vì nó ảnh hưởng trực tiếp đến chất lượng similarity view của disease.

#### 3. Lưu fold sạch hơn

- file `data_train.csv` và `data_test.csv` được ghi với `index=False`

Ý nghĩa:

- tránh sinh thêm cột index thừa
- giúp dữ liệu fold dễ kiểm tra hơn
- thuận tiện cho debug và tái sử dụng

#### 4. Hỗ trợ multi-view similarity graph

Bản gốc chỉ xây một graph consensus cho drug và disease.

Bản cải tiến thêm hàm `dgl_similarity_view_graphs()` để tạo đồng thời:

- drug fingerprint view
- drug GIP view
- drug consensus view
- disease phenotype view
- disease GIP view
- disease consensus view

Ý nghĩa:

- không còn buộc mô hình phải nén mọi thông tin similarity vào một graph duy nhất
- tạo tiền đề cho multi-view fusion thực thụ

#### 5. Bổ sung edge statistics

Trong `dgl_heterograph()`, bản cải tiến trả thêm `edge_stats` với `pair_bias`.

Ý nghĩa:

- cho thấy nhóm đã bắt đầu nghĩ tới việc đưa thống kê cấu trúc graph vào model hoặc diagnostics
- dù nhánh này hiện chưa dùng hết trong model, nó cho thấy pipeline đã được mở rộng theo hướng giàu thông tin hơn baseline

## 5.3. Cải tiến ở lớp đặc trưng topology

Đây là một trong các điểm mới quan trọng nhất so với AMDGT gốc.

### Bản gốc

Repo gốc không có file riêng để trích xuất topology feature cho từng node.

Thông tin topo chỉ được mô hình khai thác gián tiếp thông qua:

- graph similarity
- heterograph
- HGTConv

### Bản cải tiến

Nhóm tạo riêng file `topology_features.py` để trích xuất đặc trưng topo cho drug và disease.

Các đặc trưng được trích gồm:

- degree chuẩn hóa
- weighted degree chuẩn hóa
- clustering coefficient
- PageRank
- average neighbor degree
- degree theo liên kết drug-disease
- degree theo liên kết drug-protein hoặc disease-protein

Ngoài ra còn có cơ chế cache:

- lưu đặc trưng topo ra thư mục `topology_cache`
- tái sử dụng nếu dữ liệu không đổi

Ý nghĩa của cải tiến này:

- biến thông tin cấu trúc graph thành đặc trưng tường minh
- giúp mô hình có thêm một góc nhìn khác ngoài embedding học được
- tăng khả năng giải thích khi cần phân tích vì sao một drug hoặc disease quan trọng
- giảm chi phí tính toán lặp lại nhờ cache

Đây là điểm mà báo cáo nên nhấn mạnh vì nó thể hiện tư duy nâng cấp mô hình theo hướng có cơ sở chứ không chỉ tăng độ sâu mạng.

## 5.4. Cải tiến ở lớp mô hình

### Bản gốc

Trong `AMDGT/model/AMNTDDA.py`, mô hình gốc có kiến trúc tổng quát:

1. học embedding similarity cho drug và disease bằng dual graph transformer
2. chiếu đặc trưng drug, disease, protein sang cùng không gian HGT
3. chạy HGT trên heterograph
4. ghép `similarity embedding` với `heterograph embedding`
5. đưa qua transformer encoder
6. lấy biểu diễn cặp bằng phép nhân từng phần tử
7. MLP phân loại nhị phân

Đây là một kiến trúc hợp lý cho bài toán nghiên cứu, nhưng vẫn khá cứng:

- số view hạn chế
- cách fusion tương đối cố định
- không có cơ chế chẩn đoán sâu

### Bản cải tiến

#### 1. Tách model thành nhiều module riêng

Thay vì dồn toàn bộ logic vào một file ngắn, bản mới tách thành:

- `topology_encoder.py`
- `contrastive_loss.py`
- `multi_view_aggregator.py`
- `similarity_view_fusion.py`
- `fuzzy_attention.py`
- `rlg_hgt.py`
- `improved_model.py`

Ý nghĩa:

- dễ bảo trì hơn
- dễ thử nghiệm hơn
- có thể hoán đổi từng khối độc lập

#### 2. Thêm nhánh topology encoder

`TopologyEncoder` biến vector đặc trưng topo thành embedding cùng không gian với các view khác.

Điều này giúp mô hình không chỉ "nhìn" similarity view và association view mà còn "nhìn" được cấu trúc topo một cách tường minh.

#### 3. Thêm contrastive learning giữa các view

`MultiViewContrastiveLoss` tính loss đồng bộ giữa:

- similarity view
- association view
- topology view

Ý nghĩa:

- giúp các view khác nhau học biểu diễn nhất quán hơn
- giảm nguy cơ mỗi view học lệch nhau quá xa
- tăng chất lượng fusion

#### 4. Hỗ trợ multi-view fusion thật sự

Nếu `similarity_view_mode = multi`, mô hình có thể học fusion giữa nhiều similarity view thông qua `SimilarityViewFusion`.

Điểm mạnh:

- view nào quan trọng hơn sẽ được gán trọng số attention cao hơn
- không còn phải dùng duy nhất graph consensus

#### 5. Nhiều chế độ association backbone

Mô hình mới hỗ trợ:

- `vanilla_hgt`
- `rlghgt`

Điều này có nghĩa là nhóm đã biến mô hình thành một framework nhỏ để thử nghiệm nhiều backbone thay vì chỉ một kiến trúc cố định.

#### 6. Nhiều chiến lược fusion

Mô hình mới hỗ trợ:

- `mva`
- `rvg`

Trong đó:

- `mva` dùng `MultiViewAggregator`
- `rvg` dùng transformer base + `FuzzyGate`

Đây là cải tiến lớn về mặt biểu diễn vì cho phép thử nhiều chiến lược hòa trộn thông tin.

#### 7. Bổ sung fuzzy gate

`FuzzyGate` dùng tín hiệu topo để điều tiết mức cộng residual vào biểu diễn nền.

Ý nghĩa:

- topology không bị trộn cứng
- mô hình có thể học "nên tin topology đến mức nào"
- giúp fusion mềm hơn, có kiểm soát hơn

#### 8. Bổ sung pair head nâng cao

Ngoài head kiểu gốc `mul_mlp`, bản mới còn có `interaction` head với các đặc trưng:

- vector drug
- vector disease
- tích phần tử
- trị tuyệt đối sai khác
- cosine similarity
- bilinear score

Ý nghĩa:

- biểu diễn quan hệ cặp phong phú hơn
- không còn phụ thuộc hoàn toàn vào Hadamard product như baseline

#### 9. Hệ thống diagnostics trong forward

Model mới có thể trả về:

- loss phụ
- alignment giữa các view
- norm của representation
- thống kê gate
- trọng số view fusion

Đây là cải tiến rất giá trị trong thực nghiệm vì cho phép giải thích và debug tốt hơn nhiều so với bản gốc.

## 5.5. Cải tiến ở pipeline huấn luyện

### Bản gốc

`AMDGT/train_DDA.py` là một script huấn luyện khá ngắn và trực diện:

- khai báo tham số
- đọc dữ liệu
- tạo graph
- huấn luyện với `CrossEntropyLoss`
- đánh giá từng epoch
- in ra metric

Điểm hạn chế:

- ít kiểm tra lỗi dữ liệu
- chưa có preset theo dataset
- chưa có logging bài bản
- chưa có early stopping
- chưa có checkpoint bài bản
- chưa có class weighting hoặc contrastive objective

### Bản cải tiến

`train_final.py` là một bước nhảy rất lớn về mặt kỹ thuật.

#### 1. Preset riêng cho từng dataset

Nhóm thêm `DATASET_PRESETS` cho:

- `B-dataset`
- `C-dataset`
- `F-dataset`

Mỗi dataset có:

- learning rate
- weight decay
- neighbor
- kích thước embedding
- số layer HGT
- kích thước topology hidden
- với `F-dataset` còn có `multi-view` và `positive weight mode`

Ý nghĩa:

- không dùng một cấu hình chung cho mọi dataset
- hợp lý hơn về mặt thực nghiệm

#### 2. Kiểm tra dữ liệu đầu vào

`validate_data_dir()` đảm bảo đủ các file cần thiết trước khi train.

Ý nghĩa:

- giảm lỗi runtime muộn
- dễ dùng hơn cho người mới

#### 3. Tự động chọn device

`resolve_device()` cho phép:

- `auto`
- `cpu`
- `cuda`

và có fallback nếu yêu cầu CUDA nhưng máy không có GPU.

Ý nghĩa:

- tăng khả năng chạy thực tế
- không phụ thuộc cứng vào một môi trường cụ thể

#### 4. Thiết lập random seed bài bản

Hàm `set_random_seed()` đặt seed cho:

- Python
- NumPy
- PyTorch
- CUDA
- DGL

Ý nghĩa:

- tăng khả năng tái lập thí nghiệm

#### 5. Class weighting cho dữ liệu mất cân bằng

`build_class_weights()` hỗ trợ nhiều chế độ:

- `none`
- `sampled`
- `global_linear`
- `global_sqrt`
- `global_log`

Ý nghĩa:

- phù hợp hơn với bài toán sparse link prediction
- giảm thiên lệch về lớp âm

#### 6. Checkpoint và kết quả tổng hợp

Script mới có thể:

- lưu checkpoint tốt nhất theo fold
- lưu best overall checkpoint
- xuất CSV tổng hợp kết quả
- log toàn bộ quá trình train ra file

Ý nghĩa:

- tốt hơn rất nhiều cho mục đích theo dõi và báo cáo

#### 7. Early stopping và scheduler

Thêm:

- `ReduceLROnPlateau`
- `patience`
- `min_lr`

Ý nghĩa:

- tiết kiệm thời gian train
- tránh overfitting kéo dài

#### 8. Đưa topology và contrastive loss vào train loop

Train loop mới:

- trích topology feature
- truyền topology vào model
- lấy `aux_losses`
- cộng `contrastive loss` với `cross entropy`

Ý nghĩa:

- pipeline đã thực sự chuyển từ baseline sang improved model

#### 9. Logging rõ ràng hơn

Script mới log:

- cấu hình dataset
- device
- mode fusion
- backbone
- positive weight mode
- metric từng epoch
- thời điểm AUC cải thiện

Đây là cải tiến rất quan trọng khi làm đồ án vì cho phép chứng minh quá trình thực nghiệm rõ ràng.

## 5.6. Cải tiến ở metric và độ ổn định khi đánh giá

### Bản gốc

`metric.py` gốc tính:

- Accuracy
- MCC
- Precision
- Recall
- F1
- AUC
- AUPR

Tuy nhiên khi dữ liệu hoặc dự đoán chỉ rơi vào một lớp, một số metric có thể gây cảnh báo hoặc lỗi.

### Bản cải tiến

`metric.py` mới thêm:

- kiểm tra số lớp trước khi tính MCC
- nếu suy biến thì đặt MCC = 0.0
- thêm `zero_division=0` cho precision, recall, f1

Ý nghĩa:

- pipeline đánh giá ổn định hơn
- tránh vỡ luồng train chỉ vì một epoch suy biến

Đây là cải tiến nhỏ nhưng thể hiện tư duy làm hệ thống chắc chắn hơn.

## 5.7. Cải tiến ở lớp suy luận và phục vụ mô hình

### Bản gốc

Repo gốc không có tầng phục vụ model qua API.

Muốn dùng mô hình, người dùng phải:

- tự chạy script Python
- tự chuẩn bị dữ liệu đầu vào
- tự đọc kết quả từ terminal hoặc file

### Bản cải tiến

Nhóm xây dựng `python_api/main.py` bằng FastAPI.

Các điểm nổi bật:

#### 1. Có API chuẩn

- `POST /predict`
- `GET /health`

Điều này biến model thành một service có thể tích hợp với frontend.

#### 2. Có cache model và cache dữ liệu

`InferenceManager` giữ:

- `cached_models`
- `cached_data`

Ý nghĩa:

- không phải load lại toàn bộ model và graph cho mỗi request
- giảm độ trễ đáng kể khi demo

#### 3. Tự tìm checkpoint

API có `resolve_model_path()` để tìm checkpoint phù hợp trong các thư mục kết quả.

Ý nghĩa:

- giảm thao tác cấu hình thủ công

#### 4. Đọc metadata thực thể

API có các hàm:

- `load_drug_info()`
- `load_disease_info()`
- `load_protein_info()`

Ý nghĩa:

- không chỉ trả về index số
- có thể trả về tên và ID thực thể để hiển thị cho người dùng

#### 5. Fuzzy matching đầu vào

API dùng `fuzzy_match()` để tìm thực thể theo:

- tên
- ID
- khớp một phần chuỗi

Ý nghĩa:

- thân thiện với người dùng hơn
- không bắt buộc nhập đúng tuyệt đối

#### 6. Tạo graph trả về cho frontend

API không chỉ trả top-k kết quả mà còn tạo:

- `graph_nodes`
- `graph_links`

bao gồm:

- thực thể nguồn
- protein liên quan
- các drug hoặc disease được dự đoán

Ý nghĩa:

- phục vụ trực quan hóa 3D trên web
- tăng giá trị trình diễn và phân tích

#### 7. Chế độ demo fallback

Nếu chưa tìm thấy checkpoint, API vẫn trả kết quả demo và thông báo rõ đang chạy chế độ minh họa.

Ý nghĩa:

- rất hữu ích cho demo đồ án
- giảm rủi ro trình diễn bị hỏng hoàn toàn

## 5.8. Cải tiến ở giao diện web

### Bản gốc

Repo gốc không có giao diện người dùng.

### Bản cải tiến

Nhóm xây dựng một web app PHP hoàn chỉnh.

Các thành phần chính:

- `public/login.php`
- `public/index.php`
- `public/history.php`
- `public/admin.php`
- `public/admin_drugs.php`
- `public/admin_diseases.php`
- `public/admin_links.php`
- `public/logout.php`
- `public/assets/style.css`

#### 1. Đăng nhập và phân quyền

Người dùng phải đăng nhập trước khi vào dashboard.

Có hai mức quyền:

- `user`
- `admin`

Ý nghĩa:

- tạo cảm giác hệ thống hoàn chỉnh
- phù hợp mô hình ứng dụng thật

#### 2. Dashboard dự đoán

Trang `index.php` cho phép:

- chọn dataset
- nhập tên hoặc ID
- chọn hướng truy vấn `drug_to_disease` hoặc `disease_to_drug`
- chọn `top-k`
- gửi yêu cầu đến API
- xem bảng kết quả

#### 3. Trực quan hóa đồ thị 3D

Frontend tích hợp `3d-force-graph`.

Ý nghĩa:

- tăng khả năng trình bày trực quan
- giúp người nghe báo cáo thấy được mạng lưới liên kết thay vì chỉ thấy bảng số

#### 4. Hiển thị trạng thái API

Trang chủ có kiểm tra API online/offline.

Ý nghĩa:

- giúp vận hành dễ hơn
- giảm lỗi demo

#### 5. Lịch sử truy vấn

Người dùng có thể xem các lần dự đoán gần đây.

Ý nghĩa:

- chuyển từ “chạy model một lần” sang “hệ thống có lưu vết sử dụng”

#### 6. Trang quản trị

Admin có thể:

- xem thống kê
- quản lý thuốc
- quản lý bệnh
- quản lý liên kết drug-disease

Đây là cải tiến rất lớn so với repo gốc vì nó đưa dự án sang hướng ứng dụng thông tin sinh học thực thụ.

## 5.9. Cải tiến ở tầng cơ sở dữ liệu

### Bản gốc

Repo gốc không có MySQL schema.

### Bản cải tiến

Nhóm xây dựng `database_schema.sql` với hệ thống bảng đầy đủ:

- `users`
- `drugs`
- `diseases`
- `proteins`
- `drug_disease_links`
- `drug_protein_links`
- `protein_disease_links`
- `prediction_requests`
- `prediction_results`
- `system_settings`

Ý nghĩa của từng nhóm bảng:

- bảng thực thể sinh học: lưu dữ liệu để hiển thị và quản trị
- bảng liên kết sinh học: lưu tri thức nền
- bảng người dùng: phục vụ xác thực và phân quyền
- bảng prediction history: lưu lịch sử suy luận để tra cứu
- bảng settings: hỗ trợ cấu hình hệ thống

So với AMDGT gốc, đây là bước nâng cấp từ mô hình học máy sang hệ thống thông tin có trạng thái.

## 5.10. Cải tiến ở lớp service PHP

Hai file quan trọng:

- `app/services/AuthService.php`
- `app/services/PredictionService.php`

### `AuthService.php`

Chức năng:

- xác thực username/password bằng `password_verify`
- lưu session người dùng
- cập nhật `last_login_at`

### `PredictionService.php`

Chức năng:

- kiểm tra sức khỏe API
- gọi FastAPI bằng cURL
- xử lý lỗi HTTP và lỗi kết nối
- lưu lịch sử request
- lưu từng kết quả trả về vào `prediction_results`

Ý nghĩa:

- tách logic nghiệp vụ khỏi giao diện
- mã nguồn có tổ chức hơn
- dễ bảo trì hơn

## 5.11. Cải tiến ở metadata và khả năng hiển thị dữ liệu thật

### Bản gốc

Repo gốc thiên về ID số hoặc ID mã hóa trong dataset.

Điều này đủ để train mô hình nhưng không thân thiện cho demo.

### Bản cải tiến

Nhóm viết bộ script:

- `scripts/fetch_protein_names.py`
- `scripts/fetch_disease_names.py`
- `scripts/generate_metadata_csv.py`

Chức năng:

- lấy tên protein và gene từ UniProt
- lấy tên bệnh từ OMIM thông qua OLS/NCBI
- lưu cache JSON
- sinh CSV metadata mới cho từng dataset

Ý nghĩa:

- biến dữ liệu khó hiểu thành dữ liệu có thể đọc được
- tăng chất lượng giao diện
- giúp báo cáo dễ thuyết phục hơn vì có thể nêu tên thực thể thật

## 5.12. Cải tiến ở khả năng chạy trên Google Colab và môi trường hiện đại

### Bản gốc

README gốc yêu cầu môi trường khá cũ:

- Python 3.9.13
- PyTorch 1.10.0
- DGL 0.9.0

Trong thực tế, việc chạy lại repo nghiên cứu cũ trên Colab hoặc máy mới thường gặp nhiều lỗi tương thích.

### Bản cải tiến

Nhóm bổ sung:

- `COLAB_TRAINING.md`
- `requirements-colab.txt`
- `scripts/colab_setup.sh`
- `scripts/colab_train.py`

Các cải tiến cụ thể:

- hướng dẫn cài đúng version package trên Colab
- có smoke test trước khi train dài
- có preset `smoke`, `standard`, `full`
- hỗ trợ mount Google Drive để lưu kết quả
- có cảnh báo riêng cho `F-dataset`

Ý nghĩa:

- tăng khả năng tái lập thực nghiệm
- giảm thời gian debug môi trường
- thuận lợi cho việc train trong đồ án hoặc demo trên nền tảng cloud

## 6. Tóm tắt các nhóm cải tiến lớn nhất

| Nhóm cải tiến | Bản gốc AMDGT | Dự án hiện tại | Mức độ thay đổi |
|---|---|---|---|
| Phạm vi repo | Research code thuần Python | Hệ thống full-stack AI + web + DB | Rất lớn |
| Preprocess | Một pipeline consensus cơ bản | Sửa logic disease consensus, hỗ trợ multi-view, edge stats | Lớn |
| Topology | Không có module riêng | Có `topology_features.py` và `TopologyEncoder` | Rất lớn |
| Model | Kiến trúc AMNTDDA cố định | Nhiều backbone, fusion, pair head, contrastive, diagnostics | Rất lớn |
| Training | Script ngắn, train trực tiếp | Preset theo dataset, logging, checkpoint, early stopping, weighting | Rất lớn |
| Inference | Không có | FastAPI + cache + checkpoint loader + graph response | Rất lớn |
| Frontend | Không có | PHP dashboard, login, history, admin, 3D graph | Rất lớn |
| Database | Không có | MySQL schema đầy đủ | Rất lớn |
| Metadata | Chủ yếu ID thô | Script fetch tên disease/protein và tạo CSV metadata | Lớn |
| Triển khai | README nghiên cứu | Hướng dẫn web + Colab + môi trường thực tế | Lớn |

## 7. Ý nghĩa học thuật và ý nghĩa ứng dụng của các cải tiến

## 7.1. Ý nghĩa học thuật

Các cải tiến ở lớp mô hình và pipeline cho thấy nhóm không chỉ "gói lại" repo cũ mà đã có can thiệp về mặt chuyên môn:

- bổ sung topology feature
- bổ sung multi-view fusion
- bổ sung contrastive alignment
- mở rộng backbone và pair head
- nâng chất lượng thực nghiệm bằng class weighting và logging

Điều này cho thấy nhóm đã hiểu bản chất bài toán và phát triển tiếp theo hướng có cơ sở.

## 7.2. Ý nghĩa ứng dụng

Các cải tiến ở web, API và cơ sở dữ liệu biến mô hình từ dạng nghiên cứu thành sản phẩm trình diễn được:

- người dùng có thể đăng nhập
- người dùng có thể truy vấn drug hoặc disease
- hệ thống lưu lịch sử
- admin có thể quản lý dữ liệu
- kết quả có thể trực quan hóa bằng graph 3D

Đây là giá trị rất lớn trong bối cảnh đồ án cơ sở vì chứng minh được khả năng đưa mô hình AI vào một luồng sử dụng thực tế.

## 8. Những điểm cần trình bày trung thực trong báo cáo

Để báo cáo thuyết phục và tránh bị phản biện ngược, nên nêu rõ các giới hạn sau:

### 1. Chưa có bằng chứng benchmark cuối cùng trong repo

Trong workspace hiện tại không thấy thư mục kết quả train hoặc checkpoint được commit vào `Result/`.

Điều đó có nghĩa là:

- có thể khẳng định dự án đã cải tiến kiến trúc và pipeline
- nhưng chưa thể khẳng định chắc chắn bằng số liệu trong repo rằng AUC/AUPR cuối cùng luôn cao hơn bản gốc bao nhiêu

### 2. Một số tham số nâng cao mới chỉ ở mức chuẩn bị

Trong `train_final.py` có khai báo:

- warmup
- ranking loss
- hard negative weight
- EMA
- target AUC schedule

nhưng ở vòng lặp train hiện tại, phần loss đang dùng thực tế chủ yếu là:

- cross entropy
- contrastive loss

Do đó, nên trình bày đúng là:

- hệ thống đã chuẩn bị nền cho các kỹ thuật nâng cao
- nhưng chưa phải tất cả đều được kích hoạt đầy đủ trong phiên bản hiện tại

### 3. `edge_stats` chưa được dùng trọn vẹn

Preprocess đã sinh `edge_stats`, nhưng trong `improved_model.py` biến này hiện bị bỏ qua.

Điều này có thể báo cáo là:

- một hướng mở rộng đang được chuẩn bị
- chưa phải thành phần đóng góp trực tiếp trong phiên bản hiện tại

### 4. API inference đang dùng cấu hình model khá cố định

`python_api/main.py` đang mặc định:

- `assoc_backbone = vanilla_hgt`
- `fusion_mode = mva`
- `pair_mode = mul_mlp`

Vì vậy, nếu train ra checkpoint bằng một cấu hình khác thì API có thể cần đồng bộ thêm.

### 5. Tài liệu và code chưa đồng bộ 100%

Một vài hướng dẫn cũ hoặc ghi chú kế hoạch chưa cập nhật hoàn toàn với code hiện tại.

Trong báo cáo nên nói:

- code là nguồn chân thực nhất
- tài liệu triển khai đã có nhưng vẫn cần hoàn thiện thêm

## 9. Đánh giá tổng thể mức độ cải tiến

Nếu đánh giá theo thang định tính, có thể xếp như sau:

- **Cải tiến rất lớn**:
  - kiến trúc hệ thống tổng thể
  - tầng web và cơ sở dữ liệu
  - tầng API suy luận
  - tầng huấn luyện
  - tầng mô hình multi-view có topology
- **Cải tiến lớn**:
  - tiền xử lý dữ liệu
  - metadata và khả năng trình diễn
  - khả năng chạy trên Colab
- **Cải tiến vừa**:
  - metric robustness
  - tương thích môi trường mới
- **Đang ở mức nền tảng cho phát triển tiếp**:
  - edge statistics
  - một số tham số train nâng cao chưa sử dụng hết

## 10. Câu kết luận có thể dùng trực tiếp khi thuyết trình

Có thể dùng đoạn sau:

> So với AMDGT gốc, dự án của nhóm em không dừng ở việc tái hiện lại mô hình nghiên cứu, mà đã phát triển thành một hệ thống hoàn chỉnh hơn theo hai hướng. Thứ nhất là hướng học thuật, nhóm đã cải tiến pipeline train, bổ sung topology feature, multi-view fusion, contrastive alignment và nhiều cơ chế giúp mô hình ổn định hơn. Thứ hai là hướng ứng dụng, nhóm đã xây dựng FastAPI, web PHP, cơ sở dữ liệu MySQL, lịch sử dự đoán, trang quản trị và trực quan hóa đồ thị 3D. Vì vậy, giá trị của dự án hiện tại không chỉ nằm ở mô hình AI, mà còn nằm ở việc biến một research repo thành một hệ thống có thể triển khai và demo thực tế.

## 11. Kết luận cuối cùng

So với bản gốc AMDGT, dự án hiện tại đã có bước phát triển mạnh ở cả chiều sâu lẫn chiều rộng:

- **Chiều sâu**: cải tiến mô hình, bổ sung topology, multi-view fusion, contrastive learning, pipeline huấn luyện ổn định hơn
- **Chiều rộng**: mở rộng sang API, web, MySQL, quản trị, history, metadata, tài liệu triển khai và Colab workflow

Nếu repo gốc là một **mô hình nghiên cứu**, thì repo hiện tại đã tiến gần đến một **nguyên mẫu hệ thống AI ứng dụng hoàn chỉnh**.

## 12. Phụ lục: các file minh chứng quan trọng

### Baseline gốc

- `AMDGT/README.md`
- `AMDGT/data_preprocess.py`
- `AMDGT/metric.py`
- `AMDGT/train_DDA.py`
- `AMDGT/model/AMNTDDA.py`

### Phần cải tiến AI

- `data_preprocess_improved.py`
- `topology_features.py`
- `train_final.py`
- `model/AMNTDDA.py`
- `model/improved/improved_model.py`
- `model/improved/topology_encoder.py`
- `model/improved/contrastive_loss.py`
- `model/improved/multi_view_aggregator.py`
- `model/improved/similarity_view_fusion.py`
- `model/improved/fuzzy_attention.py`
- `model/improved/rlg_hgt.py`

### Phần suy luận và ứng dụng

- `python_api/main.py`
- `app/services/PredictionService.php`
- `app/services/AuthService.php`
- `public/index.php`
- `public/login.php`
- `public/history.php`
- `public/admin.php`
- `public/admin_drugs.php`
- `public/admin_diseases.php`
- `public/admin_links.php`
- `database_schema.sql`
- `public/assets/style.css`

### Phần hỗ trợ vận hành

- `README.md`
- `HUONG_DAN_CHAY_WEB.md`
- `COLAB_TRAINING.md`
- `requirements-colab.txt`
- `scripts/colab_train.py`
- `scripts/colab_setup.sh`
- `scripts/fetch_protein_names.py`
- `scripts/fetch_disease_names.py`
- `scripts/generate_metadata_csv.py`
