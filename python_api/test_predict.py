import sys
import os
from pathlib import Path
sys.path.append(str(Path('D:/LapTrinh/Đồ án cơ sở/Colab_V4').resolve()))
from python_api.main import InferenceManager
import numpy as np

manager = InferenceManager()
dataset = 'F-dataset'
model_version = 'improved'

print(f"Loading {model_version} for {dataset}...")
ctx = manager.load_context_for_version(dataset, model_version)
diseases = ctx['disease_info']
drugs = ctx['drugs_info']

target_disease = 'D102100'
disease_idx = next(i for i, d in enumerate(diseases) if d['id'] == target_disease)
disease_name = diseases[disease_idx].get('name', target_disease)

pair_indices = [(i, disease_idx) for i in range(len(drugs))]
scores = manager.score_pairs(dataset, model_version, pair_indices)

top_5_idx = np.argsort(scores)[-5:][::-1]
print(f"\nTop 5 drugs for disease {disease_name} ({target_disease}):")
for idx in top_5_idx:
    drug = drugs[idx]
    print(f"- {drug['id']} - {drug.get('name', '')} : Score = {scores[idx]:.4f}")
