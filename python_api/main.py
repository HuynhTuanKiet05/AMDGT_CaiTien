from __future__ import annotations

import csv
import json
import os
import re
import sys
from pathlib import Path
from typing import Dict, List, Tuple

import numpy as np
import pandas as pd
import torch
import torch.nn.functional as fn
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

BASE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = BASE_DIR.parent
sys.path.append(str(PROJECT_ROOT))

from model.AMNTDDA import AMNTDDA
from model.improved.improved_model import AMNTDDA as ImprovedAMNTDDA
from AMDGT_original.model.AMNTDDA import AMNTDDA as OriginalAMNTDDA
from AMDGT_original import data_preprocess as original_preprocess
import data_preprocess_improved as improved_preprocess
from topology_features import extract_topology_features

if os.environ.get('HGT_MODEL_VERSION', 'improved') == 'improved':
    from data_preprocess_improved import dgl_similarity_graph, dgl_heterograph
else:
    from AMDGT_original.data_preprocess import dgl_similarity_graph, dgl_heterograph

from fastapi.middleware.cors import CORSMiddleware

app = FastAPI(title='HGT Drug-Disease Prediction API', version='2.0.0')
app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])


import traceback
from fastapi.responses import JSONResponse
from fastapi import FastAPI, HTTPException, Request

@app.exception_handler(Exception)
async def _global_exc_handler(request: Request, exc: Exception):
    tb = traceback.format_exc()
    print(f"[ERROR] Unhandled on {request.url.path}:\n{tb}")
    return JSONResponse(status_code=500, content={"detail": str(exc), "traceback": tb})


device = torch.device(os.environ.get('AMDGT_DEVICE', 'cuda' if torch.cuda.is_available() else 'cpu'))
print(f"Using device: {device}")


class PredictRequest(BaseModel):
    query_type: str = Field(pattern='^(drug_to_disease|disease_to_drug)$')
    input_text: str
    top_k: int = 10
    dataset: str = 'C-dataset'



DATASET_PRESETS = {
    'B-dataset': {
        'neighbor': 3,
        'gt_out_dim': 512,
        'hgt_in_dim': 512,
        'hgt_layer': 2,
        'hgt_head': 8,
        'hgt_head_dim': 64,
        'topo_hidden': 192,
    },
    'C-dataset': {
        'neighbor': 5,
        'gt_out_dim': 256,
        'hgt_in_dim': 256,
        'hgt_layer': 2,
        'hgt_head': 8,
        'hgt_head_dim': 32,
        'topo_hidden': 128,
    },
    'F-dataset': {
        'neighbor': 10,
        'gt_out_dim': 384,
        'hgt_in_dim': 384,
        'hgt_layer': 3,
        'hgt_head': 8,
        'hgt_head_dim': 48,
        'topo_hidden': 192,
    },
}


