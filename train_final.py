import argparse
import gc
import logging
import os
import random
import timeit
import warnings
from contextlib import nullcontext
from pathlib import Path

# DGL 2.4 still calls the deprecated ``torch.cuda.amp.autocast`` API in its
# sparse backend; with --amp enabled this prints two FutureWarnings on every
# forward pass, flooding the training log. Silence only those so that real
# warnings from other sources stay visible.
warnings.filterwarnings(
    "ignore",
    category=FutureWarning,
    message=r".*torch\.cuda\.amp\.autocast.*",
)

import numpy as np
import pandas as pd
import torch
import torch.nn as nn
import torch.nn.functional as fn
import torch.optim as optim
from torch.optim.lr_scheduler import CosineAnnealingLR, ReduceLROnPlateau

from data_preprocess_improved import (
    dgl_heterograph,
    dgl_similarity_graph,
    dgl_similarity_view_graphs,
    data_processing,
    filter_positive_pairs,
    get_data,
    k_fold,
    resample_fold_negatives,
)
from metric import get_metric
from model.improved.improved_model import AMNTDDA
from model.improved.training_utils import (
    FocalLoss,
    ModelEMA,
    apply_warmup_lr,
    compute_focal_gamma,
    get_adamw_param_groups,
    ranking_loss,
)
from topology_features import extract_topology_features


REQUIRED_DATA_FILES = [
    "DrugFingerprint.csv",
    "DrugGIP.csv",
    "DiseasePS.csv",
    "DiseaseGIP.csv",
    "DrugDiseaseAssociationNumber.csv",
    "DrugProteinAssociationNumber.csv",
    "ProteinDiseaseAssociationNumber.csv",
    "Drug_mol2vec.csv",
    "DiseaseFeature.csv",
    "Protein_ESM.csv",
]

DATASET_PRESETS = {
    "B-dataset": {
        "lr": 1e-4,
        "weight_decay": 1e-3,
        "neighbor": 3,
        "gt_out_dim": 512,
        "hgt_layer": 2,
        "hgt_in_dim": 512,
        "hgt_head_dim": 64,
        "topo_hidden": 192,
    },
    "C-dataset": {
        "lr": 1e-4,
        "weight_decay": 1e-3,
        "neighbor": 5,
        "gt_out_dim": 256,
        "hgt_layer": 2,
        "hgt_in_dim": 256,
        "hgt_head_dim": 32,
        "topo_hidden": 128,
    },
    "F-dataset": {
        "lr": 8e-5,
        "weight_decay": 1e-3,
        "neighbor": 10,
        "gt_out_dim": 384,
        "hgt_layer": 3,
        "hgt_in_dim": 384,
        "hgt_head_dim": 48,
        "topo_hidden": 192,
        "similarity_view_mode": "multi",
        "positive_weight_mode": "global_log",
    },
}


def resolve_amp_dtype(amp_arg, device):
    """Resolve the mixed-precision dtype given the CLI flag and GPU capability.

    Returns ``None`` to disable AMP entirely. Otherwise returns the torch dtype
    to use inside ``torch.autocast``. BFloat16 requires Ampere or newer (CC>=8.0);
    Turing (T4/V100) only has fp16 tensor cores, so 'auto' falls back to fp16 there.
    """
    if amp_arg == "none":
        return None
    if device.type != "cuda":
        return None
    if amp_arg == "bfloat16":
        return torch.bfloat16
    if amp_arg == "float16":
        return torch.float16
    # auto
    major, _ = torch.cuda.get_device_capability(device)
    return torch.bfloat16 if major >= 8 else torch.float16


def resolve_device(device_name):
    if device_name == "auto":
        device_name = os.environ.get("AMDGT_DEVICE", "cuda" if torch.cuda.is_available() else "cpu")
    if device_name == "cuda" and not torch.cuda.is_available():
        print("CUDA requested but unavailable. Falling back to CPU.")
        device_name = "cpu"
    return torch.device(device_name)


def set_random_seed(seed):
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    if torch.cuda.is_available():
        torch.cuda.manual_seed(seed)
        torch.cuda.manual_seed_all(seed)
    torch.backends.cudnn.deterministic = True
    torch.backends.cudnn.benchmark = False
    try:
        import dgl

        dgl.seed(seed)
    except Exception:
        pass


def validate_data_dir(data_dir):
    missing = [name for name in REQUIRED_DATA_FILES if not os.path.exists(os.path.join(data_dir, name))]
    if missing:
        raise FileNotFoundError(f"Missing dataset files in {data_dir}: {', '.join(missing)}")


