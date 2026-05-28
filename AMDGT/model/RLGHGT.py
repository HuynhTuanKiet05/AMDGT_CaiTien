import torch
import torch.nn as nn
import torch.nn.functional as F
import dgl

from . import gt_net_drug, gt_net_disease
from .rlg_layers import RelationAwareLayer, LayerAggregator

device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')

class RLGHGT(nn.Module):
    def __init__(self, args):
        super(RLGHGT, self).__init__()
        self.args = args
        
        # 1. Similarity Branch
        self.gt_drug = gt_net_drug.GraphTransformer(device, args.gt_layer, args.drug_number, args.gt_out_dim, args.gt_out_dim,
                                                    args.gt_head, args.dropout)
        self.gt_disease = gt_net_disease.GraphTransformer(device, args.gt_layer, args.disease_number, args.gt_out_dim,
                                                    args.gt_out_dim, args.gt_head, args.dropout)

        # 2. Input Projections
        self.drug_linear = nn.Linear(300, args.hgt_in_dim)
        self.disease_linear = nn.Linear(64, args.hgt_in_dim)
        self.protein_linear = nn.Linear(320, args.hgt_in_dim)

        # 3. RLG-HGT Layers
        self.hgt_layers = nn.ModuleList()
        for _ in range(args.hgt_layer):
            self.hgt_layers.append(RelationAwareLayer(args.hgt_in_dim, args.hgt_in_dim, args.hgt_head, 3, 6, args.dropout))

        # 4. Layer Aggregator
        self.layer_agg = LayerAggregator(args.hgt_layer, args.hgt_in_dim)

        # 5. Modality Interaction (Transformer Encoders)
        encoder_layer = nn.TransformerEncoderLayer(d_model=args.gt_out_dim, nhead=args.tr_head, batch_first=True)
        self.drug_trans = nn.TransformerEncoder(encoder_layer, num_layers=args.tr_layer)
        self.disease_trans = nn.TransformerEncoder(encoder_layer, num_layers=args.tr_layer)

        # Projection to match dims
        self.hgt_to_gt_proj = nn.Linear(args.hgt_in_dim, args.gt_out_dim)

        # 6. VERSION 2: Deep Interaction MLP
        # Input size: 2 * (2 * gt_out_dim) = 4 * gt_out_dim if concat
        # We will use: concat([dr, di, dr*di, |dr-di|])
        # Each dr, di has size (2 * gt_out_dim) = 2 * 200 = 400
        # Total input size = 400 * 4 = 1600
        combined_dim = self.args.gt_out_dim * 2
        self.interaction_mlp = nn.Sequential(
            nn.Linear(combined_dim * 4, 1024),
            nn.BatchNorm1d(1024),
            nn.LeakyReLU(0.2),
            nn.Dropout(0.4),
            nn.Linear(1024, 512),
            nn.BatchNorm1d(512),
            nn.LeakyReLU(0.2),
            nn.Dropout(0.3),
            nn.Linear(512, 128),
            nn.LeakyReLU(0.2),
            nn.Linear(128, 2)
        )
        
        self._init_weights()

    def _init_weights(self):
        for m in self.modules():
            if isinstance(m, nn.Linear):
                nn.init.orthogonal_(m.weight)
                if m.bias is not None:
                    nn.init.constant_(m.bias, 0)

    def forward(self, drdr_graph, didi_graph, drdipr_graph, drug_feature, disease_feature, protein_feature, sample):
        # --- Similarity Path ---
        dr_sim = self.gt_drug(drdr_graph)
        di_sim = self.gt_disease(didi_graph)

        # --- HGT Path ---
        drug_h = self.drug_linear(drug_feature)
        disease_h = self.disease_linear(disease_feature)
        protein_h = self.protein_linear(protein_feature)

        feature_dict = {'drug': drug_h, 'disease': disease_h, 'protein': protein_h}
        drdipr_graph.ndata['h'] = feature_dict
        g_homo = dgl.to_homogeneous(drdipr_graph, ndata='h')
        
        h = g_homo.ndata['h']
        layer_outputs = []
        for layer in self.hgt_layers:
            h = layer(g_homo, h, g_homo.ndata['_TYPE'], g_homo.edata['_TYPE']) 
            layer_outputs.append(h)
        
        hgt_out = self.layer_agg(layer_outputs)

        # Slicing & Projections (disease: 0, drug: 1, protein: 2)
        di_hgt = self.hgt_to_gt_proj(hgt_out[:self.args.disease_number, :])
        dr_hgt = self.hgt_to_gt_proj(hgt_out[self.args.disease_number:self.args.disease_number+self.args.drug_number, :])

        # --- Modal Fusion ---
        dr = torch.stack((dr_sim, dr_hgt), dim=1) # [N, 2, dim]
        di = torch.stack((di_sim, di_hgt), dim=1) # [N, 2, dim]

        dr = self.drug_trans(dr).view(self.args.drug_number, -1)     # [N, 2*dim]
        di = self.disease_trans(di).view(self.args.disease_number, -1) # [N, 2*dim]

        # --- VERSION 2: Advanced Interaction ---
        dr_s = dr[sample[:, 0]]
        di_s = di[sample[:, 1]]
        
        # Concat features: [dr, di, dr*di, |dr-di|]
        interaction_feat = torch.cat([
            dr_s, 
            di_s, 
            dr_s * di_s, 
            torch.abs(dr_s - di_s)
        ], dim=-1)
        
        output = self.interaction_mlp(interaction_feat)

        return dr, output
