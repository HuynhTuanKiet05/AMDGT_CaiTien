# So Sánh Phần Model Train Của `Colab_V4` So Với `AMDGT` Gốc

## 1. Phạm vi tài liệu

Tài liệu này chỉ so sánh phần:

- tiền xử lý dữ liệu phục vụ huấn luyện
- kiến trúc mô hình
- chiến lược fusion
- loss function
- training pipeline

Repo gốc đối chiếu:

- GitHub: `https://github.com/JK-Liu7/AMDGT`
- baseline trong workspace: `Colab_V4/AMDGT/`

## 2. Tóm tắt các phần đã bổ sung để cải tiến

Trước khi đi sâu vào từng thành phần, có thể tóm tắt ngắn gọn rằng `Colab_V4` đã bổ sung thêm nhiều module mới vào pipeline train để cải tiến các điểm còn hạn chế của `AMDGT` gốc.

## 2.1. Bảng tóm tắt đã thêm gì để cải tiến phần nào

| Thành phần được bổ sung / thay đổi | File hoặc module tiêu biểu | Dùng để cải tiến phần nào |
|---|---|---|
| Sửa preprocess similarity | `data_preprocess_improved.py` | Làm đầu vào huấn luyện hợp lý hơn, nhất là ở nhánh disease |
| Multi-view similarity graph | `dgl_similarity_view_graphs()` | Giữ nhiều góc nhìn similarity thay vì ép về một graph duy nhất |
| Topology feature extraction | `topology_features.py` | Bổ sung tín hiệu cấu trúc đồ thị cho drug và disease |
| Topology encoder | `model/improved/topology_encoder.py` | Biến topology thành embedding để đưa vào mô hình |
| Similarity view fusion | `model/improved/similarity_view_fusion.py` | Học cách trộn các similarity view tốt hơn |
| Multi-view aggregator | `model/improved/multi_view_aggregator.py` | Hợp nhất similarity, association và topology theo cách mềm hơn |
| Fuzzy gate | `model/improved/fuzzy_attention.py` | Điều tiết mức đóng góp của topology vào biểu diễn cuối |
| Contrastive loss | `model/improved/contrastive_loss.py` | Đồng bộ embedding giữa các view, giảm xung đột khi fusion |
| Pair head mở rộng | `InteractionPairHead` trong `improved_model.py` | Mô tả tương tác drug-disease phong phú hơn |
| Backbone association mở rộng | `model/improved/rlg_hgt.py` | Tạo thêm lựa chọn backbone cho nhánh association |
| Training pipeline mới | `train_final.py` | Làm quá trình train ổn định hơn và dễ kiểm soát hơn |

## 2.2. Tóm tắt theo nhóm cải tiến

Có thể gom các nâng cấp của `Colab_V4` thành 5 nhóm chính:

1. cải tiến đầu vào huấn luyện:
   sửa logic similarity và giữ được nhiều view hơn
2. cải tiến mức biểu diễn:
   thêm topology như một nguồn thông tin mới
3. cải tiến mức fusion:
   dùng các cơ chế hợp nhất mềm hơn thay vì ghép cứng
4. cải tiến mức objective:
   thêm contrastive loss để các nhánh học nhất quán hơn
5. cải tiến mức training pipeline:
   bổ sung weighting, scheduler, early stopping, checkpoint

Nhờ đó, phần cải tiến của `Colab_V4` không chỉ nằm ở một chỗ, mà trải đều từ dữ liệu đầu vào, biểu diễn trung gian, loss function cho đến toàn bộ quy trình huấn luyện.

## 3. Kết luận ngắn gọn

So với `AMDGT` gốc, `Colab_V4` đã nâng cấp phần model train theo hướng:

1. đầu vào giàu thông tin hơn
2. biểu diễn node đa góc nhìn hơn
3. fusion mềm và linh hoạt hơn
4. loss huấn luyện mạnh hơn
5. training ổn định và dễ kiểm soát hơn

Nói ngắn gọn: bản gốc chủ yếu học từ `similarity view + HGT association view`, còn bản cải tiến học từ `similarity view + association view + topology view`, đồng thời thêm contrastive alignment và nhiều lựa chọn fusion/pair scoring hơn.