class InferenceManager:
    def __init__(self):
        self.cached_models: Dict[str, torch.nn.Module] = {}
        self.cached_data: Dict[str, Dict[str, object]] = {}
        self.disease_name_cache = self.load_json_map(PROJECT_ROOT / 'scripts' / 'cache' / 'disease_name_map.json')

    def get_dataset_paths(self, dataset_name: str):
        data_dir = PROJECT_ROOT / 'AMDGT_original' / 'data' / dataset_name
        model_path = self.resolve_model_path(dataset_name)
        return data_dir, model_path

    def resolve_model_path(self, dataset_name: str) -> Path | None:
        dataset_variants = {dataset_name, dataset_name.replace('-', '_'), dataset_name.replace('_', '-')}
        candidate_roots = []
        for variant in dataset_variants:
            candidate_roots.extend([
                PROJECT_ROOT / 'Result' / 'improved' / variant,
                PROJECT_ROOT / 'Result' / variant,
            ])

        candidates: List[Path] = []
        for root in candidate_roots:
            if not root.exists():
                continue
            candidates.extend(root.rglob('best_model*.pth'))

        if not candidates:
            legacy_path = PROJECT_ROOT / 'Result' / dataset_name / 'AMNTDDA' / 'best_model.pth'
            return legacy_path if legacy_path.exists() else None

        candidates.sort(key=lambda path: ('fold' in path.stem.lower(), -path.stat().st_mtime))
        return candidates[0]

    def resolve_checkpoint_path(self, dataset_name: str, model_version: str) -> Path | None:
        if model_version not in {'original', 'improved'}:
            raise ValueError(f"Unsupported model version: {model_version}")

        dataset_variants = {dataset_name, dataset_name.replace('-', '_'), dataset_name.replace('_', '-')}
        candidate_roots = []
        for variant in dataset_variants:
            if model_version == 'original':
                candidate_roots.extend([
                    PROJECT_ROOT / 'Result' / 'original' / variant,
                    PROJECT_ROOT / 'Result' / variant / 'AMNTDDA',
                    PROJECT_ROOT / 'AMDGT_original' / 'Result' / variant / 'AMNTDDA',
                    PROJECT_ROOT / 'Result' / variant / 'RLGHGT_v2',
                ])
            else:
                candidate_roots.extend([
                    PROJECT_ROOT / 'Result' / 'improved' / variant,
                    PROJECT_ROOT / 'Result' / variant / 'improved',
                    PROJECT_ROOT / 'Result' / variant / 'AMNTDDA_improved',
                ])

        candidates: List[Path] = []
        for root in candidate_roots:
            if root.exists():
                candidates.extend(root.rglob('best_model*.pth'))
                candidates.extend(root.rglob('*.pth'))

        seen: Dict[str, Path] = {}
        for path in candidates:
            seen[str(path.resolve())] = path
        candidates = list(seen.values())
        if not candidates:
            return None

        def sort_key(path: Path):
            stem = path.stem.lower()
            fold_penalty = 1 if 'fold' in stem else 0
            return fold_penalty, -path.stat().st_mtime

        candidates.sort(key=sort_key)
        return candidates[0]

    @staticmethod
    def load_csv(path: Path) -> List[Dict[str, str]]:
        if not path.exists():
            return []
        with path.open('r', encoding='utf-8', newline='') as handle:
            return list(csv.DictReader(handle))

    @staticmethod
    def load_json_map(path: Path) -> Dict[str, str]:
        if not path.exists():
            return {}
        try:
            with path.open('r', encoding='utf-8') as handle:
                data = json.load(handle)
                return data if isinstance(data, dict) else {}
        except Exception:
            return {}

    @staticmethod
    def load_node_entries(data_dir: Path) -> List[Dict[str, str]]:
        for filename in ('AllNode.csv', 'Allnode.csv'):
            path = data_dir / filename
            if not path.exists():
                continue
            frame = pd.read_csv(path, header=None)
            if frame.empty:
                continue

            if frame.shape[1] == 1:
                node_ids = frame.iloc[:, 0].astype(str).str.strip()
                labels = node_ids
            else:
                node_ids = frame.iloc[:, 0].astype(str).str.strip()
                labels = frame.iloc[:, -1].astype(str).str.strip()

            entries: List[Dict[str, str]] = []
            for node_id, label in zip(node_ids, labels):
                if not label or label.lower() in {'id', 'nan'}:
                    continue
                if not node_id or node_id.lower() in {'id', 'nan'}:
                    node_id = label
                entries.append({'node_id': node_id, 'label': label})
            return entries
        return []

    @staticmethod
    def load_node_ids(data_dir: Path) -> List[str]:
        return [entry['label'] for entry in InferenceManager.load_node_entries(data_dir)]

    @staticmethod
    def looks_like_compact_id(value: str) -> bool:
        return bool(re.fullmatch(r'[A-Za-z]{0,5}\d[\w-]*', value.strip()))

    def load_drug_info(self, data_dir: Path, count: int) -> List[Dict[str, str]]:
        node_entries = self.load_node_entries(data_dir)
        # If count is 0, we try to get all drugs from node_ids based on common patterns
        # or just use the whole list if it looks like only drugs.
        # But usually count is provided by the caller who knows the feature matrix shape.
        drug_nodes = node_entries[:count] if count > 0 else node_entries

        info_map = {}
        rows = self.load_csv(data_dir / 'DrugInformation.csv')
        for row in rows:
            # We index by name and ID (lowercased) to maximize match chances
            name = (row.get('name') or row.get('drug_name') or '').lower().strip()
            drug_id = str(row.get('id') or row.get('drug_id') or '').lower().strip()
            if name:
                info_map[name] = row
            if drug_id:
                info_map[drug_id] = row

        results = []
        for entry in drug_nodes:
            node = entry['label']
            node_lower = node.lower().strip()
            row = info_map.get(node_lower)
            if row:
                results.append({
                    'id': str(row.get('id') or row.get('drug_id') or node),
                    'name': str(row.get('name') or row.get('drug_name') or node),
                    'smiles': str(row.get('smiles') or ''),
                })
            else:
                results.append({'id': node, 'name': node, 'smiles': ''})
        return results

    def load_disease_info(self, data_dir: Path, drug_count: int, disease_count: int) -> List[Dict[str, str]]:
        node_entries = self.load_node_entries(data_dir)
        disease_nodes = node_entries[drug_count:drug_count + disease_count] if disease_count > 0 else node_entries[drug_count:]

        info_map = {}
        rows = self.load_csv(data_dir / 'DiseaseInformation.csv')
        for row in rows:
            name = (row.get('name') or row.get('disease_name') or '').lower().strip()
            disease_id = str(row.get('id') or row.get('disease_id') or '').lower().strip()
            if name:
                info_map[name] = row
            if disease_id:
                info_map[disease_id] = row

        for disease_id, disease_name in self.disease_name_cache.items():
            cache_row = {'id': disease_id, 'name': disease_name}
            disease_id_key = str(disease_id).lower().strip()
            disease_name_key = str(disease_name).lower().strip()
            if disease_id_key and disease_id_key not in info_map:
                info_map[disease_id_key] = cache_row
            if disease_name_key and disease_name_key not in info_map:
                info_map[disease_name_key] = cache_row

        results = []
        for entry in disease_nodes:
            node_id = entry['node_id']
            node_label = entry['label']
            node_lower = node_label.lower().strip()
            row = info_map.get(node_lower) or info_map.get(str(node_id).lower().strip())
            if row:
                row_id = str(row.get('id') or row.get('disease_id') or '').strip()
                row_name = str(row.get('name') or row.get('disease_name') or node_label).strip()
                resolved_id = row_id
                if not resolved_id:
                    resolved_id = node_label if self.looks_like_compact_id(node_label) else node_id
                elif resolved_id.lower() == row_name.lower() and not self.looks_like_compact_id(resolved_id):
                    resolved_id = node_id or resolved_id
                if not row_name or row_name.lower() == resolved_id.lower():
                    row_name = str(self.disease_name_cache.get(resolved_id) or self.disease_name_cache.get(node_label) or row_name or node_label)
                results.append({
                    'id': resolved_id,
                    'name': row_name,
                })
            else:
                fallback_id = node_label if self.looks_like_compact_id(node_label) else node_id
                fallback_key = fallback_id or node_label
                fallback_name = str(self.disease_name_cache.get(fallback_key) or self.disease_name_cache.get(node_label) or node_label)
                results.append({'id': fallback_key, 'name': fallback_name})
        return results

    def load_protein_info(self, data_dir: Path, protein_count: int) -> List[Dict[str, str]]:
        rows = self.load_csv(data_dir / 'ProteinInformation.csv')
        # Standardize to protein_count if possible
        if rows:
            info_list = [
                {
                    'id': str(row.get('id') or row.get('protein_id') or index),
                    'name': str(row.get('protein_name') or row.get('name') or row.get('id') or index),
                    'protein_name': str(row.get('protein_name') or row.get('name') or row.get('id') or index),
                    'sequence': str(row.get('sequence') or ''),
                }
                for index, row in enumerate(rows)
            ]
            if protein_count > 0:
                return info_list[:protein_count]
            return info_list
        
        return [{'id': f'PROTEIN_{index}', 'name': f'PROTEIN_{index}', 'protein_name': f'PROTEIN_{index}'} for index in range(protein_count)]

    @staticmethod
    def move_tensor_dict(values):
        if values is None:
            return None
        return {
            key: value.to(device) if isinstance(value, torch.Tensor) else value
            for key, value in values.items()
        }

    @staticmethod
    def load_checkpoint(path: Path):
        try:
            return torch.load(path, map_location=device, weights_only=False)
        except TypeError:
            return torch.load(path, map_location=device)

    @staticmethod
    def build_args(dataset_name: str, data_dir: Path, model_version: str = 'improved'):
        if model_version == 'original':
            # Per-dataset presets for original model checkpoints
            original_presets = {
                'F-dataset': {
                    'neighbor': 10,
                    'gt_out_dim': 384,
                    'hgt_in_dim': 384,
                    'hgt_layer': 3,
                    'hgt_head': 8,
                    'hgt_head_dim': 48,
                    'topo_hidden': 192,
                },
                'B-dataset': {
                    'neighbor': 15,
                    'gt_out_dim': 256,
                    'hgt_in_dim': 256,
                    'hgt_layer': 3,
                    'hgt_head': 8,
                    'hgt_head_dim': 32,
                    'topo_hidden': 128,
                },
            }
            preset = original_presets.get(dataset_name, {
                'neighbor': 20,
                'gt_out_dim': 200,
                'hgt_in_dim': 64,
                'hgt_layer': 2,
                'hgt_head': 8,
                'hgt_head_dim': 25,
                'topo_hidden': 128,
            })
        else:
            preset = DATASET_PRESETS.get(dataset_name, DATASET_PRESETS['C-dataset'])

        class MockArgs:
            def __init__(self, dataset: str, root: Path, cfg: Dict[str, int]):
                self.dataset = dataset
                self.data_dir = str(root)
                self.neighbor = cfg['neighbor']
                self.hgt_layer = cfg['hgt_layer']
                self.hgt_head = cfg['hgt_head']
                self.hgt_head_dim = cfg['hgt_head_dim']
                self.hgt_in_dim = cfg['hgt_in_dim']
                self.hgt_out_dim = cfg['gt_out_dim']
                self.gt_layer = 2
                self.gt_head = cfg.get('gt_head', 2) # Cả hai bản đều dùng 2
                self.gt_out_dim = cfg['gt_out_dim']
                # Cả hai bản đều dùng 2 layers transformer
                self.tr_layer = cfg.get('tr_layer', 2)
                self.tr_head = cfg.get('tr_head', 4)
                self.dropout = 0.2
                self.topo_hidden = cfg.get('topo_hidden', 128)
                self.assoc_backbone = 'vanilla_hgt'
                self.fusion_mode = 'mva'
                self.pair_mode = 'mul_mlp'
                self.gate_mode = 'vector'
                self.gate_bias_init = -2.0
                self.temperature = 0.5
                self.topo_hidden = cfg['topo_hidden']
                self.topo_feat_dim = 7
                self.device = str(device)

        return MockArgs(dataset_name, data_dir, preset)

    def get_related_proteins(self, ctx: Dict[str, object], source_type: str, source_idx: int, limit: int = 5) -> List[Dict[str, str]]:
        related = []
        for protein_idx in self.get_related_protein_indices(ctx, source_type, source_idx, limit):
            if protein_idx >= len(ctx['protein_info']):
                continue
            related.append(ctx['protein_info'][protein_idx])
        return related

    def get_related_protein_indices(self, ctx: Dict[str, object], source_type: str, source_idx: int, limit: int | None = None) -> List[int]:
        protein_indices: List[int] = []
        if source_type == 'drug':
            for row in ctx.get('drpr_edges', []):
                if int(row[0]) == source_idx:
                    protein_indices.append(int(row[1]))
        else:
            for row in ctx.get('dipr_edges', []):
                if int(row[0]) == source_idx:
                    protein_indices.append(int(row[1]))

        seen = set()
        unique_indices: List[int] = []
        for protein_idx in protein_indices:
            if protein_idx in seen:
                continue
            seen.add(protein_idx)
            unique_indices.append(protein_idx)
            if limit is not None and len(unique_indices) >= limit:
                break
        return unique_indices

    def load_context(self, dataset_name: str):
        if dataset_name in self.cached_data:
            return self.cached_data[dataset_name]

        data_dir, model_path = self.get_dataset_paths(dataset_name)
        if not data_dir.exists():
            raise HTTPException(status_code=404, detail=f"Dataset directory not found: {dataset_name}")

        args = self.build_args(dataset_name, data_dir)

        drf = pd.read_csv(data_dir / 'DrugFingerprint.csv').iloc[:, 1:].to_numpy()
        drg = pd.read_csv(data_dir / 'DrugGIP.csv').iloc[:, 1:].to_numpy()
        dip = pd.read_csv(data_dir / 'DiseasePS.csv').iloc[:, 1:].to_numpy()
        dig = pd.read_csv(data_dir / 'DiseaseGIP.csv').iloc[:, 1:].to_numpy()

        drs = np.where(drf == 0, drg, (drf + drg) / 2)
        dis = np.where(dip == 0, dig, (dip + dig) / 2)

        data = {
            'drs': drs,
            'dis': dis,
            'drdi': pd.read_csv(data_dir / 'DrugDiseaseAssociationNumber.csv', dtype=int).to_numpy(),
            'drpr': pd.read_csv(data_dir / 'DrugProteinAssociationNumber.csv', dtype=int).to_numpy(),
            'dipr': pd.read_csv(data_dir / 'ProteinDiseaseAssociationNumber.csv', dtype=int).to_numpy(),
            'drugfeature': pd.read_csv(data_dir / 'Drug_mol2vec.csv', header=None).iloc[:, 1:].to_numpy(),
            'diseasefeature': pd.read_csv(data_dir / 'DiseaseFeature.csv', header=None).iloc[:, 1:].to_numpy(),
            'proteinfeature': pd.read_csv(data_dir / 'Protein_ESM.csv', header=None).iloc[:, 1:].to_numpy(),
        }

        args.drug_number = data['drugfeature'].shape[0]
        args.disease_number = data['diseasefeature'].shape[0]
        args.protein_number = data['proteinfeature'].shape[0]

        drugs_info = self.load_drug_info(data_dir, args.drug_number)
        disease_info = self.load_disease_info(data_dir, args.drug_number, args.disease_number)
        protein_info = self.load_protein_info(data_dir, args.protein_number)

        drdr_graph, didi_graph, _ = dgl_similarity_graph(data, args)
        drug_topo_feat, disease_topo_feat = extract_topology_features(data, args)

        empty_drdi = np.empty((0, 2), dtype=int)
        heterograph_result = dgl_heterograph(data, empty_drdi, args)
        drdipr_graph = heterograph_result[0]
        data = heterograph_result[1]
        edge_stats = heterograph_result[2] if len(heterograph_result) > 2 else None

        drdr_graph = drdr_graph.to(device)
        didi_graph = didi_graph.to(device)
        drdipr_graph = drdipr_graph.to(device)
        drug_feature = torch.tensor(data['drugfeature'], dtype=torch.float32).to(device)
        disease_feature = torch.tensor(data['diseasefeature'], dtype=torch.float32).to(device)
        protein_feature = torch.tensor(data['proteinfeature'], dtype=torch.float32).to(device)
        drug_topo_feat = drug_topo_feat.to(device)
        disease_topo_feat = disease_topo_feat.to(device)

        context = {
            'args': args,
            'model_path': model_path,
            'drdr_graph': drdr_graph,
            'didi_graph': didi_graph,
            'drdipr_graph': drdipr_graph,
            'drug_feature': drug_feature,
            'disease_feature': disease_feature,
            'protein_feature': protein_feature,
            'drug_topo_feat': drug_topo_feat,
            'disease_topo_feat': disease_topo_feat,
            'edge_stats': self.move_tensor_dict(edge_stats),
            'drugs_info': drugs_info,
            'disease_info': disease_info,
            'protein_info': protein_info,
            'drpr_edges': data['drpr'],
            'dipr_edges': data['dipr'],
        }
        self.cached_data[dataset_name] = context
        return context

    def get_model(self, dataset_name: str):
        if dataset_name in self.cached_models:
            return self.cached_models[dataset_name]

        _, model_path = self.get_dataset_paths(dataset_name)
        if model_path is None or not model_path.exists():
            return None

        ctx = self.load_context(dataset_name)
        model = AMNTDDA(ctx['args']).to(device)

        try:
            checkpoint = self.load_checkpoint(model_path)
            state_dict = checkpoint.get('model_state_dict', checkpoint) if isinstance(checkpoint, dict) else checkpoint
            model.load_state_dict(state_dict, strict=False)
            model.eval()
            self.cached_models[dataset_name] = model
            return model
        except Exception as e:
            print(f"Error loading model: {e}")
            return None

    def load_context_for_version(self, dataset_name: str, model_version: str):
        cache_key = f"{model_version}:{dataset_name}"
        if cache_key in self.cached_data:
            return self.cached_data[cache_key]

        data_dir = PROJECT_ROOT / 'AMDGT_original' / 'data' / dataset_name
        if not data_dir.exists():
            raise HTTPException(status_code=404, detail=f"Dataset directory not found: {dataset_name}")

        args = self.build_args(dataset_name, data_dir, model_version)
        preprocess = improved_preprocess if model_version == 'improved' else original_preprocess

        drf = pd.read_csv(data_dir / 'DrugFingerprint.csv').iloc[:, 1:].to_numpy()
        drg = pd.read_csv(data_dir / 'DrugGIP.csv').iloc[:, 1:].to_numpy()
        dip = pd.read_csv(data_dir / 'DiseasePS.csv').iloc[:, 1:].to_numpy()
        dig = pd.read_csv(data_dir / 'DiseaseGIP.csv').iloc[:, 1:].to_numpy()

        drs = np.where(drf == 0, drg, (drf + drg) / 2)
        dis = np.where(dip == 0, dig, (dip + dig) / 2)

        data = {
            'drs': drs,
            'dis': dis,
            'drdi': pd.read_csv(data_dir / 'DrugDiseaseAssociationNumber.csv', dtype=int).to_numpy(),
            'drpr': pd.read_csv(data_dir / 'DrugProteinAssociationNumber.csv', dtype=int).to_numpy(),
            'dipr': pd.read_csv(data_dir / 'ProteinDiseaseAssociationNumber.csv', dtype=int).to_numpy(),
            'drugfeature': pd.read_csv(data_dir / 'Drug_mol2vec.csv', header=None).iloc[:, 1:].to_numpy(),
            'diseasefeature': pd.read_csv(data_dir / 'DiseaseFeature.csv', header=None).iloc[:, 1:].to_numpy(),
            'proteinfeature': pd.read_csv(data_dir / 'Protein_ESM.csv', header=None).iloc[:, 1:].to_numpy(),
        }

        args.drug_number = data['drugfeature'].shape[0]
        args.disease_number = data['diseasefeature'].shape[0]
        args.protein_number = data['proteinfeature'].shape[0]

        drugs_info = self.load_drug_info(data_dir, args.drug_number)
        disease_info = self.load_disease_info(data_dir, args.drug_number, args.disease_number)
        protein_info = self.load_protein_info(data_dir, args.protein_number)

        drdr_graph, didi_graph, _ = preprocess.dgl_similarity_graph(data, args)
        heterograph_result = preprocess.dgl_heterograph(data, np.empty((0, 2), dtype=int), args)
        drdipr_graph = heterograph_result[0]
        data = heterograph_result[1]
        edge_stats = heterograph_result[2] if len(heterograph_result) > 2 else None

        drug_topo_feat, disease_topo_feat = extract_topology_features(data, args)

        context = {
            'args': args,
            'drdr_graph': drdr_graph.to(device),
            'didi_graph': didi_graph.to(device),
            'drdipr_graph': drdipr_graph.to(device),
            'drug_feature': torch.tensor(data['drugfeature'], dtype=torch.float32).to(device),
            'disease_feature': torch.tensor(data['diseasefeature'], dtype=torch.float32).to(device),
            'protein_feature': torch.tensor(data['proteinfeature'], dtype=torch.float32).to(device),
            'drug_topo_feat': drug_topo_feat.to(device),
            'disease_topo_feat': disease_topo_feat.to(device),
            'edge_stats': self.move_tensor_dict(edge_stats),
            'drugs_info': drugs_info,
            'disease_info': disease_info,
            'protein_info': protein_info,
            'drpr_edges': data['drpr'],
            'dipr_edges': data['dipr'],
        }
        self.cached_data[cache_key] = context
        return context

    def get_model_for_version(self, dataset_name: str, model_version: str):
        checkpoint_path = self.resolve_checkpoint_path(dataset_name, model_version)
        if checkpoint_path is None or not checkpoint_path.exists():
            message = 'Không tìm thấy checkpoint model gốc' if model_version == 'original' else 'Không tìm thấy checkpoint model cải tiến'
            raise HTTPException(status_code=404, detail=f"{message} cho {dataset_name}.")

        cache_key = f"{model_version}:{dataset_name}:{checkpoint_path.resolve()}"
        if cache_key in self.cached_models:
            return self.cached_models[cache_key], checkpoint_path

        ctx = self.load_context_for_version(dataset_name, model_version)
        model_cls = OriginalAMNTDDA if model_version == 'original' else ImprovedAMNTDDA
        model = model_cls(ctx['args']).to(device)

        try:
            checkpoint = self.load_checkpoint(checkpoint_path)
            state_dict = checkpoint.get('model_state_dict', checkpoint) if isinstance(checkpoint, dict) else checkpoint
            try:
                model.load_state_dict(state_dict, strict=False)
            except RuntimeError:
                stripped = {
                    key.replace('module.', '', 1) if key.startswith('module.') else key: value
                    for key, value in state_dict.items()
                }
                model.load_state_dict(stripped, strict=False)
            model.eval()
        except HTTPException:
            raise
        except Exception as exc:
            raise HTTPException(status_code=500, detail=f"Không load được checkpoint {model_version}: {checkpoint_path}. Lỗi: {exc}")

        print(f"[compare_predict] Loaded {model_version} checkpoint: {checkpoint_path}")
        self.cached_models[cache_key] = model
        return model, checkpoint_path

    def score_pairs(self, dataset_name: str, model_version: str, pair_indices: List[Tuple[int, int]]) -> np.ndarray:
        model, _ = self.get_model_for_version(dataset_name, model_version)
        ctx = self.load_context_for_version(dataset_name, model_version)
        x_pairs = torch.tensor(pair_indices, dtype=torch.long, device=device)

        with torch.no_grad():
            if model_version == 'improved':
                _, scores = model(
                    ctx['drdr_graph'], ctx['didi_graph'], ctx['drdipr_graph'],
                    ctx['drug_feature'], ctx['disease_feature'], ctx['protein_feature'],
                    x_pairs,
                    drug_topo_feat=ctx['drug_topo_feat'],
                    disease_topo_feat=ctx['disease_topo_feat'],
                    edge_stats=ctx['edge_stats'],
                )
            else:
                _, scores = model(
                    ctx['drdr_graph'], ctx['didi_graph'], ctx['drdipr_graph'],
                    ctx['drug_feature'], ctx['disease_feature'], ctx['protein_feature'],
                    x_pairs,
                )

        return fn.softmax(scores, dim=-1)[:, 1].cpu().numpy()

