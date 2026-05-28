import os
import torch
import torch.nn as nn

# Đây là file Bridge (Cầu nối) hỗ trợ việc chuyển đổi giữa mô hình gốc và mô hình cải tiến
# Tuân thủ yêu cầu: "giữ nguyên mô hình gốc" và "từ mô hình gốc gọi tiến file cải tiến"

def get_model(args, version=None):
    """
    Factory function để lấy instance của mô hình.
    Mặc định sử dụng 'improved' nếu không có cấu hình khác.
    """
    # Lấy version từ môi trường hoặc tham số truyền vào
    selected_version = version or os.environ.get('HGT_MODEL_VERSION', 'improved')
    
    if selected_version == 'improved':
        print(">>> Loading IMPROVED HGT Model (RLG-HGT) ...")
        from model.improved.improved_model import AMNTDDA as ImprovedAMNTDDA
        return ImprovedAMNTDDA(args)
    else:
        print(">>> Loading ORIGINAL AMDGT Model (Baseline) ...")
        from AMDGT.model.AMNTDDA import AMNTDDA as BaseAMNTDDA
        return BaseAMNTDDA(args)

# Để tương thích ngược với các script cũ thực hiện 'from model.AMNTDDA import AMNTDDA'
class AMNTDDA(nn.Module):
    def __new__(cls, args):
        # Khi khởi tạo AMNTDDA, thực chất sẽ trả về instance của bản gốc hoặc cải tiến
        return get_model(args)