## 3.1. Bảng tóm tắt tổng quan

| Hạng mục | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Dữ liệu similarity | Chủ yếu gom về consensus graph | Giữ cả nhiều similarity view | Giảm mất thông tin do trộn sớm |
| Topology | Chưa có nhánh riêng | Có topology feature + topology encoder | Bổ sung tín hiệu cấu trúc đồ thị |
| Fusion | Tương đối cố định | Có nhiều chiến lược fusion mềm hơn | Học cách trộn thông tin tốt hơn |
| Loss | Chủ yếu loss phân loại | Thêm contrastive loss | Đồng bộ embedding giữa các view |
| Pair scoring | Chủ yếu nhân từng phần tử + MLP | Có thêm interaction head | Mô tả tương tác drug-disease phong phú hơn |
| Training pipeline | Gọn, ít cơ chế ổn định hóa | Có weighting, scheduler, early stopping, checkpoint | Huấn luyện ổn định và dễ kiểm soát hơn |

## 4. Bản gốc `AMDGT` học như thế nào

Ở mức tổng quát, `AMDGT` gốc huấn luyện theo pipeline:

1. đọc dữ liệu similarity và association
2. tạo graph similarity cho drug và disease
3. tạo heterograph drug - disease - protein
4. học embedding similarity bằng dual graph transformer
5. học embedding association bằng HGT
6. ghép hai nhánh này lại
7. dự đoán cặp drug - disease bằng MLP

Đây là một baseline tốt, nhưng có vài giới hạn:

- similarity bị nén khá sớm vào một graph consensus
- chưa có nhánh topology riêng
- chưa có contrastive loss giữa các view
- chiến lược fusion còn khá cố định
- training pipeline còn gọn, ít cơ chế ổn định hóa hơn

## 4.1. Bảng tóm tắt pipeline baseline

| Bước | AMDGT gốc |
|---|---|
| 1 | Đọc dữ liệu similarity và association |
| 2 | Tạo graph similarity cho drug và disease |
| 3 | Tạo heterograph drug-disease-protein |
| 4 | Học similarity embedding bằng dual graph transformer |
| 5 | Học association embedding bằng HGT |
| 6 | Ghép hai nhánh biểu diễn |
| 7 | Dự đoán cặp drug-disease bằng MLP |

## 5. Các thuật ngữ quan trọng cần hiểu

## 5.1. Bảng thuật ngữ

| Thuật ngữ | Tiếng Việt | Ý nghĩa ngắn gọn |
|---|---|---|
| Topology | Đặc trưng cấu trúc đồ thị | Cho biết node nằm ở đâu và có vai trò gì trong mạng |
| Multi-view | Đa góc nhìn | Một đối tượng được mô tả qua nhiều loại similarity khác nhau |
| Fusion | Hợp nhất thông tin | Trộn các nhánh biểu diễn lại thành biểu diễn chung |
| Contrastive learning | Học tương phản | Ép các embedding đúng bản chất gần nhau hơn |
| Fuzzy gate | Cổng mờ | Điều tiết mềm mức đóng góp của topology |
| Pair scoring | Chấm điểm cặp | Tính điểm tương tác giữa drug và disease |

### 5.2. Topology (đặc trưng cấu trúc đồ thị)

`Topology` là thông tin mô tả vị trí của một node trong đồ thị.

Ví dụ:

- degree (bậc kết nối)
- weighted degree (bậc có trọng số)
- clustering coefficient (hệ số gom cụm)
- PageRank
- average neighbor degree (bậc trung bình hàng xóm)

Ý nghĩa:

- mô hình biết node này nằm ở vùng trung tâm hay vùng biên
- mô hình thấy được vai trò cấu trúc của drug hoặc disease trong mạng

Nói đơn giản: topology cho mô hình biết "node này đứng ở đâu trong đồ thị", không chỉ "node này có vector gì".

### 5.3. Multi-view (đa góc nhìn)

`Multi-view` nghĩa là cùng một đối tượng nhưng được mô tả qua nhiều loại similarity khác nhau.

Trong repo này:

- drug có `fingerprint`, `gip`, `consensus`
- disease có `phenotype`, `gip`, `consensus`

Ý nghĩa:

- mỗi view giữ một loại tín hiệu riêng
- mô hình không bị ép trộn tất cả quá sớm

### 5.4. Contrastive learning (học tương phản)

Đây là cách ép các embedding thuộc cùng một thực thể nhưng đến từ các view khác nhau phải gần nhau hơn trong không gian biểu diễn.

Trong repo này, contrastive loss được dùng giữa:

- similarity view
- association view
- topology view

Ý nghĩa:

- các nhánh học đồng bộ hơn
- fusion phía sau ổn định hơn

### 5.5. Fuzzy gate (cổng mờ)

`FuzzyGate` là cơ chế học một trọng số mềm để điều tiết xem topology nên đóng góp bao nhiêu vào biểu diễn cuối.

Ý nghĩa:

- không cộng topology một cách cứng nhắc
- mô hình tự học khi nào nên tin topology nhiều hay ít

## 6. Các nâng cấp ở bước tiền xử lý phục vụ train

File liên quan:

- `AMDGT/data_preprocess.py`
- `data_preprocess_improved.py`

## 6.1. Bảng so sánh preprocess

| Hạng mục preprocess | AMDGT gốc | Colab_V4 cải tiến | Tác động |
|---|---|---|---|
| Consensus disease similarity | Logic cũ | Sửa fallback `dip -> dig` khi cần | Đầu vào disease hợp lý hơn |
| Similarity graph | Một graph tổng hợp | Nhiều view riêng biệt | Giữ tín hiệu chi tiết hơn |
| Edge statistics | Chưa trả thêm | Có `edge_stats` | Mở đường cho mở rộng mô hình |
| Tương thích PyTorch mới | Hạn chế hơn | Dùng `sparse_coo_tensor` | Ổn định hơn khi chạy |

## 6.2. Sửa logic consensus similarity của disease

Đây là một điểm kỹ thuật nhỏ nhưng quan trọng.

Bản cải tiến sửa cách tạo `dis` theo hướng:

- nếu `dip == 0` thì dùng `dig`
- nếu không thì lấy trung bình

Ý nghĩa:

- đầu vào disease similarity hợp lý hơn
- giảm nguy cơ mất thông tin ở nhánh disease

Điểm này có thể ảnh hưởng trực tiếp đến chất lượng graph similarity của disease, từ đó tác động đến embedding học được.

## 6.3. Hỗ trợ multi-view similarity graph

Bản gốc chỉ dùng một graph similarity tổng hợp.  
Bản cải tiến thêm `dgl_similarity_view_graphs()` để tạo riêng:

- drug fingerprint view
- drug GIP view
- drug consensus view
- disease phenotype view
- disease GIP view
- disease consensus view

Ý nghĩa:

- giữ lại tín hiệu riêng của từng view
- tạo nền cho `SimilarityViewFusion`
- tránh mất thông tin do trộn quá sớm

## 6.4. Chuẩn bị edge statistics

Trong `dgl_heterograph()`, bản cải tiến trả thêm `edge_stats`, ví dụ `pair_bias`.

Ý nghĩa:

- pipeline train được thiết kế để có thể đưa thêm thống kê cấu trúc vào mô hình

Ghi chú trung thực:

- trong code hiện tại, `edge_stats` đã được tạo nhưng chưa được dùng thực sự trong `improved_model.py`
- đây là hướng mở rộng đã chuẩn bị, chưa phải phần đóng góp hoàn chỉnh

## 7. Các nâng cấp quan trọng ở mức biểu diễn

## 7.1. Bảng so sánh mức biểu diễn

| Hạng mục biểu diễn | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Số nhánh chính | 2 nhánh: similarity + association | 3 nhánh: similarity + association + topology | Biểu diễn giàu thông tin hơn |
| Topology feature | Không có module riêng | Có trích xuất và encode riêng | Khai thác cấu trúc đồ thị tường minh |
| Similarity view | Chủ yếu consensus | Có thể dùng multi-view | Không làm mất thông tin quá sớm |

## 7.2. Thêm topology features riêng

File liên quan:

- `topology_features.py`
- `model/improved/topology_encoder.py`

Đây là nâng cấp đáng kể so với AMDGT gốc.

Bản gốc:

- không có module riêng để trích topology feature cho từng node
- topology chỉ được khai thác gián tiếp qua graph và HGT

Bản cải tiến:

1. trích topology feature riêng cho drug và disease
2. cache lại để tái sử dụng
3. đưa qua `TopologyEncoder` để chuyển sang embedding cùng không gian với các view khác

Ý nghĩa:

- topology trở thành một view độc lập
- mô hình có thêm tín hiệu cấu trúc rõ ràng
- tăng khả năng biểu diễn của node

Lý do có thể giúp kết quả tốt hơn:

- trong bài toán mạng sinh học, cấu trúc liên kết thường mang tín hiệu mạnh
- thêm topology giúp mô hình không chỉ dựa vào feature gốc và association

## 7.3. Từ 2 nhánh sang 3 nhánh biểu diễn

### Bản gốc

Mô hình gốc chủ yếu kết hợp:

- similarity embedding
- association embedding

### Bản cải tiến

Mô hình hiện tại kết hợp:

- similarity embedding
- association embedding
- topology embedding

Ý nghĩa:

- biểu diễn cuối cùng giàu thông tin hơn
- mỗi node được nhìn qua thêm một góc độ cấu trúc

## 8. Các nâng cấp ở mức fusion

File liên quan:

- `model/improved/improved_model.py`
- `model/improved/multi_view_aggregator.py`
- `model/improved/similarity_view_fusion.py`
- `model/improved/fuzzy_attention.py`

## 8.1. Bảng so sánh fusion

| Hạng mục fusion | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Fusion similarity | Chủ yếu consensus | `SimilarityViewFusion` học trọng số từng view | Chọn view hữu ích tốt hơn |
| Fusion node views | Ghép tương đối cố định | `MultiViewAggregator` hoặc `FuzzyGate` | Trộn nhánh linh hoạt hơn |
| Topology integration | Chưa tường minh | Đưa vào như một view riêng hoặc residual có gate | Dùng topology có kiểm soát hơn |

## 8.2. `SimilarityViewFusion`

Module này học trọng số cho từng similarity view thay vì ép dùng một consensus graph duy nhất.

Ý nghĩa:

- view mạnh hơn sẽ được ưu tiên
- view yếu hơn có thể bị giảm ảnh hưởng

So với baseline, đây là bước fusion thông minh hơn ở ngay đầu nhánh similarity.

## 8.3. `MultiViewAggregator`

Module này gom ba nhánh:

- similarity
- association
- topology

bằng transformer encoder.

Ý nghĩa:

- thay vì ghép cứng các nhánh, mô hình học cách trộn chúng
- biểu diễn cuối có thể giữ được tương tác giữa các view tốt hơn

## 8.4. `FuzzyGate`

Khi dùng `fusion_mode = rvg`, topology được đưa vào như một residual có gate điều tiết.

Ý nghĩa:

- topology không chi phối quá mạnh từ đầu
- mô hình tự học mức đóng góp phù hợp của topology

Đây là một kiểu fusion mềm hơn, an toàn hơn so với cộng trực tiếp.

## 9. Các nâng cấp ở mức loss function

File liên quan:

- `model/improved/contrastive_loss.py`
- `train_final.py`

## 9.1. Bảng so sánh loss

| Hạng mục loss | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Loss chính | Loss phân loại | Loss phân loại | Giữ mục tiêu dự đoán chính |
| Loss phụ | Chưa có contrastive view alignment | Có `MultiViewContrastiveLoss` | Đồng bộ biểu diễn giữa các view |
| Mục tiêu tối ưu | Chủ yếu tối ưu đầu ra | Tối ưu đầu ra và tối ưu không gian biểu diễn | Mô hình học ổn định hơn |

## 9.2. Thêm contrastive loss

Bản gốc chủ yếu tối ưu bằng loss phân loại nhị phân.  
Bản cải tiến thêm `MultiViewContrastiveLoss` để kéo gần:

- similarity view
- association view
- topology view

Ý nghĩa:

- các nhánh không học lệch nhau quá nhiều
- giảm xung đột khi fusion
- biểu diễn cuối nhất quán hơn

## 9.3. Tổng loss của bản cải tiến

Ở `train_final.py`, loss huấn luyện thực tế là:

- `cross entropy loss`
- cộng thêm `lambda_cl * contrastive_loss`

Điểm này cho thấy bản hiện tại không chỉ tối ưu cho dự đoán đầu ra, mà còn tối ưu sự nhất quán giữa các không gian biểu diễn.

## 10. Các nâng cấp ở bước pair scoring

File liên quan:

- `model/improved/improved_model.py`

## 10.1. Bảng so sánh pair scoring

| Hạng mục | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Kiểu tạo đặc trưng cặp | Chủ yếu nhân từng phần tử | Nhiều đặc trưng tương tác hơn | Mô tả quan hệ drug-disease tốt hơn |
| Pair head | MLP cơ bản | `ReferencePairHead` và `InteractionPairHead` | Linh hoạt hơn trong scoring |

### Bản gốc

Biểu diễn cặp chủ yếu theo kiểu:

- nhân từng phần tử
- đưa qua MLP

### Bản cải tiến

Ngoài kiểu cũ `mul_mlp`, bản hiện tại thêm `InteractionPairHead` với các tín hiệu:

- `pair_mul`: tích từng phần tử
- `pair_abs`: hiệu tuyệt đối
- `pair_cos`: cosine similarity
- `pair_bilinear`: tương tác song tuyến tính
- nối trực tiếp biểu diễn drug và disease

Ý nghĩa:

- đầu phân loại cuối khai thác quan hệ drug - disease phong phú hơn
- tăng khả năng mô tả các kiểu tương tác khác nhau giữa hai node

## 11. Các nâng cấp ở nhánh association backbone

File liên quan:

- `model/improved/improved_model.py`
- `model/improved/rlg_hgt.py`

## 11.1. Bảng so sánh association backbone

| Hạng mục backbone | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Backbone chính | `vanilla_hgt` | `vanilla_hgt` hoặc `rlghgt` | Linh hoạt hơn trong thí nghiệm |
| Layer aggregation | Chưa tách rõ thành module riêng | Có `LayerAggregator` | Trộn thông tin nhiều tầng tốt hơn |
| Khả năng mở rộng | Hạn chế hơn | Có interface cho nhiều cờ mở rộng | Thuận lợi cho ablation/future work |

Bản gốc cố định dùng `vanilla_hgt`.

Bản hiện tại hỗ trợ:

- `vanilla_hgt`
- `rlghgt`

Trong `RLGHGT`, code hiện tại đã có:

- residual connection
- layer norm
- nhiều tầng HGT
- layer aggregation bằng trọng số học được

Ý nghĩa:

- association branch linh hoạt hơn
- tạo điều kiện thử nghiệm backbone khác nhau

Ghi chú trung thực:

- các cờ như `use_relation_attention`, `use_metapath`, `use_global`, `use_topological` đã xuất hiện ở interface
- nhưng trong code hiện tại chúng chưa được triển khai đầy đủ
- vì vậy nên mô tả đây là `khung mở rộng của backbone`, không nên nói là đã hoàn chỉnh tất cả các nhánh lý thuyết

## 12. Các nâng cấp ở training pipeline

File liên quan:

- `AMDGT/train_DDA.py`
- `train_final.py`

## 12.1. Bảng so sánh training pipeline

| Hạng mục training | AMDGT gốc | Colab_V4 cải tiến | Ý nghĩa |
|---|---|---|---|
| Dataset preset | Ít cấu hình hóa hơn | Có preset theo dataset | Phù hợp dữ liệu hơn |
| Class imbalance | Xử lý hạn chế hơn | Nhiều `positive_weight_mode` | Giảm lệch lớp |
| Ổn định tối ưu | Ít cơ chế hơn | Scheduler, early stopping, grad clip, label smoothing | Train ổn định hơn |
| Theo dõi kết quả | Gọn hơn | Log, checkpoint, CSV kết quả theo fold | Dễ báo cáo và tái lập hơn |

## 12.2. Preset theo dataset

Repo hiện tại có `DATASET_PRESETS` cho từng dataset.

Ý nghĩa:

- mỗi bộ dữ liệu có thể dùng cấu hình phù hợp hơn
- giảm lệch do dùng một bộ siêu tham số cố định cho tất cả

## 12.3. Class weighting cho dữ liệu mất cân bằng

