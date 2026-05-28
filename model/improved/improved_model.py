import os

import dgl
import dgl.nn.pytorch
import torch
import torch.nn as nn
import torch.nn.functional as F

from AMDGT.model import gt_net_disease, gt_net_drug

from .contrastive_loss import MultiViewContrastiveLoss
from .fuzzy_attention import FuzzyGate
from .multi_view_aggregator import MultiViewAggregator
from .rlg_hgt import RLGHGT
from .similarity_view_fusion import SimilarityViewFusion
from .topology_encoder import TopologyEncoder


def _normalize_fusion_mode(fusion_mode):
    if fusion_mode == "mva_fuzzy":
        return "rvg"
    return fusion_mode


class ReferencePairHead(nn.Module):
    def __init__(self, node_dim):
        super().__init__()
        self.mlp = nn.Sequential(
            nn.Linear(node_dim, 1024),
            nn.ReLU(),
            nn.Dropout(0.4),
            nn.Linear(1024, 1024),
            nn.ReLU(),
            nn.Dropout(0.4),
            nn.Linear(1024, 256),
            nn.ReLU(),
            nn.Dropout(0.4),
            nn.Linear(256, 2),
        )

    def forward(self, drug_repr, disease_repr):
        return self.mlp(drug_repr * disease_repr)


class InteractionPairHead(nn.Module):
    def __init__(self, node_dim, dropout=0.3):
        super().__init__()
        in_dim = node_dim * 4 + 2
        self.main = nn.Sequential(
            nn.LayerNorm(in_dim),
            nn.Linear(in_dim, 1024),
            nn.GELU(),
            nn.Dropout(dropout),
            nn.Linear(1024, 512),
            nn.GELU(),
            nn.Dropout(dropout),
            nn.Linear(512, 128),
            nn.GELU(),
            nn.Linear(128, 2),
        )
        self.skip = nn.Linear(in_dim, 2)
        self.bilinear = nn.Bilinear(node_dim, node_dim, 1)

    def forward(self, drug_repr, disease_repr):
        pair_mul = drug_repr * disease_repr
        pair_abs = torch.abs(drug_repr - disease_repr)
        pair_cos = F.cosine_similarity(drug_repr, disease_repr, dim=-1).unsqueeze(-1)
        pair_bilinear = self.bilinear(drug_repr, disease_repr)
        features = torch.cat([drug_repr, disease_repr, pair_mul, pair_abs, pair_cos, pair_bilinear], dim=-1)
        return self.main(features) + self.skip(features)


