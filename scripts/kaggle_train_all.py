"""
=============================================================
KAGGLE TRAINING SCRIPT - Drug Disease HGT (Both Models)
=============================================================
Cách dùng trên Kaggle:
1. Tạo notebook mới trên Kaggle
2. Upload repo (Add Data > Your Datasets hoặc GitHub)
3. Copy từng cell vào notebook và chạy

Hoặc chạy toàn bộ file này:
    python kaggle_train_all.py --dataset C-dataset --model both
=============================================================
"""

import argparse
import os
import sys
import gc
import timeit
from pathlib import Path

# ── Kaggle-specific setup ──────────────────────────────────
KAGGLE = os.path.exists('/kaggle')
if KAGGLE:
    REPO = Path('/kaggle/working/AMDGT_CaiTien')
    OUTPUT = Path('/kaggle/working/checkpoints')
else:
    REPO = Path(__file__).resolve().parent.parent
    OUTPUT = REPO / 'Result'

OUTPUT.mkdir(parents=True, exist_ok=True)
sys.path.insert(0, str(REPO))
sys.path.insert(0, str(REPO / 'python_api'))
os.chdir(str(REPO))

import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F
import torch.optim as optim
from torch.optim.lr_scheduler import ReduceLROnPlateau

# ── Hyperparameters per dataset ───────────────────────────
IMPROVED_PRESETS = {
    'B-dataset': dict(lr=1e-4, weight_decay=1e-3, neighbor=3,
                      gt_out_dim=512, hgt_layer=2, hgt_in_dim=512,
                      hgt_head=8, hgt_head_dim=64, topo_hidden=192,
                      similarity_view_mode='consensus', positive_weight_mode='none'),
    'C-dataset': dict(lr=1e-4, weight_decay=1e-3, neighbor=5,
                      gt_out_dim=256, hgt_layer=2, hgt_in_dim=256,
                      hgt_head=8, hgt_head_dim=32, topo_hidden=128,
                      similarity_view_mode='consensus', positive_weight_mode='none'),
    'F-dataset': dict(lr=8e-5, weight_decay=1e-3, neighbor=10,
                      gt_out_dim=384, hgt_layer=3, hgt_in_dim=384,
                      hgt_head=8, hgt_head_dim=48, topo_hidden=192,
                      similarity_view_mode='multi', positive_weight_mode='global_log'),
}

ORIGINAL_PRESET = dict(lr=1e-4, weight_decay=1e-3, neighbor=20,
                       gt_out_dim=200, hgt_layer=2, hgt_in_dim=64,
                       hgt_head=8, hgt_head_dim=25, hgt_out_dim=200,
                       gt_layer=2, gt_head=2, tr_layer=2, tr_head=4)


# ── MockArgs builder ──────────────────────────────────────
def make_args(dataset, data_dir, preset, model_version='improved'):
    class A:
        pass
    a = A()
    a.dataset = dataset
    a.data_dir = str(data_dir)
    a.k_fold = 10
    a.negative_rate = 1.0
    a.dropout = 0.2
    a.random_seed = 1234
    a.gt_layer = preset.get('gt_layer', 2)
    a.gt_head = preset.get('gt_head', 2)
    a.gt_out_dim = preset['gt_out_dim']
    a.hgt_layer = preset['hgt_layer']
    a.hgt_head = preset.get('hgt_head', 8)
    a.hgt_head_dim = preset['hgt_head_dim']
    a.hgt_in_dim = preset['hgt_in_dim']
    a.hgt_out_dim = preset.get('hgt_out_dim', preset['gt_out_dim'])
    a.tr_layer = preset.get('tr_layer', 2)
    a.tr_head = preset.get('tr_head', 4)
    a.neighbor = preset['neighbor']
    if model_version == 'improved':
        a.assoc_backbone = 'vanilla_hgt'
        a.fusion_mode = 'mva'
        a.pair_mode = 'mul_mlp'
        a.gate_mode = 'vector'
        a.gate_bias_init = -2.0
        a.temperature = 0.5
        a.topo_hidden = preset.get('topo_hidden', 128)
        a.topo_feat_dim = 7
        a.similarity_view_mode = preset.get('similarity_view_mode', 'consensus')
        a.positive_weight_mode = preset.get('positive_weight_mode', 'none')
        a.device = str(device)
    return a