manager = InferenceManager()


def fuzzy_match(items: List[Dict[str, str]], text: str, name_key: str = 'name'):
    text_lower = text.strip().lower()
    for item in items:
        if (item.get(name_key) or '').lower() == text_lower or item.get('id', '').lower() == text_lower:
            return item
    for item in items:
        if text_lower in (item.get(name_key) or '').lower() or text_lower in item.get('id', '').lower():
            return item
    return None


def normalize_requested_entities(values: List[str], label: str) -> List[str]:
    cleaned: List[str] = []
    seen = set()
    for raw_value in values:
        for part in str(raw_value).replace('\n', ',').split(','):
            value = part.strip()
            if not value:
                continue
            key = value.lower()
            if key in seen:
                continue
            cleaned.append(value)
            seen.add(key)

    if not cleaned:
        raise HTTPException(status_code=422, detail=f"Vui lòng nhập ít nhất 1 {label}.")
    if len(cleaned) > 5:
        raise HTTPException(status_code=422, detail=f"Chỉ được nhập tối đa 5 {label}.")
    return cleaned


def resolve_requested_items(items: List[Dict[str, str]], values: List[str], label: str):
    resolved = []
    used_ids = set()
    for value in values:
        match = fuzzy_match(items, value, 'name')
        if not match:
            raise HTTPException(status_code=422, detail=f"Không tìm thấy {label}: {value}")
        if match['id'] in used_ids:
            continue
        resolved.append(match)
        used_ids.add(match['id'])
    return resolved