def apply_dataset_preset(args):
    preset = DATASET_PRESETS.get(args.dataset, {})
    for key, value in preset.items():
        if getattr(args, key, None) is None:
            setattr(args, key, value)

    if args.similarity_view_mode is None:
        args.similarity_view_mode = "consensus"
    if args.positive_weight_mode is None:
        args.positive_weight_mode = "none"
    if args.hgt_head_dim is None:
        args.hgt_head_dim = max(1, args.gt_out_dim // args.hgt_head)
    args.hgt_out_dim = args.gt_out_dim
    args.topo_feat_dim = 7


def build_class_weights(args, data, y_train, device):
    mode = getattr(args, "positive_weight_mode", "none")
    if mode == "none":
        return None, 1.0

    total_pairs = max(1, int(args.drug_number * args.disease_number))
    global_pos = max(1, int(len(data["drdi"])))
    global_neg = max(1, total_pairs - global_pos)
    sampled_pos = max(1, int((y_train == 1).sum().item()))
    sampled_neg = max(1, int((y_train == 0).sum().item()))

    if mode == "sampled":
        pos_weight = sampled_neg / sampled_pos
    elif mode == "global_linear":
        pos_weight = global_neg / global_pos
    elif mode == "global_sqrt":
        pos_weight = float(np.sqrt(global_neg / global_pos))
    elif mode == "global_log":
        pos_weight = float(np.log1p(global_neg / global_pos))
    else:
        raise ValueError(f"Unsupported positive_weight_mode: {mode}")

    pos_weight = max(1.0, float(pos_weight))
    class_weights = torch.tensor([1.0, pos_weight], dtype=torch.float32, device=device)
    class_weights = class_weights / class_weights.mean()
    return class_weights, pos_weight


def build_model_tag(args):
    return f"{args.assoc_backbone}_{args.fusion_mode}_{args.pair_mode}_{args.gate_mode}"


def save_checkpoint(path, model, optimizer, scheduler, fold_idx, epoch, best_metrics, args):
    checkpoint = {
        "fold": fold_idx,
        "epoch": epoch,
        "model_state_dict": model.state_dict(),
        "optimizer_state_dict": optimizer.state_dict(),
        "best_metrics": best_metrics,
        "args": vars(args),
    }
    if scheduler is not None:
        checkpoint["scheduler_state_dict"] = scheduler.state_dict()
    torch.save(checkpoint, path)


class _SafeStreamHandler(logging.StreamHandler):
    """StreamHandler that swallows transient stdout/stderr I/O errors.

    On Kaggle/Colab, when the browser tab disconnects or the kernel reattaches,
    sys.stdout can briefly raise ``OSError: [Errno 5] Input/output error`` or
    ``BrokenPipeError`` during ``flush``. The default StreamHandler treats those
    as logging errors and prints noisy tracebacks; in long training loops this
    spam can also push the kernel into an unhealthy state and Kaggle may kill
    the process. Silently dropping the affected record keeps the FileHandler
    (which writes to disk) running and lets training continue uninterrupted.
    """

    def emit(self, record):
        try:
            super().emit(record)
        except (OSError, ValueError):
            pass

    def flush(self):
        try:
            super().flush()
        except (OSError, ValueError):
            pass


class _SafeFileHandler(logging.FileHandler):
    """FileHandler that survives transient disk flush errors on hosted notebooks."""

    def emit(self, record):
        try:
            super().emit(record)
        except (OSError, ValueError):
            pass

    def flush(self):
        try:
            super().flush()
        except (OSError, ValueError):
            pass


def configure_logging(result_dir):
    log_file = Path(result_dir) / "training_log.txt"
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s - %(message)s",
        handlers=[
            _SafeFileHandler(log_file, mode="a", encoding="utf-8"),
            _SafeStreamHandler(),
        ],
        force=True,
    )
    # Belt and suspenders: silence the logging module's own error reporter so a
    # broken stdout cannot crash the training loop via repeated tracebacks.
    logging.raiseExceptions = False
    return log_file


def build_results_dataframe(best_epochs, aucs, auprs, accs, precs, recs, f1s, mccs):
    results_df = pd.DataFrame(
        {
            "Fold": [f"Fold {i}" for i in range(len(aucs))],
            "Best_Epoch": best_epochs,
            "AUC": aucs,
            "AUPR": auprs,
            "Accuracy": accs,
            "Precision": precs,
            "Recall": recs,
            "F1-score": f1s,
            "Mcc": mccs,
        }
    )

    metrics_only = results_df.drop(columns=["Fold", "Best_Epoch"])
    summary_df = pd.DataFrame(
        [
            ["Mean", ""] + metrics_only.mean().tolist(),
            ["Std", ""] + metrics_only.std().tolist(),
        ],
        columns=results_df.columns,
    )
    return pd.concat([results_df, summary_df], ignore_index=True)


