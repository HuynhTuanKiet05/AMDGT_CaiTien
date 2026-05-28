"""
Quick-train script: sinh checkpoint demo cho ORIGINAL + IMPROVED model.
Chạy 1 fold, ít epoch trên CPU. KHÔNG dùng cho production, chỉ để demo web.

Usage:
    cd AMDGT_CaiTien
    python scripts/generate_demo_checkpoints.py --dataset C-dataset --epochs 5
"""
import argparse
import os
import sys
import timeit
from pathlib import Path

import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as fn
import torch.optim as optim

PROJECT_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(PROJECT_ROOT))
sys.path.insert(0, str(PROJECT_ROOT / 'python_api'))

from AMDGT.data_preprocess import (
    dgl_similarity_graph as orig_sim_graph,
    dgl_heterograph as orig_hetero,
    get_data as orig_get_data,
    data_processing as orig_data_processing,
    k_fold as orig_k_fold,
)
from AMDGT.model.AMNTDDA import AMNTDDA as OriginalAMNTDDA

from data_preprocess_improved import (
    dgl_similarity_graph as imp_sim_graph,
    dgl_heterograph as imp_hetero,
    get_data,
    data_processing,
    k_fold,
)
from model.improved.improved_model import AMNTDDA as ImprovedAMNTDDA
from topology_features import extract_topology_features

device = torch.device('cpu')


def train_original(args, epochs):
    """Train original model, 1 fold, save checkpoint."""
    print(f"\n{'='*60}")
    print(f"Training ORIGINAL model on {args.dataset} for {epochs} epochs")
    print(f"{'='*60}")

    orig_args = argparse.Namespace(
        dataset=args.dataset,
        data_dir=str(PROJECT_ROOT / 'AMDGT' / 'data' / args.dataset),
        k_fold=10,
        negative_rate=1.0,
        neighbor=20,
        dropout=0.2,
        gt_layer=2, gt_head=2, gt_out_dim=200,
        hgt_layer=2, hgt_head=8, hgt_in_dim=64, hgt_head_dim=25, hgt_out_dim=200,
        tr_layer=2, tr_head=4,
        random_seed=1234,
    )

    data = orig_get_data(orig_args)
    orig_args.drug_number = data['drug_number']
    orig_args.disease_number = data['disease_number']
    orig_args.protein_number = data['protein_number']
    data = orig_data_processing(data, orig_args)
    data = orig_k_fold(data, orig_args)

    drdr_graph, didi_graph, data = orig_sim_graph(data, orig_args)
    drdr_graph = drdr_graph.to(device)
    didi_graph = didi_graph.to(device)

    drug_feature = torch.FloatTensor(data['drugfeature']).to(device)
    disease_feature = torch.FloatTensor(data['diseasefeature']).to(device)
    protein_feature = torch.FloatTensor(data['proteinfeature']).to(device)

    model = OriginalAMNTDDA(orig_args).to(device)
    optimizer = optim.Adam(model.parameters(), weight_decay=1e-3, lr=1e-4)
    criterion = nn.CrossEntropyLoss()

    x_train = torch.LongTensor(data['X_train'][0]).to(device)
    y_train = torch.LongTensor(data['Y_train'][0]).to(device).flatten()
    drdipr_graph, data = orig_hetero(data, data['X_train'][0], orig_args)
    drdipr_graph = drdipr_graph.to(device)

    start = timeit.default_timer()
    for epoch in range(epochs):
        model.train()
        _, train_score = model(drdr_graph, didi_graph, drdipr_graph,
                               drug_feature, disease_feature, protein_feature, x_train)
        loss = criterion(train_score, y_train)
        optimizer.zero_grad()
        loss.backward()
        optimizer.step()
        elapsed = timeit.default_timer() - start
        print(f"  Epoch {epoch+1}/{epochs} | loss={loss.item():.4f} | time={elapsed:.1f}s")

    # Save checkpoint
    out_dir = PROJECT_ROOT / 'Result' / 'original' / args.dataset
    out_dir.mkdir(parents=True, exist_ok=True)
    ckpt_path = out_dir / 'best_model.pth'
    torch.save({'model_state_dict': model.state_dict(), 'epoch': epochs, 'fold': 0}, ckpt_path)
    print(f"  Saved: {ckpt_path}")
    return ckpt_path