# ── Training: ORIGINAL model ──────────────────────────────
def train_original(dataset, epochs=1000, k_fold=10):
    from AMDGT.data_preprocess import (
        get_data, data_processing, k_fold as make_kfold,
        dgl_similarity_graph, dgl_heterograph,
    )
    from AMDGT.model.AMNTDDA import AMNTDDA
    from metric import get_metric
    import pandas as pd

    print(f"\n{'='*60}")
    print(f"ORIGINAL MODEL | {dataset} | {epochs} epochs | {k_fold} folds")
    print(f"{'='*60}")

    data_dir = REPO / 'AMDGT' / 'data' / dataset
    args = make_args(dataset, data_dir, ORIGINAL_PRESET, 'original')
    args.k_fold = k_fold

    data = get_data(args)
    args.drug_number = data['drug_number']
    args.disease_number = data['disease_number']
    args.protein_number = data['protein_number']
    data = data_processing(data, args)
    data = make_kfold(data, args)

    drdr_graph, didi_graph, data = dgl_similarity_graph(data, args)
    drdr_graph = drdr_graph.to(device)
    didi_graph = didi_graph.to(device)

    drug_feat = torch.FloatTensor(data['drugfeature']).to(device)
    disease_feat = torch.FloatTensor(data['diseasefeature']).to(device)
    protein_feat = torch.FloatTensor(data['proteinfeature']).to(device)

    best_overall_auc = -1.0
    best_state = None
    aucs = []

    for fold in range(k_fold):
        print(f"\n--- Fold {fold}/{k_fold-1} ---")
        model = AMNTDDA(args).to(device)
        optimizer = optim.Adam(model.parameters(), lr=args.lr, weight_decay=args.weight_decay)
        criterion = nn.CrossEntropyLoss()

        x_train = torch.LongTensor(data['X_train'][fold]).to(device)
        y_train = torch.LongTensor(data['Y_train'][fold]).to(device).flatten()
        x_test  = torch.LongTensor(data['X_test'][fold]).to(device)
        y_test  = data['Y_test'][fold].flatten()

        drdipr_graph, data = dgl_heterograph(data, data['X_train'][fold], args)
        drdipr_graph = drdipr_graph.to(device)

        best_auc = -1.0
        no_improve = 0
        t0 = timeit.default_timer()

        for epoch in range(epochs):
            model.train()
            _, score = model(drdr_graph, didi_graph, drdipr_graph,
                             drug_feat, disease_feat, protein_feat, x_train)
            loss = criterion(score, y_train)
            optimizer.zero_grad(); loss.backward(); optimizer.step()

            if (epoch + 1) % 50 == 0 or epoch == epochs - 1:
                model.eval()
                with torch.no_grad():
                    _, ts = model(drdr_graph, didi_graph, drdipr_graph,
                                  drug_feat, disease_feat, protein_feat, x_test)
                prob = F.softmax(ts, dim=-1)[:, 1].cpu().numpy()
                pred = torch.argmax(ts, dim=-1).cpu().numpy()
                auc, *_ = get_metric(y_test, pred, prob)
                elapsed = timeit.default_timer() - t0
                print(f"  Epoch {epoch+1:4d} | AUC={auc:.4f} | {elapsed:.1f}s")

                if auc > best_auc:
                    best_auc = auc
                    no_improve = 0
                    if auc > best_overall_auc:
                        best_overall_auc = auc
                        best_state = {k: v.cpu().clone() for k, v in model.state_dict().items()}
                else:
                    no_improve += 50

                if no_improve >= 300:
                    print(f"  Early stop at epoch {epoch+1}")
                    break

        aucs.append(best_auc)
        print(f"  Fold {fold} best AUC: {best_auc:.4f}")
        del model, optimizer, drdipr_graph
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()

    print(f"\nOriginal | Mean AUC: {np.mean(aucs):.4f} ± {np.std(aucs):.4f}")

    out_dir = OUTPUT / 'original' / dataset
    out_dir.mkdir(parents=True, exist_ok=True)
    ckpt_path = out_dir / 'best_model.pth'
    torch.save({'model_state_dict': best_state, 'auc': best_overall_auc,
                'dataset': dataset, 'model_version': 'original'}, ckpt_path)
    print(f"Saved: {ckpt_path}")
    return ckpt_path