Repo hiện tại hỗ trợ nhiều chế độ `positive_weight_mode`:

- `none`
- `sampled`
- `global_log`
- `global_sqrt`
- `global_linear`

Ý nghĩa:

- bài toán drug-disease thường rất mất cân bằng lớp
- cơ chế weighting giúp mô hình không bị lệch quá mạnh về mẫu âm

## 12.4. Ổn định hóa quá trình tối ưu

Repo hiện tại bổ sung:

- `label_smoothing`
- `grad_clip`
- `ReduceLROnPlateau`
- `early stopping`

Ý nghĩa:

- train ổn định hơn
- giảm rung lắc loss
- giảm overfitting
- tiết kiệm thời gian chạy

## 12.5. Logging và checkpoint tốt hơn

Repo hiện tại có:

- kiểm tra file dữ liệu đầu vào
- log metric theo epoch
- lưu checkpoint tốt nhất
- lưu kết quả theo từng fold

Ý nghĩa:

- dễ theo dõi quá trình train
- dễ tái lập thí nghiệm
- dễ so sánh giữa các cấu hình

## 13. Tóm tắt các nâng cấp quan trọng nhất trong phần model train

## 13.1. Bảng tổng hợp cuối cùng

| Nhóm nâng cấp | Nội dung chính | Mức ảnh hưởng kỳ vọng |
|---|---|---|
| Preprocess | Sửa consensus disease, thêm multi-view similarity | Trung bình đến lớn |
| Biểu diễn | Thêm topology view | Lớn |
| Fusion | Similarity fusion, multi-view aggregation, fuzzy gate | Lớn |
| Loss | Contrastive alignment giữa các view | Trung bình đến lớn |
| Pair scoring | Interaction head phong phú hơn | Trung bình |
| Training pipeline | Weighting, scheduler, early stopping, checkpoint | Lớn về độ ổn định |

Nếu chỉ xét riêng phần model train, các nâng cấp đáng chú ý nhất của `Colab_V4` so với `AMDGT` gốc là:

1. sửa và làm giàu bước tiền xử lý similarity
2. thêm topology feature như một nhánh biểu diễn độc lập
3. hỗ trợ multi-view similarity thay vì ép về một graph duy nhất
4. thêm `SimilarityViewFusion`, `MultiViewAggregator`, `FuzzyGate` để fusion mềm hơn
5. thêm contrastive loss giữa các view
6. mở rộng pair head để mô tả tương tác drug - disease tốt hơn
7. mở rộng backbone association và training pipeline để huấn luyện ổn định hơn

## 14. Cách diễn đạt an toàn trong báo cáo

Nếu viết báo cáo hoặc thuyết trình, nên nói theo hướng này:

> So với AMDGT gốc, phần model train của `Colab_V4` được nâng cấp theo hướng học đa góc nhìn hơn. Cụ thể, ngoài similarity view và association view như baseline, mô hình còn bổ sung topology view, contrastive alignment giữa các view và nhiều cơ chế fusion mềm hơn như similarity view fusion, multi-view aggregation và fuzzy gate. Đồng thời, pipeline huấn luyện cũng được chuẩn hóa hơn với class weighting, early stopping, scheduler và checkpointing. Các thay đổi này giúp mô hình giàu thông tin hơn, huấn luyện ổn định hơn và có tiềm năng cải thiện chất lượng dự đoán so với baseline.

## 15. Kết luận

Nếu chỉ nhìn riêng phần model train, thì giá trị nâng cấp của `Colab_V4` nằm ở chỗ:

- không còn học theo cấu trúc 2 nhánh đơn giản như baseline
- đã chuyển sang học biểu diễn đa view
- đã đưa topology thành tín hiệu tường minh
- đã làm fusion và objective function mạnh hơn
- đã làm training pipeline chặt chẽ và ổn định hơn

Một câu ngắn gọn có thể dùng:

> So với AMDGT gốc, bản của nhóm em đã nâng cấp phần train theo hướng đa view, bổ sung topology feature, contrastive learning và cơ chế fusion mềm hơn, từ đó giúp mô hình học biểu diễn giàu thông tin và ổn định hơn trong bài toán dự đoán drug-disease association.