def build_epoch_metric_header():
    columns = ["Epoch", "Time", "AUC", "AUPR", "Accuracy", "Precision", "Recall", "F1-score", "Mcc"]
    return " ".join(f"{column:>10}" for column in columns)


def format_epoch_metric_row(epoch, elapsed, auc, aupr, accuracy, precision, recall, f1, mcc):
    values = [
        epoch,
        f"{elapsed:.2f}",
        f"{auc:.5f}",
        f"{aupr:.5f}",
        f"{accuracy:.5f}",
        f"{precision:.5f}",
        f"{recall:.5f}",
        f"{f1:.5f}",
        f"{mcc:.5f}",
    ]
    return " ".join(f"{value:>10}" for value in values)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--k_fold", type=int, default=10, help="k-fold cross validation")
    parser.add_argument("--epochs", type=int, default=1000, help="number of epochs to train")
    parser.add_argument("--lr", type=float, default=None, help="learning rate")
    parser.add_argument("--weight_decay", type=float, default=None, help="weight decay")
    parser.add_argument("--random_seed", type=int, default=1234, help="random seed")
    parser.add_argument("--neighbor", type=int, default=None, help="k for similarity knn graphs")
    parser.add_argument("--negative_rate", type=float, default=1.0, help="negative sampling rate")
    parser.add_argument("--dataset", default="C-dataset", help="dataset")
    parser.add_argument("--dropout", default=0.2, type=float, help="dropout")
    parser.add_argument("--device", default="auto", choices=["auto", "cpu", "cuda"], help="training device")
    parser.add_argument("--data_root", default=None, help="dataset directory; defaults to AMDGT/data/<dataset>")
    parser.add_argument("--result_root", default=None, help="output directory; defaults to Result/improved/<dataset>")
    parser.add_argument("--save_checkpoints", action=argparse.BooleanOptionalAction, default=False, help="save model checkpoints")
    parser.add_argument("--eval_start_epoch", default=1, type=int, help="minimum epoch before evaluation begins")
    parser.add_argument("--score_every", default=1, type=int, help="evaluate every N epochs after eval_start_epoch")
    parser.add_argument("--patience", default=180, type=int, help="early stopping patience in epochs without AUC improvement; <=0 disables early stopping")
    parser.add_argument("--lambda_cl", default=0.1, type=float, help="weight for contrastive alignment loss")
    parser.add_argument("--temperature", default=0.5, type=float, help="temperature for contrastive loss")
    parser.add_argument("--cl_warmup_epochs", default=50, type=int, help="epochs to linearly ramp contrastive loss weight")
    parser.add_argument("--disable_scheduler", action="store_true", help="disable ReduceLROnPlateau scheduler")
    parser.add_argument("--topo_hidden", default=None, type=int, help="hidden dimension for topology encoder")
    parser.add_argument("--similarity_view_mode", choices=["consensus", "multi"], default=None, help="use only consensus similarity graph or fuse multiple similarity views")
    parser.add_argument("--positive_weight_mode", choices=["none", "sampled", "global_log", "global_sqrt", "global_linear"], default=None, help="positive-class weighting strategy for sparse datasets")
    parser.add_argument("--fold_limit", type=int, default=None, help="optional limit on number of folds to execute")
    parser.add_argument("--assoc_backbone", choices=["vanilla_hgt", "rlghgt"], default="vanilla_hgt", help="association encoder backbone")
    parser.add_argument("--fusion_mode", choices=["mva", "rvg", "mva_fuzzy"], default="mva", help="node-view fusion strategy")
    parser.add_argument("--pair_mode", choices=["mul_mlp", "interaction"], default="mul_mlp", help="pair scoring head")
    parser.add_argument("--gate_mode", choices=["scalar", "vector"], default="vector", help="fuzzy gate output type")
    parser.add_argument("--gate_bias_init", default=-2.0, type=float, help="initial bias for fuzzy gate")
    parser.add_argument("--grad_clip", default=1.0, type=float, help="max gradient norm; <=0 disables clipping")
    parser.add_argument("--optimizer", choices=["adam", "adamw"], default="adamw", help="optimizer (adamw uses decoupled weight decay)")
    parser.add_argument("--scheduler", choices=["plateau", "cosine"], default="plateau", help="LR scheduler after warmup")
    parser.add_argument("--use_ema", action=argparse.BooleanOptionalAction, default=True, help="maintain EMA of weights for evaluation")
    parser.add_argument("--use_focal", action=argparse.BooleanOptionalAction, default=True, help="use focal loss instead of plain CE")
    parser.add_argument("--use_ranking", action=argparse.BooleanOptionalAction, default=True, help="add BPR-style pairwise ranking loss")
    parser.add_argument("--hard_neg_ratio", default=0.3, type=float, help="fraction of ranking negatives drawn as hard negatives")
    parser.add_argument("--neg_resample_every", default=0, type=int, help="resample training negatives every N epochs (0 disables dynamic negatives)")
    parser.add_argument("--filter_assoc_positives_only", action=argparse.BooleanOptionalAction, default=True, help="build drdipr heterograph using training positives only (fixes BUG-09)")
    parser.add_argument("--use_relation_attention", action=argparse.BooleanOptionalAction, default=True, help="enable relation-aware attention in RLGHGT")
    parser.add_argument("--use_metapath", action=argparse.BooleanOptionalAction, default=True, help="enable metapath branch in RLGHGT")
    parser.add_argument("--use_global_hgt", action=argparse.BooleanOptionalAction, default=True, help="enable global context branch in RLGHGT")
    parser.add_argument("--use_topological", action=argparse.BooleanOptionalAction, default=True, help="enable topology projection branch in RLGHGT")

    parser.add_argument("--hgt_in_dim", default=None, type=int, help="HGT input dimension")
    parser.add_argument("--hgt_layer", default=None, type=int, help="HGT layers")
    parser.add_argument("--hgt_head", default=8, type=int, help="HGT heads")
    parser.add_argument("--hgt_head_dim", default=None, type=int, help="HGT per-head output dimension")
    parser.add_argument("--hgt_out_dim", default=None, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--gt_layer", default=2, type=int, help="GT layers")
    parser.add_argument("--gt_head", default=2, type=int, help="GT heads")
    parser.add_argument("--gt_out_dim", default=None, type=int, help="GT output dimension")
    parser.add_argument("--tr_layer", default=2, type=int, help="Transformer layers")
    parser.add_argument("--tr_head", default=4, type=int, help="Transformer heads")

    parser.add_argument("--min_lr", default=1e-6, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--lr_warmup_epochs", default=40, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--warmup_epochs", default=220, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--log_every", default=25, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--focal_gamma", default=1.2, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--focal_gamma_warm", default=2.0, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--contrastive_weight", default=0.08, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--contrastive_temperature", default=0.20, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--ranking_weight", default=0.08, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--ranking_margin", default=0.20, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--ranking_samples", default=2048, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--hard_negative_weight", default=1.2, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--label_smoothing", default=0.0, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--target_auc", default=0.96, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--target_auc_warmup", default=400, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--target_auc_patience", default=4, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--plateau_patience", default=30, type=int, help=argparse.SUPPRESS)
    parser.add_argument("--plateau_factor", default=0.5, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--ema_decay", default=0.995, type=float, help=argparse.SUPPRESS)
    parser.add_argument("--ema_warmup_epochs", default=80, type=int, help="epochs of plain training before EMA starts tracking; avoids random-init bias leaking into eval weights")
    parser.add_argument(
        "--amp",
        choices=["none", "auto", "bfloat16", "float16"],
        default="none",
        help=(
            "automatic mixed precision dtype for forward/backward. Default 'none' keeps "
            "fp32 since this model loses ~0.02-0.03 AUC under fp16. Use 'bfloat16' on "
            "Ampere+ (L4/A100) for ~1.4-1.8x speedup with minimal AUC drop, 'float16' for "
            "max speed on T4/V100 at the cost of ~0.02-0.03 AUC, or 'auto' to pick per GPU."
        ),
    )

    import sys as _sys
    _explicit = set()
    for _arg in _sys.argv[1:]:
        if _arg.startswith("--"):
            _key = _arg.split("=", 1)[0].lstrip("-").replace("-", "_")
            _explicit.add(_key)

    args = parser.parse_args()
    apply_dataset_preset(args)
    if args.fusion_mode == "mva_fuzzy":
        args.fusion_mode = "rvg"

    # Consolidate deprecated contrastive params. Canonical flags are
    # --contrastive_weight / --contrastive_temperature; legacy --lambda_cl /
    # --temperature override them only when the user passes them explicitly.
    if "lambda_cl" in _explicit:
        args.contrastive_weight = float(args.lambda_cl)
    else:
        args.lambda_cl = float(args.contrastive_weight)
    if "temperature" in _explicit:
        args.contrastive_temperature = float(args.temperature)
    else:
        args.temperature = float(args.contrastive_temperature)

    device = resolve_device(args.device)
    args.device = device
    os.environ["AMDGT_DEVICE"] = device.type
    set_random_seed(args.random_seed)

    default_data_dir = Path("AMDGT") / "data" / args.dataset
    default_result_dir = Path("Result") / "improved" / args.dataset
    args.data_dir = str(Path(args.data_root) if args.data_root else default_data_dir)
    args.result_dir = str(Path(args.result_root) if args.result_root else default_result_dir)
    args.model_tag = build_model_tag(args)

    validate_data_dir(args.data_dir)
    os.makedirs(args.result_dir, exist_ok=True)
    log_file = configure_logging(args.result_dir)

    logging.info("--- Starting Final Improved Pipeline ---")
    logging.info(f"Dataset: {args.dataset} | LR: {args.lr} | GT dim: {args.gt_out_dim} | Neighbor: {args.neighbor}")
    logging.info(f"Device: {device} | Data dir: {args.data_dir} | Result dir: {args.result_dir}")
    logging.info(f"Save checkpoints: {args.save_checkpoints}")
    if args.patience > 0:
        logging.info(f"Early stopping patience: {args.patience}")
    else:
        logging.info("Early stopping: disabled")
    logging.info(
        f"Contrastive: weight={args.contrastive_weight:.4g} | temperature={args.contrastive_temperature:.4g}"
    )
    logging.info(
        f"Loss mix: focal={args.use_focal} (gamma warm->{args.focal_gamma_warm}->{args.focal_gamma}) | "
        f"ranking={args.use_ranking} (w={args.ranking_weight}, margin={args.ranking_margin}, hard_ratio={args.hard_neg_ratio})"
    )
    logging.info(
        f"Optimizer: {args.optimizer} | scheduler: {args.scheduler} | "
        f"warmup_epochs={args.lr_warmup_epochs} | plateau_patience={args.plateau_patience} | "
        f"plateau_factor={args.plateau_factor} | min_lr={args.min_lr} | grad_clip={args.grad_clip}"
    )
    logging.info(
        f"EMA: enabled={args.use_ema} (decay={args.ema_decay}, "
        f"warmup_epochs={args.ema_warmup_epochs}) | "
        f"neg_resample_every={args.neg_resample_every} | "
        f"filter_assoc_positives_only={args.filter_assoc_positives_only}"
    )
    logging.info(f"Similarity view mode: {args.similarity_view_mode} | Positive weight mode: {args.positive_weight_mode}")
    logging.info(
        "Model config: "
        f"tag={args.model_tag} | assoc={args.assoc_backbone} | fusion={args.fusion_mode} | "
        f"pair={args.pair_mode} | gate={args.gate_mode}"
    )
    logging.info(f"Training log file: {log_file}")

    amp_dtype = resolve_amp_dtype(args.amp, device)
    if amp_dtype is None:
        logging.info("AMP: disabled (fp32)")
    else:
        gpu_name = torch.cuda.get_device_name(device) if device.type == "cuda" else device.type
        logging.info(f"AMP: enabled (dtype={str(amp_dtype).rsplit('.', 1)[-1]}, device={gpu_name})")

    def autocast_ctx():
        return torch.autocast(device_type=device.type, dtype=amp_dtype) if amp_dtype is not None else nullcontext()

    data = get_data(args)
    args.drug_number = data["drug_number"]
    args.disease_number = data["disease_number"]
    args.protein_number = data["protein_number"]
    logging.info(f"Loaded data: {args.drug_number} drugs, {args.disease_number} diseases, {args.protein_number} proteins")

    data = data_processing(data, args)
    data = k_fold(data, args)

    if args.similarity_view_mode == "multi":
        drdr_graph, didi_graph, data = dgl_similarity_view_graphs(data, args)
        drdr_graph = {name: graph.to(device) for name, graph in drdr_graph.items()}
        didi_graph = {name: graph.to(device) for name, graph in didi_graph.items()}
        logging.info(f"Drug similarity views: {list(drdr_graph.keys())}")
        logging.info(f"Disease similarity views: {list(didi_graph.keys())}")
    else:
        drdr_graph, didi_graph, data = dgl_similarity_graph(data, args)
        drdr_graph = drdr_graph.to(device)
        didi_graph = didi_graph.to(device)

    logging.info("Extracting topology features...")
    drug_topo_feat, disease_topo_feat = extract_topology_features(data, args)
    drug_topo_feat = drug_topo_feat.to(device)
    disease_topo_feat = disease_topo_feat.to(device)
    logging.info(f"Drug topology features: {tuple(drug_topo_feat.shape)}")
    logging.info(f"Disease topology features: {tuple(disease_topo_feat.shape)}")

    drug_feature = torch.tensor(data["drugfeature"], dtype=torch.float32).to(device)
    disease_feature = torch.tensor(data["diseasefeature"], dtype=torch.float32).to(device)
    protein_feature = torch.tensor(data["proteinfeature"], dtype=torch.float32).to(device)

    metric_header = build_epoch_metric_header()
    logging.info(metric_header)

    aucs, auprs, accs, precs, recs, f1s, mccs, best_epochs = [], [], [], [], [], [], [], []
    best_overall_auc = -1.0
    best_overall_path = None
    best_overall_payload = None

    max_folds = args.k_fold if args.fold_limit is None else min(args.k_fold, args.fold_limit)

    for fold_idx in range(max_folds):
        logging.info(f'\n{"=" * 60}')
        logging.info(f"Fold: {fold_idx}")
        logging.info(f'{"=" * 60}')
        logging.info(metric_header)

        model = AMNTDDA(args).to(device)
        if args.optimizer == "adamw":
            param_groups = get_adamw_param_groups(model, args.weight_decay)
            optimizer = optim.AdamW(param_groups, lr=args.lr)
        else:
            optimizer = optim.Adam(model.parameters(), weight_decay=args.weight_decay, lr=args.lr)
        base_lrs = [pg["lr"] for pg in optimizer.param_groups]

        if args.disable_scheduler:
            scheduler = None
        elif args.scheduler == "cosine":
            remaining = max(1, args.epochs - args.lr_warmup_epochs)
            scheduler = CosineAnnealingLR(optimizer, T_max=remaining, eta_min=args.min_lr)
        else:
            scheduler = ReduceLROnPlateau(
                optimizer,
                mode="max",
                factor=args.plateau_factor,
                patience=args.plateau_patience,
                min_lr=args.min_lr,
            )

        x_train_np = np.asarray(data["X_train"][fold_idx])
        y_train_np = np.asarray(data["Y_train"][fold_idx]).reshape(-1).astype(int)
        x_train = torch.tensor(x_train_np, dtype=torch.long, device=device)
        y_train = torch.tensor(y_train_np, dtype=torch.long, device=device).flatten()
        x_test = torch.tensor(data["X_test"][fold_idx], dtype=torch.long, device=device)
        y_test = data["Y_test"][fold_idx].flatten()
        class_weights, pos_weight = build_class_weights(args, data, y_train, device)
        if args.use_focal:
            criterion = FocalLoss(
                gamma=args.focal_gamma_warm,
                weight=class_weights,
                label_smoothing=max(0.0, float(args.label_smoothing)),
            )
        else:
            criterion = nn.CrossEntropyLoss(
                weight=class_weights,
                label_smoothing=max(0.0, float(args.label_smoothing)),
            )
        logging.info(
            f"Fold {fold_idx} loss config: pos_weight_mode={args.positive_weight_mode}, "
            f"raw_positive_weight={pos_weight:.4f}, label_smoothing={float(args.label_smoothing):.4f}"
        )

        # BUG-09 fix: only use TRAIN POSITIVES as ('drug','association','disease')
        # edges of the HGT heterograph. Negatives were previously being injected
        # as real associations and polluting the association representation.
        if args.filter_assoc_positives_only:
            pos_pairs_fold = filter_positive_pairs(x_train_np, y_train_np)
        else:
            pos_pairs_fold = x_train_np
        drdipr_graph, _ = dgl_heterograph(data, pos_pairs_fold, args)
        drdipr_graph = drdipr_graph.to(device)
        logging.info(
            f"Fold {fold_idx} drdipr_graph: n_train_pairs={len(x_train_np)} | "
            f"edges_drug_disease={int(pos_pairs_fold.shape[0])} (positives_only={args.filter_assoc_positives_only})"
        )

        # EMA is lazily instantiated at --ema_warmup_epochs so that the shadow
        # weights are seeded from already-trained parameters (not random init).
        # With full-batch training (1 update/epoch) and decay=0.995, eager
        # init would leave EMA dominated by random weights for ~200 epochs and
        # drag eval AUC below random. See training log "EMA: ..." line.
        ema = None
        neg_rng = np.random.default_rng(args.random_seed + fold_idx * 9973)

        # Per-fold GradScaler. A single shared scaler across folds carries scale
        # state and a step counter tied to the *previous* fold's optimizer; when
        # Fold 1 attaches a fresh optimizer, scaler.step() can silently skip every
        # update, collapsing predictions to a single class (observed as AUC~0.38,
        # accuracy~0.5, precision/recall=0).
        scaler = torch.amp.GradScaler(device.type) if amp_dtype == torch.float16 else None

        best_fold = {
            "AUC": -1.0,
            "AUPR": 0.0,
            "Accuracy": 0.0,
            "Precision": 0.0,
            "Recall": 0.0,
            "F1": 0.0,
            "MCC": 0.0,
            "epoch": 0,
        }
        no_improve_epochs = 0
        start = timeit.default_timer()

        for epoch in range(args.epochs):
            apply_warmup_lr(optimizer, base_lrs, epoch, args.lr_warmup_epochs)

            if (
                args.neg_resample_every > 0
                and epoch > 0
                and epoch % args.neg_resample_every == 0
            ):
                new_x, new_y = resample_fold_negatives(data, fold_idx, neg_rng)
                if new_x is not None:
                    x_train_np = new_x
                    y_train_np = new_y.reshape(-1).astype(int)
                    x_train = torch.tensor(x_train_np, dtype=torch.long, device=device)
                    y_train = torch.tensor(y_train_np, dtype=torch.long, device=device).flatten()
                    class_weights, pos_weight = build_class_weights(args, data, y_train, device)
                    if isinstance(criterion, FocalLoss):
                        criterion = FocalLoss(
                            gamma=criterion.gamma,
                            weight=class_weights,
                            label_smoothing=max(0.0, float(args.label_smoothing)),
                        )
                    else:
                        criterion = nn.CrossEntropyLoss(
                            weight=class_weights,
                            label_smoothing=max(0.0, float(args.label_smoothing)),
                        )

            if args.use_focal and isinstance(criterion, FocalLoss):
                criterion.set_gamma(
                    compute_focal_gamma(
                        epoch,
                        max(1, args.warmup_epochs),
                        args.focal_gamma_warm,
                        args.focal_gamma,
                    )
                )

            model.train()
            with autocast_ctx():
                _, train_score, aux_losses = model(
                    drdr_graph,
                    didi_graph,
                    drdipr_graph,
                    drug_feature,
                    disease_feature,
                    protein_feature,
                    x_train,
                    drug_topo_feat=drug_topo_feat,
                    disease_topo_feat=disease_topo_feat,
                    return_aux=True,
                )
                contrastive_loss = aux_losses.get("contrastive", train_score.new_tensor(0.0))
                ce_loss = criterion(train_score, y_train)

                if args.use_ranking and args.ranking_weight > 0:
                    ranking_term = ranking_loss(
                        train_score,
                        y_train,
                        margin=args.ranking_margin,
                        num_samples=args.ranking_samples,
                        hard_weight=args.hard_negative_weight,
                        hard_ratio=args.hard_neg_ratio,
                    )
                else:
                    ranking_term = train_score.new_tensor(0.0)

                train_loss = (
                    ce_loss
                    + args.contrastive_weight * contrastive_loss
                    + args.ranking_weight * ranking_term
                )

            optimizer.zero_grad()
            if scaler is not None:
                scaler.scale(train_loss).backward()
                if args.grad_clip > 0:
                    scaler.unscale_(optimizer)
                    nn.utils.clip_grad_norm_(model.parameters(), max_norm=args.grad_clip)
                scaler.step(optimizer)
                scaler.update()
            else:
                train_loss.backward()
                if args.grad_clip > 0:
                    nn.utils.clip_grad_norm_(model.parameters(), max_norm=args.grad_clip)
                optimizer.step()
            # Lazy-init EMA once base model is past --ema_warmup_epochs so the
            # shadow snapshot is seeded from trained weights (not random init).
            if args.use_ema and ema is None and (epoch + 1) >= args.ema_warmup_epochs:
                ema = ModelEMA(model, decay=args.ema_decay)
            if ema is not None:
                ema.update(model)

            should_eval = (epoch + 1) >= args.eval_start_epoch and ((epoch + 1 - args.eval_start_epoch) % max(1, args.score_every) == 0)
            if should_eval or epoch == args.epochs - 1:
                if ema is not None:
                    ema.apply_to(model)
                model.eval()
                with torch.no_grad(), autocast_ctx():
                    _, test_score, diagnostics = model(
                        drdr_graph,
                        didi_graph,
                        drdipr_graph,
                        drug_feature,
                        disease_feature,
                        protein_feature,
                        x_test,
                        drug_topo_feat=drug_topo_feat,
                        disease_topo_feat=disease_topo_feat,
                        return_diagnostics=True,
                    )
                if ema is not None:
                    ema.restore(model)

                test_prob = fn.softmax(test_score, dim=-1)[:, 1].cpu().numpy()
                test_pred = torch.argmax(test_score, dim=-1).cpu().numpy()
                auc, aupr, accuracy, precision, recall, f1, mcc = get_metric(y_test, test_pred, test_prob)
                if scheduler is not None and epoch >= args.lr_warmup_epochs:
                    if isinstance(scheduler, ReduceLROnPlateau):
                        scheduler.step(auc)
                    else:
                        scheduler.step()

                elapsed = timeit.default_timer() - start
                logging.info(
                    format_epoch_metric_row(
                        epoch + 1,
                        elapsed,
                        float(auc),
                        float(aupr),
                        float(accuracy),
                        float(precision),
                        float(recall),
                        float(f1),
                        float(mcc),
                    )
                )

                if auc > best_fold["AUC"] + 1e-6:
                    best_fold.update(
                        {
                            "AUC": float(auc),
                            "AUPR": float(aupr),
                            "Accuracy": float(accuracy),
                            "Precision": float(precision),
                            "Recall": float(recall),
                            "F1": float(f1),
                            "MCC": float(mcc),
                            "epoch": epoch + 1,
                        }
                    )
                    no_improve_epochs = 0
                    if args.save_checkpoints:
                        checkpoint_path = os.path.join(args.result_dir, f"best_model_{args.model_tag}_fold{fold_idx}.pth")
                        save_checkpoint(checkpoint_path, model, optimizer, scheduler, fold_idx, epoch + 1, best_fold, args)
                        if auc > best_overall_auc:
                            best_overall_auc = float(auc)
                            best_overall_path = checkpoint_path
                            best_overall_payload = {
                                "model_state_dict": {k: v.detach().cpu().clone() for k, v in model.state_dict().items()},
                                "optimizer_state_dict": optimizer.state_dict(),
                                "best_metrics": best_fold.copy(),
                                "fold": fold_idx,
                                "epoch": epoch + 1,
                                "args": vars(args),
                            }
                            if scheduler is not None:
                                best_overall_payload["scheduler_state_dict"] = scheduler.state_dict()
                    else:
                        if auc > best_overall_auc:
                            best_overall_auc = float(auc)
                    logging.info(f"AUC improved at epoch {epoch + 1} ;\tbest_auc: {auc:.5f}")
                else:
                    no_improve_epochs += max(1, args.score_every)

                if args.patience > 0 and no_improve_epochs >= args.patience:
                    logging.info(
                        f"Early stopping at epoch {epoch + 1} after "
                        f"{no_improve_epochs} epochs without AUC improvement."
                    )
                    break
            else:
                if scheduler is not None and not isinstance(scheduler, ReduceLROnPlateau) and epoch >= args.lr_warmup_epochs:
                    scheduler.step()
                if (epoch + 1) % max(1, args.log_every) == 0:
                    elapsed = timeit.default_timer() - start
                    current_lr = optimizer.param_groups[0]["lr"]
                    logging.info(
                        f"Epoch {epoch + 1:4d} | {elapsed:7.2f}s | "
                        f"lr {current_lr:.1e} | loss {train_loss.item():.5f} | "
                        f"cls {ce_loss.item():.5f} | ctr {contrastive_loss.item():.5f} | "
                        f"rnk {float(ranking_term.item()):.5f}"
                    )

        aucs.append(best_fold["AUC"])
        auprs.append(best_fold["AUPR"])
        accs.append(best_fold["Accuracy"])
        precs.append(best_fold["Precision"])
        recs.append(best_fold["Recall"])
        f1s.append(best_fold["F1"])
        mccs.append(best_fold["MCC"])
        best_epochs.append(best_fold["epoch"])
        logging.info(f"Fold {fold_idx} completed: best_epoch={best_fold['epoch']}, best_auc={best_fold['AUC']:.5f}, best_aupr={best_fold['AUPR']:.5f}")

        del model, optimizer, scheduler, drdipr_graph
        if ema is not None:
            del ema
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()

    final_df = build_results_dataframe(best_epochs, aucs, auprs, accs, precs, recs, f1s, mccs)

    output_csv = os.path.join(args.result_dir, f"{max_folds}_fold_results_{args.model_tag}.csv")
    final_df.to_csv(output_csv, index=False)
    logging.info(f'\n{"=" * 60}')
    logging.info("FINAL RESULTS SUMMARY (REFERENCE-ALIGNED PIPELINE)")
    logging.info(f'{"=" * 60}')
    logging.info(f"\n{final_df.to_string(index=False)}")
    logging.info(f"AUC: {aucs}")
    logging.info(f"Mean AUC: {float(np.mean(aucs)):.5f} (+/- {float(np.std(aucs)):.5f})")
    logging.info(f"AUPR: {auprs}")
    logging.info(f"Mean AUPR: {float(np.mean(auprs)):.5f} (+/- {float(np.std(auprs)):.5f})")
    logging.info(f"Saved improved results to: {output_csv}")

    if args.save_checkpoints and best_overall_payload is not None:
        overall_path = os.path.join(args.result_dir, f"best_model_{args.model_tag}.pth")
        torch.save(best_overall_payload, overall_path)
        logging.info(f"Saved best overall checkpoint to: {overall_path}")