class AMNTDDA(nn.Module):
    def __init__(self, args):
        super().__init__()
        self.args = args
        runtime_device = getattr(args, "device", None)
        if isinstance(runtime_device, torch.device):
            self.runtime_device = runtime_device
        elif runtime_device is not None:
            self.runtime_device = torch.device(runtime_device)
        else:
            self.runtime_device = torch.device(os.environ.get("AMDGT_DEVICE", "cuda" if torch.cuda.is_available() else "cpu"))

        self.assoc_backbone = getattr(args, "assoc_backbone", "vanilla_hgt")
        self.fusion_mode = _normalize_fusion_mode(getattr(args, "fusion_mode", "mva"))
        self.pair_mode = getattr(args, "pair_mode", "mul_mlp")
        self.gate_mode = getattr(args, "gate_mode", "vector")
        self.gate_bias_init = getattr(args, "gate_bias_init", -2.0)
        self.similarity_view_mode = getattr(args, "similarity_view_mode", "consensus")

        self.drug_linear = nn.Linear(300, args.hgt_in_dim)
        self.protein_linear = nn.Linear(320, args.hgt_in_dim)
        self.disease_linear = nn.Linear(64, args.hgt_in_dim) if args.hgt_in_dim != 64 else None
        # C3: Feature normalization — prevents scale mismatch across entity types
        self.drug_norm = nn.LayerNorm(args.hgt_in_dim)
        self.protein_norm = nn.LayerNorm(args.hgt_in_dim)
        self.disease_norm = nn.LayerNorm(args.hgt_in_dim)

        self.gt_drug = gt_net_drug.GraphTransformer(
            self.runtime_device,
            args.gt_layer,
            args.drug_number,
            args.gt_out_dim,
            args.gt_out_dim,
            args.gt_head,
            args.dropout,
        )
        self.gt_disease = gt_net_disease.GraphTransformer(
            self.runtime_device,
            args.gt_layer,
            args.disease_number,
            args.gt_out_dim,
            args.gt_out_dim,
            args.gt_head,
            args.dropout,
        )
        self.drug_similarity_fuser = None
        self.disease_similarity_fuser = None
        if self.similarity_view_mode == "multi":
            self.drug_similarity_fuser = SimilarityViewFusion(
                view_dim=args.gt_out_dim,
                nhead=args.tr_head,
                num_layers=max(1, args.tr_layer),
                dropout=args.dropout,
            )
            self.disease_similarity_fuser = SimilarityViewFusion(
                view_dim=args.gt_out_dim,
                nhead=args.tr_head,
                num_layers=max(1, args.tr_layer),
                dropout=args.dropout,
            )

        self.canonical_etypes = [
            ("drug", "association", "disease"),
            ("drug", "association", "protein"),
            ("disease", "association", "protein"),
        ]
        self.node_types = ["drug", "disease", "protein"]

        if self.assoc_backbone == "vanilla_hgt":
            self.hgt_layers = nn.ModuleList()
            for _ in range(args.hgt_layer - 1):
                self.hgt_layers.append(
                    dgl.nn.pytorch.conv.HGTConv(
                        args.hgt_in_dim,
                        int(args.hgt_in_dim / args.hgt_head),
                        args.hgt_head,
                        3,
                        3,
                        args.dropout,
                    )
                )
            self.hgt_layers.append(
                dgl.nn.pytorch.conv.HGTConv(
                    args.hgt_in_dim,
                    args.hgt_head_dim,
                    args.hgt_head,
                    3,
                    3,
                    args.dropout,
                )
            )
            assoc_dim = args.hgt_head_dim * args.hgt_head
            self.rlg_hgt = None
        elif self.assoc_backbone == "rlghgt":
            self.hgt_layers = None
            self.rlg_hgt = RLGHGT(
                hidden_dim=args.hgt_in_dim,
                out_dim=args.gt_out_dim,
                num_heads=args.hgt_head,
                num_layers=args.hgt_layer,
                canonical_etypes=self.canonical_etypes,
                node_types=self.node_types,
                dropout=args.dropout,
                use_relation_attention=getattr(args, "use_relation_attention", True),
                use_metapath=getattr(args, "use_metapath", True),
                use_global=getattr(args, "use_global_hgt", True),
                use_topological=getattr(args, "use_topological", True),
            )
            assoc_dim = args.gt_out_dim
        else:
            raise ValueError(f"Unsupported assoc_backbone: {self.assoc_backbone}")

        if assoc_dim == args.gt_out_dim:
            self.drug_assoc_proj = nn.Identity()
            self.disease_assoc_proj = nn.Identity()
        else:
            self.drug_assoc_proj = nn.Sequential(nn.Linear(assoc_dim, args.gt_out_dim), nn.LayerNorm(args.gt_out_dim))
            self.disease_assoc_proj = nn.Sequential(nn.Linear(assoc_dim, args.gt_out_dim), nn.LayerNorm(args.gt_out_dim))

        topo_feat_dim = getattr(args, "topo_feat_dim", 7)
        topo_hidden = getattr(args, "topo_hidden", 128)
        temperature = getattr(args, "contrastive_temperature", getattr(args, "temperature", 0.5))

        self.drug_topology_encoder = TopologyEncoder(
            topo_feat_dim=topo_feat_dim,
            hidden_dim=topo_hidden,
            out_dim=args.gt_out_dim,
            dropout=args.dropout,
        )
        self.disease_topology_encoder = TopologyEncoder(
            topo_feat_dim=topo_feat_dim,
            hidden_dim=topo_hidden,
            out_dim=args.gt_out_dim,
            dropout=args.dropout,
        )
        self.contrastive_loss = MultiViewContrastiveLoss(temperature=temperature)

        if self.fusion_mode == "mva":
            self.drug_multi_view = MultiViewAggregator(
                view_dim=args.gt_out_dim,
                nhead=args.tr_head,
                num_layers=args.tr_layer,
                dropout=args.dropout,
            )
            self.disease_multi_view = MultiViewAggregator(
                view_dim=args.gt_out_dim,
                nhead=args.tr_head,
                num_layers=args.tr_layer,
                dropout=args.dropout,
            )
            self.drug_trans = None
            self.disease_trans = None
            self.drug_fuzzy_gate = None
            self.disease_fuzzy_gate = None
            node_repr_dim = args.gt_out_dim * 3
        elif self.fusion_mode == "rvg":
            self.drug_multi_view = None
            self.disease_multi_view = None
            drug_encoder_layer = nn.TransformerEncoderLayer(d_model=args.gt_out_dim, nhead=args.tr_head, batch_first=True)
            disease_encoder_layer = nn.TransformerEncoderLayer(d_model=args.gt_out_dim, nhead=args.tr_head, batch_first=True)
            self.drug_trans = nn.TransformerEncoder(drug_encoder_layer, num_layers=args.tr_layer)
            self.disease_trans = nn.TransformerEncoder(disease_encoder_layer, num_layers=args.tr_layer)
            self.drug_fuzzy_gate = FuzzyGate(
                base_dim=args.gt_out_dim * 2,
                topo_dim=args.gt_out_dim,
                dropout=args.dropout,
                gate_mode=self.gate_mode,
                gate_bias_init=self.gate_bias_init,
            )
            self.disease_fuzzy_gate = FuzzyGate(
                base_dim=args.gt_out_dim * 2,
                topo_dim=args.gt_out_dim,
                dropout=args.dropout,
                gate_mode=self.gate_mode,
                gate_bias_init=self.gate_bias_init,
            )
            node_repr_dim = args.gt_out_dim * 2
        else:
            raise ValueError(f"Unsupported fusion_mode: {self.fusion_mode}")

        if self.pair_mode == "mul_mlp":
            self.pair_head = ReferencePairHead(node_repr_dim)
        elif self.pair_mode == "interaction":
            self.pair_head = InteractionPairHead(node_repr_dim, dropout=args.dropout)
        else:
            raise ValueError(f"Unsupported pair_mode: {self.pair_mode}")

    def _encode_entity_features(self, drug_feature, disease_feature, protein_feature):
        drug_feature = self.drug_norm(self.drug_linear(drug_feature))
        protein_feature = self.protein_norm(self.protein_linear(protein_feature))
        if self.disease_linear is not None:
            disease_feature = self.disease_norm(self.disease_linear(disease_feature))
        else:
            disease_feature = self.disease_norm(disease_feature)
        return {
            "drug": drug_feature,
            "disease": disease_feature,
            "protein": protein_feature,
        }

    def _compute_vanilla_assoc_views(self, drdipr_graph, feature_dict):
        drug_type_id = drdipr_graph.get_ntype_id("drug")
        disease_type_id = drdipr_graph.get_ntype_id("disease")
        with drdipr_graph.local_scope():
            drdipr_graph.ndata["h"] = feature_dict
            homo_graph = dgl.to_homogeneous(drdipr_graph, ndata="h")
            feature = homo_graph.ndata["h"]
            ntype = homo_graph.ndata["_TYPE"]
            etype = homo_graph.edata["_TYPE"]
            for layer in self.hgt_layers:
                feature = layer(homo_graph, feature, ntype, etype, presorted=True)

        drug_mask = ntype == drug_type_id
        disease_mask = ntype == disease_type_id
        return feature[drug_mask], feature[disease_mask]

    def _compute_rlghgt_assoc_views(self, drdipr_graph, feature_dict):
        assoc_out = self.rlg_hgt(drdipr_graph, feature_dict)
        return assoc_out["drug"], assoc_out["disease"]

    def _compute_assoc_views(self, drdipr_graph, drug_feature, disease_feature, protein_feature):
        if drdipr_graph.device != drug_feature.device:
            drdipr_graph = drdipr_graph.to(drug_feature.device)

        feature_dict = self._encode_entity_features(drug_feature, disease_feature, protein_feature)
        if self.assoc_backbone == "vanilla_hgt":
            drug_assoc, disease_assoc = self._compute_vanilla_assoc_views(drdipr_graph, feature_dict)
        else:
            drug_assoc, disease_assoc = self._compute_rlghgt_assoc_views(drdipr_graph, feature_dict)

        return self.drug_assoc_proj(drug_assoc), self.disease_assoc_proj(disease_assoc)

    def _compute_similarity_embeddings(self, graph_input, encoder, fuser, prefix, return_diagnostics=False):
        if isinstance(graph_input, dict):
            view_names = list(graph_input.keys())
            view_embeddings = [encoder(graph_input[name]) for name in view_names]
            diagnostics = {}
            if fuser is not None:
                if return_diagnostics:
                    fused, weights = fuser(view_embeddings, return_weights=True)
                    for idx, name in enumerate(view_names):
                        diagnostics[f"{prefix}_view_weight_{name}"] = float(weights[:, idx].mean().item())
                else:
                    fused = fuser(view_embeddings)
            else:
                fused = torch.stack(view_embeddings, dim=0).mean(dim=0)
                if return_diagnostics:
                    uniform_weight = 1.0 / max(1, len(view_names))
                    for name in view_names:
                        diagnostics[f"{prefix}_view_weight_{name}"] = uniform_weight
            return fused, diagnostics

        return encoder(graph_input), {}

    @staticmethod
    def _summarize_gate_branch(base_rep, gate_details, prefix):
        gate = gate_details["gate"]
        topo_proj = gate_details["topo_proj"]
        residual = gate_details["residual"]
        base_norm = torch.norm(base_rep, dim=-1)
        topo_norm = torch.norm(topo_proj, dim=-1)
        residual_norm = torch.norm(residual, dim=-1)
        return {
            f"{prefix}_gate_mean": float(gate.mean().item()),
            f"{prefix}_gate_std": float(gate.std(unbiased=False).item()),
            f"{prefix}_gate_min": float(gate.min().item()),
            f"{prefix}_gate_max": float(gate.max().item()),
            f"{prefix}_base_norm_mean": float(base_norm.mean().item()),
            f"{prefix}_topo_norm_mean": float(topo_norm.mean().item()),
            f"{prefix}_residual_norm_mean": float(residual_norm.mean().item()),
            f"{prefix}_residual_ratio_mean": float((residual_norm / base_norm.clamp_min(1e-8)).mean().item()),
        }

    @staticmethod
    def _zero_gate_branch(prefix):
        return {
            f"{prefix}_gate_mean": 0.0,
            f"{prefix}_gate_std": 0.0,
            f"{prefix}_gate_min": 0.0,
            f"{prefix}_gate_max": 0.0,
            f"{prefix}_base_norm_mean": 0.0,
            f"{prefix}_topo_norm_mean": 0.0,
            f"{prefix}_residual_norm_mean": 0.0,
            f"{prefix}_residual_ratio_mean": 0.0,
        }

    def forward(
        self,
        drdr_graph,
        didi_graph,
        drdipr_graph,
        drug_feature,
        disease_feature,
        protein_feature,
        sample,
        drug_topo_feat=None,
        disease_topo_feat=None,
        edge_stats=None,
        return_aux=False,
        return_diagnostics=False,
    ):
        del edge_stats

        drug_sim, drug_view_diagnostics = self._compute_similarity_embeddings(
            drdr_graph,
            self.gt_drug,
            self.drug_similarity_fuser,
            "drug",
            return_diagnostics=return_diagnostics,
        )
        disease_sim, disease_view_diagnostics = self._compute_similarity_embeddings(
            didi_graph,
            self.gt_disease,
            self.disease_similarity_fuser,
            "disease",
            return_diagnostics=return_diagnostics,
        )
        drug_assoc, disease_assoc = self._compute_assoc_views(drdipr_graph, drug_feature, disease_feature, protein_feature)

        drug_topo = torch.zeros_like(drug_sim) if drug_topo_feat is None else self.drug_topology_encoder(drug_topo_feat)
        disease_topo = torch.zeros_like(disease_sim) if disease_topo_feat is None else self.disease_topology_encoder(disease_topo_feat)

        contrastive_drug = self.contrastive_loss(drug_sim, drug_assoc, drug_topo)
        contrastive_disease = self.contrastive_loss(disease_sim, disease_assoc, disease_topo)
        contrastive = 0.5 * (contrastive_drug + contrastive_disease)

        if return_diagnostics:
            diagnostics = {
                "assoc_backbone": self.assoc_backbone,
                "fusion_mode": self.fusion_mode,
                "pair_mode": self.pair_mode,
                "similarity_view_mode": self.similarity_view_mode,
                "contrastive_alignment_drug": float(F.cosine_similarity(drug_sim, drug_topo, dim=-1).mean().item()),
                "contrastive_alignment_disease": float(F.cosine_similarity(disease_sim, disease_topo, dim=-1).mean().item()),
                "drug_sim_assoc_cos": float(F.cosine_similarity(drug_sim, drug_assoc, dim=-1).mean().item()),
                "disease_sim_assoc_cos": float(F.cosine_similarity(disease_sim, disease_assoc, dim=-1).mean().item()),
                "drug_assoc_norm": float(torch.norm(drug_assoc, dim=-1).mean().item()),
                "disease_assoc_norm": float(torch.norm(disease_assoc, dim=-1).mean().item()),
                "contrastive": float(contrastive.item()),
            }
            diagnostics.update(drug_view_diagnostics)
            diagnostics.update(disease_view_diagnostics)
        else:
            diagnostics = None

        if self.fusion_mode == "mva":
            drug_repr = self.drug_multi_view(drug_sim, drug_assoc, drug_topo)
            disease_repr = self.disease_multi_view(disease_sim, disease_assoc, disease_topo)
            if return_diagnostics:
                diagnostics.update(self._zero_gate_branch("drug"))
                diagnostics.update(self._zero_gate_branch("disease"))
        else:
            drug_base = torch.stack((drug_sim, drug_assoc), dim=1)
            disease_base = torch.stack((disease_sim, disease_assoc), dim=1)
            drug_base = self.drug_trans(drug_base).reshape(self.args.drug_number, 2 * self.args.gt_out_dim)
            disease_base = self.disease_trans(disease_base).reshape(self.args.disease_number, 2 * self.args.gt_out_dim)
            if return_diagnostics:
                drug_repr, drug_gate_details = self.drug_fuzzy_gate(drug_base, drug_topo, return_details=True)
                disease_repr, disease_gate_details = self.disease_fuzzy_gate(disease_base, disease_topo, return_details=True)
                diagnostics.update(self._summarize_gate_branch(drug_base, drug_gate_details, "drug"))
                diagnostics.update(self._summarize_gate_branch(disease_base, disease_gate_details, "disease"))
            else:
                drug_repr = self.drug_fuzzy_gate(drug_base, drug_topo)
                disease_repr = self.disease_fuzzy_gate(disease_base, disease_topo)

        pair_drug = drug_repr[sample[:, 0].long()]
        pair_disease = disease_repr[sample[:, 1].long()]
        output = self.pair_head(pair_drug, pair_disease)

        aux_losses = {"contrastive": contrastive}
        if return_aux and return_diagnostics:
            return drug_repr, output, aux_losses, diagnostics
        if return_aux:
            return drug_repr, output, aux_losses
        if return_diagnostics:
            return drug_repr, output, diagnostics
        return drug_repr, output