def top_model_results(rows: List[Dict[str, object]], score_key: str, top_k: int) -> List[Dict[str, object]]:
    ranked = sorted(rows, key=lambda item: float(item[score_key]), reverse=True)[:top_k]
    return [
        {
            'drug_id': row['drug_id'],
            'drug_name': row['drug_name'],
            'disease_id': row['disease_id'],
            'disease_name': row['disease_name'],
            'score': row[score_key],
        }
        for row in ranked
    ]


@app.post('/predict')
async def predict(payload: PredictRequest):
    dataset = payload.dataset
    ctx = manager.load_context(dataset)
    model = manager.get_model(dataset)
    is_real = model is not None

    if is_real and ctx['model_path'] is not None:
        note = f"Đang sử dụng checkpoint {Path(ctx['model_path']).name} trên {dataset}."
    else:
        note = f"Đang chạy Demo cho {dataset} (chưa tìm thấy checkpoint phù hợp)."

    source = None

    if payload.query_type == 'drug_to_disease':
        source = fuzzy_match(ctx['drugs_info'], payload.input_text, 'name')
        if not source:
            alt_source = fuzzy_match(ctx['disease_info'], payload.input_text, 'name')
            if alt_source:
                note = f"⚠️ Thông báo: '{payload.input_text}' là một Bệnh."
            else:
                note = f"ℹ️ Không tìm thấy thực thể '{payload.input_text}' trong hệ thống dữ liệu."
            return {'status': 'success', 'results': [], 'note': note, 'graph': {'nodes': [], 'links': []}}
        source_idx = ctx['drugs_info'].index(source)
        num_diseases = len(ctx['disease_info'])
        x_infer = torch.tensor([[source_idx, i] for i in range(num_diseases)], dtype=torch.long, device=device)
        target_info = ctx['disease_info']
        target_type = 'disease'
    else:
        source = fuzzy_match(ctx['disease_info'], payload.input_text, 'name')
        if not source:
            alt_source = fuzzy_match(ctx['drugs_info'], payload.input_text, 'name')
            if alt_source:
                note = f"⚠️ Thông báo: '{payload.input_text}' là một Thuốc."
            else:
                note = f"ℹ️ Không tìm thấy thực thể '{payload.input_text}' trong hệ thống dữ liệu."
            return {'status': 'success', 'results': [], 'note': note, 'graph': {'nodes': [], 'links': []}}

        source_idx = ctx['disease_info'].index(source)
        num_drugs = len(ctx['drugs_info'])
        x_infer = torch.tensor([[i, source_idx] for i in range(num_drugs)], dtype=torch.long, device=device)
        target_info = ctx['drugs_info']
        target_type = 'drug'

    if is_real:
        with torch.no_grad():
            _, scores = model(
                ctx['drdr_graph'], ctx['didi_graph'], ctx['drdipr_graph'],
                ctx['drug_feature'], ctx['disease_feature'], ctx['protein_feature'],
                x_infer,
                drug_topo_feat=ctx['drug_topo_feat'],
                disease_topo_feat=ctx['disease_topo_feat'],
                edge_stats=ctx['edge_stats'],
            )
            probs = fn.softmax(scores, dim=-1)[:, 1].cpu().numpy()
    else:
        probs = np.random.uniform(0.1, 0.9, len(target_info))

    results = []
    indices = np.argsort(-probs)[:payload.top_k]
    for idx in indices:
        item = target_info[idx]
        results.append({
            'id': item['id'],
            'name': item.get('name', item['id']),
            'score': float(round(probs[idx], 4)),
            'type': target_type
        })

    src_type = payload.query_type.split('_')[0]
    source_node_id = f"{src_type}:{source['id']}"
    graph_node_map: Dict[str, Dict[str, object]] = {
        source_node_id: {
            'id': source_node_id,
            'actual_id': source['id'],
            'label': source.get('name', source['id']),
            'type': src_type,
            'color': '#2563eb' if src_type == 'drug' else '#dc2626',
            'smiles': source.get('smiles', ''),
            'is_source': True,
        }
    }
    graph_links: List[Dict[str, object]] = []
    seen_edges = set()

    def append_graph_link(source_id: str, target_id: str, kind: str, score_value: float) -> None:
        edge_key = (source_id, target_id, kind)
        if edge_key in seen_edges:
            return
        seen_edges.add(edge_key)
        graph_links.append({
            'source': source_id,
            'target': target_id,
            'kind': kind,
            'score': float(round(score_value, 4)),
        })

    source_protein_indices = manager.get_related_protein_indices(ctx, src_type, source_idx, limit=None)
    protein_hit_count: Dict[int, int] = {}
    graph_result_items: List[Dict[str, object]] = []

    for rank, target_idx in enumerate(indices[:5], start=1):
        item = target_info[int(target_idx)]
        target_protein_indices = manager.get_related_protein_indices(ctx, target_type, int(target_idx), limit=None)
        target_protein_set = set(target_protein_indices)
        shared_protein_indices = [protein_idx for protein_idx in source_protein_indices if protein_idx in target_protein_set][:4]

        for protein_idx in shared_protein_indices:
            protein_hit_count[protein_idx] = protein_hit_count.get(protein_idx, 0) + 1

        graph_result_items.append({
            'node_id': f"{target_type}:{item['id']}",
            'actual_id': item['id'],
            'label': item.get('name', item['id']),
            'type': target_type,
            'color': '#dc2626' if target_type == 'disease' else '#2563eb',
            'smiles': item.get('smiles', ''),
            'score': float(round(probs[int(target_idx)], 4)),
            'rank': rank,
            'shared_protein_indices': shared_protein_indices,
        })

    ranked_protein_indices = sorted(
        protein_hit_count.keys(),
        key=lambda protein_idx: (protein_hit_count[protein_idx], -protein_idx),
        reverse=True,
    )[:8]

    if not ranked_protein_indices:
        ranked_protein_indices = source_protein_indices[:6]

    selected_protein_set = set(ranked_protein_indices)
    for protein_idx in ranked_protein_indices:
        if protein_idx >= len(ctx['protein_info']):
            continue
        protein = ctx['protein_info'][protein_idx]
        protein_node_id = f"protein:{protein['id']}"
        label = protein.get('protein_name', protein.get('name', protein['id']))
        seq = protein.get('sequence', '')
        graph_node_map[protein_node_id] = {
            'id': protein_node_id,
            'actual_id': protein['id'],
            'label': label,
            'type': 'protein',
            'color': '#f59e0b',
            'seq_len': len(seq),
            'support': protein_hit_count.get(protein_idx, 1),
        }
        append_graph_link(source_node_id, protein_node_id, 'source-protein', min(0.95, 0.35 + 0.12 * protein_hit_count.get(protein_idx, 1)))

    for item in graph_result_items:
        graph_node_map[item['node_id']] = {
            'id': item['node_id'],
            'actual_id': item['actual_id'],
            'label': item['label'],
            'type': item['type'],
            'color': item['color'],
            'smiles': item['smiles'],
            'score': item['score'],
            'rank': item['rank'],
            'shared_protein_count': len(item['shared_protein_indices']),
        }
        append_graph_link(source_node_id, item['node_id'], 'prediction', float(item['score']))

        for protein_idx in item['shared_protein_indices']:
            if protein_idx not in selected_protein_set or protein_idx >= len(ctx['protein_info']):
                continue
            protein_node_id = f"protein:{ctx['protein_info'][protein_idx]['id']}"
            append_graph_link(protein_node_id, item['node_id'], 'protein-target', float(item['score']))

    graph_nodes: List[Dict[str, object]] = [graph_node_map[source_node_id]]
    for protein_idx in ranked_protein_indices:
        if protein_idx >= len(ctx['protein_info']):
            continue
        protein_node_id = f"protein:{ctx['protein_info'][protein_idx]['id']}"
        if protein_node_id in graph_node_map:
            graph_nodes.append(graph_node_map[protein_node_id])
    for item in graph_result_items:
        if item['node_id'] in graph_node_map:
            graph_nodes.append(graph_node_map[item['node_id']])

    return {
        'status': 'success',
        'matched_input': {'id': source['id'], 'name': source.get('name', source['id']), 'type': src_type, 'smiles': source.get('smiles', '')},
        'results': results,
        'graph': {'nodes': graph_nodes, 'links': graph_links},
        'note': note,
    }



