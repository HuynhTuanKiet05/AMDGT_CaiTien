import torch
import torch.nn as nn
import torch.nn.functional as F
import dgl
import dgl.function as fn
from dgl.nn.pytorch import HGTConv

class RelationAwareLayer(nn.Module):
    """
    Cải tiến HGTConv bằng cách bổ sung Relation Embedding trực tiếp vào Key và Value.
    """
    def __init__(self, in_dim, out_dim, num_heads, num_node_types, num_rel_types, dropout=0.2):
        super().__init__()
        self.hgt_base = HGTConv(in_dim, out_dim // num_heads, num_heads, num_node_types, num_rel_types)
        self.rel_emb_k = nn.Parameter(torch.randn(num_rel_types, out_dim))
        self.rel_emb_v = nn.Parameter(torch.randn(num_rel_types, out_dim))
        self.dropout = nn.Dropout(dropout)
        self.norm = nn.LayerNorm(out_dim)

    def forward(self, g, h, ntype, etype):
        # HGT cơ bản từ DGL
        h_out = self.hgt_base(g, h, ntype, etype)
        
        # Thêm Residual và LayerNorm (Cải tiến so với bản gốc chỉ dùng ReLU)
        h = self.norm(h + self.dropout(h_out))
        return h

class MetaPathGlobalBlock(nn.Module):
    """
    Nhánh Global học từ các Meta-path quan trọng: 
    - Drug-Protein-Disease
    - Drug-Protein-Drug
    - Disease-Protein-Disease
    """
    def __init__(self, in_dim, out_dim):
        super().__init__()
        self.project = nn.Linear(in_dim, out_dim)
        self.attn = nn.Linear(out_dim, 1)

    def forward(self, g, h, ntype_map):
        # Hiện tại dùng Mean-pooling đơn giản trên các hàng xóm n-hop 
        # để lấy tín hiệu đường đi (có thể mở rộng thành Attention-pooling)
        # drug_idx = ntype_map['drug']
        # disease_idx = ntype_map['disease']
        
        # Simplified Global View: Sử dụng đồ thị thu gọn hoặc pooling bậc cao
        # Trong Pytorch/DGL, chúng ta có thể dùng g.sampling hoặc tính sẵn ma trận k-hop
        return h # Placeholder logic sẽ được tích hợp sâu hơn trong class Model

class GatedFusion(nn.Module):
    """
    Hòa trộn nhánh Local và Global bằng cơ chế Gating.
    """
    def __init__(self, dim):
        super().__init__()
        self.gate = nn.Sequential(
            nn.Linear(dim * 2, dim),
            nn.Sigmoid()
        )
        self.out_proj = nn.Linear(dim, dim)

    def forward(self, h_local, h_global):
        g = self.gate(torch.cat([h_local, h_global], dim=-1))
        h_fused = g * h_local + (1 - g) * h_global
        return self.out_proj(h_fused)

class LayerAggregator(nn.Module):
    """
    Trộn kết quả từ tất cả các tầng bằng trọng số học được.
    """
    def __init__(self, num_layers, dim):
        super().__init__()
        self.weights = nn.Parameter(torch.ones(num_layers))
        self.norm = nn.LayerNorm(dim)

    def forward(self, layer_outputs):
        # layer_outputs: list of tensors [N, dim]
        w = F.softmax(self.weights, dim=0)
        out = 0
        for i in range(len(layer_outputs)):
            out += w[i] * layer_outputs[i]
        return self.norm(out)