def train_improved(args, epochs):
    """Train improved model, 1 fold, save checkpoint."""
    print(f"\n{'='*60}")
    print(f"Training IMPROVED model on {args.dataset} for {epochs} epochs")
    print(f"{'='*60}")

    imp_args = argparse.Namespace(
        dataset=args.dataset,
        data_dir=str(PROJECT_ROOT / 'AMDGT' / 'data' / args.dataset),
        k_fold=10,
        negative_rate=1.0,
        neighbor=5,
        dropout=0.2,
        gt_layer=2, gt_head=2, gt_out_dim=256,
        hgt_layer=2, hgt_head=8, hgt_in_dim=256, hgt_head_dim=32, hgt_out_dim=256,
        tr_layer=2, tr_head=4,
        random_seed=1234,
        assoc_backbone='vanilla_hgt',
        fusion_mode='mva',
        pair_mode='mul_mlp',
        gate_mode='vector',
        gate_bias_init=-2.0,
        temperature=0.5,
        topo_hidden=128,
        topo_feat_dim=7,
        similarity_view_mode='consensus',
        device=str(device),
    )

    data = get_data(imp_args)
    imp_args.drug_number = data['drug_number']
    imp_args.disease_number = data['disease_number']
    imp_args.protein_number = data['protein_number']
    data = data_processing(data, imp_args)
    data = k_fold(data, imp_args)

    drdr_graph, didi_graph, data = imp_sim_graph(data, imp_args)
    drdr_graph = drdr_graph.to(device)
    didi_graph = didi_graph.to(device)

    drug_topo_feat, disease_topo_feat = extract_topology_features(data, imp_args)
    drug_topo_feat = drug_topo_feat.to(device)
    disease_topo_feat = disease_topo_feat.to(device)

    drug_feature = torch.tensor(data['drugfeature'], dtype=torch.float32).to(device)
    disease_feature = torch.tensor(data['diseasefeature'], dtype=torch.float32).to(device)
    protein_feature = torch.tensor(data['proteinfeature'], dtype=torch.float32).to(device)

    model = ImprovedAMNTDDA(imp_args).to(device)
    optimizer = optim.Adam(model.parameters(), weight_decay=1e-3, lr=1e-4)
    criterion = nn.CrossEntropyLoss()

    x_train = torch.tensor(data['X_train'][0], dtype=torch.long, device=device)
    y_train = torch.tensor(data['Y_train'][0], dtype=torch.long, device=device).flatten()
    drdipr_graph, data_updated, *_ = imp_hetero(data, data['X_train'][0], imp_args)
    drdipr_graph = drdipr_graph.to(device)

    start = timeit.default_timer()
    for epoch in range(epochs):
        model.train()
        _, train_score, aux = model(
            drdr_graph, didi_graph, drdipr_graph,
            drug_feature, disease_feature, protein_feature, x_train,
            drug_topo_feat=drug_topo_feat,
            disease_topo_feat=disease_topo_feat,
            return_aux=True,
        )
        ce_loss = criterion(train_score, y_train)
        cl_loss = aux.get('contrastive', torch.tensor(0.0))
        loss = ce_loss + 0.1 * cl_loss
        optimizer.zero_grad()
        loss.backward()
        optimizer.step()
        elapsed = timeit.default_timer() - start
        print(f"  Epoch {epoch+1}/{epochs} | loss={loss.item():.4f} | ce={ce_loss.item():.4f} | cl={cl_loss.item():.4f} | time={elapsed:.1f}s")

    out_dir = PROJECT_ROOT / 'Result' / 'improved' / args.dataset
    out_dir.mkdir(parents=True, exist_ok=True)
    ckpt_path = out_dir / 'best_model.pth'
    torch.save({'model_state_dict': model.state_dict(), 'epoch': epochs, 'fold': 0}, ckpt_path)
    print(f"  Saved: {ckpt_path}")
    return ckpt_path


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Generate demo checkpoints for original + improved models')
    parser.add_argument('--dataset', default='C-dataset', choices=['B-dataset', 'C-dataset', 'F-dataset'])
    parser.add_argument('--epochs', type=int, default=5, help='Number of training epochs (demo: 3-10)')
    args = parser.parse_args()

    print(f"Device: {device}")
    print(f"Dataset: {args.dataset}")
    print(f"Epochs: {args.epochs}")

    orig_path = train_original(args, args.epochs)
    imp_path = train_improved(args, args.epochs)

    print(f"\n{'='*60}")
    print("DONE! Checkpoints created:")
    print(f"  Original: {orig_path}")
    print(f"  Improved: {imp_path}")
    print(f"{'='*60}")
    print("\nGiờ có thể restart FastAPI và chạy chức năng so sánh trên web.")