@app.post('/compare_predict')
async def compare_predict_removed():
    raise HTTPException(status_code=404, detail='Endpoint removed.')







@app.get('/entities')
def list_entities(dataset: str = 'C-dataset'):
    if dataset not in DATASET_PRESETS:
        raise HTTPException(status_code=422, detail=f"Dataset không hợp lệ: {dataset}")
    data_dir = PROJECT_ROOT / 'AMDGT_original' / 'data' / dataset
    if not data_dir.exists():
        raise HTTPException(status_code=404, detail=f"Dataset not found: {dataset}")
    
    # We need to get the correct counts to align entities
    try:
        drug_count = pd.read_csv(data_dir / 'Drug_mol2vec.csv', header=None).shape[0]
    except Exception:
        drug_count = 0
    try:
        disease_count = pd.read_csv(data_dir / 'DiseaseFeature.csv', header=None).shape[0]
    except Exception:
        disease_count = 0

    drugs = manager.load_drug_info(data_dir, drug_count)
    diseases = manager.load_disease_info(data_dir, drug_count, disease_count)
    
    return {
        'status': 'success',
        'dataset': dataset,
        'drugs': [{'id': d['id'], 'name': d['name'], 'smiles': d.get('smiles', '')} for d in drugs],
        'diseases': [{'id': d['id'], 'name': d['name']} for d in diseases],
    }


@app.get('/health')
def health():
    return {'status': 'ok', 'device': str(device)}