# ── Training: IMPROVED model ──────────────────────────────
def train_improved(dataset, epochs=1000, k_fold=10):
    from data_preprocess_improved import (
        get_data, data_processing, k_fold as make_kfold,
        dgl_similarity_graph, dgl_heterograph, dgl_similarity_view_graphs,
    )
    from model.improved.improved_model import AMNTDDA
    from topology_features import extract_topology_features
    from metric import get_metric

    print(f"\n{'='*60}")
    print(f"IMPROVED MODEL | {dataset} | {epochs} epochs | {k_fold} folds")
    print(f"{'='*60}")

    preset = IMPROVED_PRESETS[dataset]
    data_dir = REPO / 'AMDGT' / 'data' / dataset
    args = make_args(dataset, data_dir, preset, 'improved')
    args.k_fold = k_fold

    data = get_data(args)
    args.drug_number  = data['drug_number']
    args.disease_number = data['disease_number']
    args.protein_number = data['protein_number']
    data = data_processing(data, args)
    data = make_kfold(data, args)

    if args.similarity_view_mode == 'multi':
        drdr_graph, didi_graph, data = dgl_similarity_view_graphs(data, args)
        drdr_graph = {k: v.to(device) for k, v in drdr_graph.items()}
        didi_graph = {k: v.to(device) for k, v in didi_graph.items()}
    else:
        drdr_graph, didi_graph, data = dgl_similarity_graph(data, args)
        drdr_graph = drdr_graph.to(device)
        didi_graph = didi_graph.to(device)

    drug_topo, disease_topo = extract_topology_features(data, args)
    drug_topo    = drug_topo.to(device)
    disease_topo = disease_topo.to(device)
    drug_feat    = torch.tensor(data['drugfeature'],    dtype=torch.float32).to(device)
    disease_feat = torch.tensor(data['diseasefeature'], dtype=torch.float32).to(device)
    protein_feat = torch.tensor(data['proteinfeature'], dtype=torch.float32).to(device)

    best_overall_auc = -1.0
    best_state = None
    aucs = []

    for fold in range(k_fold):
        print(f"\n--- Fold {fold}/{k_fold-1} ---")
        model = AMNTDDA(args).to(device)
        optimizer = optim.Adam(model.parameters(), lr=args.lr, weight_decay=args.weight_decay)
        scheduler = ReduceLROnPlateau(optimizer, mode='max', factor=0.5, patience=30, min_lr=1e-6)
        criterion = nn.CrossEntropyLoss(label_smoothing=0.01)

        x_train = torch.tensor(data['X_train'][fold], dtype=torch.long, device=device)
        y_train = torch.tensor(data['Y_train'][fold], dtype=torch.long, device=device).flatten()
        x_test  = torch.tensor(data['X_test'][fold],  dtype=torch.long, device=device)
        y_test  = data['Y_test'][fold].flatten()

        result = dgl_heterograph(data, data['X_train'][fold], args)
        drdipr_graph = result[0].to(device)

        best_auc = -1.0
        no_improve = 0
        t0 = timeit.default_timer()

        for epoch in range(epochs):
            model.train()
            _, score, aux = model(
                drdr_graph, didi_graph, drdipr_graph,
                drug_feat, disease_feat, protein_feat, x_train,
                drug_topo_feat=drug_topo, disease_topo_feat=disease_topo,
                return_aux=True,
            )
            cl = aux.get('contrastive', score.new_tensor(0.0))
            loss = criterion(score, y_train) + 0.1 * cl
            optimizer.zero_grad(); loss.backward(); optimizer.step()

            if (epoch + 1) % 50 == 0 or epoch == epochs - 1:
                model.eval()
                with torch.no_grad():
                    _, ts, _ = model(
                        drdr_graph, didi_graph, drdipr_graph,
                        drug_feat, disease_feat, protein_feat, x_test,
                        drug_topo_feat=drug_topo, disease_topo_feat=disease_topo,
                        return_diagnostics=True,
                    )
                prob = F.softmax(ts, dim=-1)[:, 1].cpu().numpy()
                pred = torch.argmax(ts, dim=-1).cpu().numpy()
                auc, *_ = get_metric(y_test, pred, prob)
                scheduler.step(auc)
                elapsed = timeit.default_timer() - t0
                print(f"  Epoch {epoch+1:4d} | AUC={auc:.4f} | loss={loss.item():.4f} | {elapsed:.1f}s")

                if auc > best_auc:
                    best_auc = auc
                    no_improve = 0
                    if auc > best_overall_auc:
                        best_overall_auc = auc
                        best_state = {k: v.cpu().clone() for k, v in model.state_dict().items()}
                else:
                    no_improve += 50

                if no_improve >= 250:
                    print(f"  Early stop at epoch {epoch+1}")
                    break

        aucs.append(best_auc)
        print(f"  Fold {fold} best AUC: {best_auc:.4f}")
        del model, optimizer, scheduler, drdipr_graph
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()

    print(f"\nImproved | Mean AUC: {np.mean(aucs):.4f} ± {np.std(aucs):.4f}")

    out_dir = OUTPUT / 'improved' / dataset
    out_dir.mkdir(parents=True, exist_ok=True)
    ckpt_path = out_dir / 'best_model.pth'
    torch.save({'model_state_dict': best_state, 'auc': best_overall_auc,
                'dataset': dataset, 'model_version': 'improved'}, ckpt_path)
    print(f"Saved: {ckpt_path}")
    return ckpt_path


# ── Main ──────────────────────────────────────────────────
if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--dataset', default='all',
                        choices=['B-dataset', 'C-dataset', 'F-dataset', 'all'])
    parser.add_argument('--model', default='both',
                        choices=['original', 'improved', 'both'])
    parser.add_argument('--epochs', type=int, default=1000)
    parser.add_argument('--k_fold', type=int, default=10)
    parser.add_argument('--device', default='auto')
    args = parser.parse_args()

    if args.device == 'auto':
        device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
    else:
        device = torch.device(args.device)
    print(f"Device: {device}")

    datasets = ['B-dataset', 'C-dataset', 'F-dataset'] if args.dataset == 'all' else [args.dataset]

    for ds in datasets:
        if args.model in ('original', 'both'):
            train_original(ds, args.epochs, args.k_fold)
        if args.model in ('improved', 'both'):
            train_improved(ds, args.epochs, args.k_fold)

    print("\n" + "="*60)
    print("TRAINING COMPLETE!")
    print(f"Checkpoints saved to: {OUTPUT}")
    print("="*60)
    print("\nSau khi download ve, dat vao:")
    print("  Result/original/<dataset>/best_model.pth")
    print("  Result/improved/<dataset>/best_model.pth")
