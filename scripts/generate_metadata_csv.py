"""
generate_metadata_csv.py
Dùng cache JSON đã fetch để tạo ra các file CSV metadata mới cho từng dataset.
Chạy SAU khi đã chạy xong fetch_disease_names.py và fetch_protein_names.py.
"""
import os
import json
import csv

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(BASE_DIR, "AMDGT", "data")
CACHE_DIR = os.path.join(BASE_DIR, "scripts", "cache")

DISEASE_CACHE = os.path.join(CACHE_DIR, "disease_name_map.json")
PROTEIN_CACHE = os.path.join(CACHE_DIR, "protein_name_map.json")


def load_json(path):
    if not os.path.exists(path):
        print(f"[ERR] Cache not found: {path}")
        return {}
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def generate_disease_info(dataset, disease_cache):
    """Tạo DiseaseInformation.csv cho dataset có OMIM ID."""
    feature_path = os.path.join(DATA_DIR, dataset, "DiseaseFeature.csv")
    out_path = os.path.join(DATA_DIR, dataset, "DiseaseInformation.csv")

    if not os.path.exists(feature_path):
        print(f"[SKIP] {dataset}: DiseaseFeature.csv not found")
        return

    rows = []
    with open(feature_path, "r", encoding="utf-8") as f:
        for line in f:
            omim_id = line.strip().split(",")[0]
            if not omim_id:
                continue
            name = disease_cache.get(omim_id, omim_id)
            omim_num = omim_id[1:] if omim_id.startswith("D") else omim_id
            omim_url = f"https://omim.org/entry/{omim_num}" if omim_num.isdigit() else ""
            rows.append({"id": omim_id, "name": name, "omim_url": omim_url})

    with open(out_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["id", "name", "omim_url"])
        writer.writeheader()
        writer.writerows(rows)

    try:
        resolved = sum(1 for r in rows if r["name"] != r["id"])
        print(f"[OK] {dataset}/DiseaseInformation.csv - {len(rows)} disease, {resolved} resolved")
    except UnicodeEncodeError:
        pass



def generate_b_dataset_disease():
    """B-dataset: bệnh đã là tên tiếng Anh, chỉ cần tạo DiseaseInformation.csv đơn giản."""
    feature_path = os.path.join(DATA_DIR, "B-dataset", "DiseaseFeature.csv")
    out_path = os.path.join(DATA_DIR, "B-dataset", "DiseaseInformation.csv")

    if not os.path.exists(feature_path):
        print("[SKIP] B-dataset: DiseaseFeature.csv not found")
        return

    rows = []
    with open(feature_path, "r", encoding="utf-8") as f:
        for line in f:
            name = line.strip().split(",")[0].strip('"')
            if name:
                rows.append({"id": name, "name": name.title(), "omim_url": ""})

    with open(out_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["id", "name", "omim_url"])
        writer.writeheader()
        writer.writerows(rows)

    try:
        print(f"[OK] B-dataset/DiseaseInformation.csv - {len(rows)} disease")
    except UnicodeEncodeError:
        pass


def generate_protein_name_map(dataset, protein_cache):
    """Tạo ProteinNameMap.csv cho dataset."""
    protein_path = os.path.join(DATA_DIR, dataset, "ProteinInformation.csv")
    out_path = os.path.join(DATA_DIR, dataset, "ProteinNameMap.csv")

    if not os.path.exists(protein_path):
        print(f"[SKIP] {dataset}: ProteinInformation.csv not found")
        return

    rows = []
    with open(protein_path, "r", encoding="utf-8") as f:
        next(f)  # Bỏ header
        for line in f:
            uid = line.strip().split(",")[0]
            if not uid:
                continue
            entry = protein_cache.get(uid, {})
            protein_name = entry.get("protein_name", uid) if isinstance(entry, dict) else uid
            gene_name = entry.get("gene_name", "") if isinstance(entry, dict) else ""
            rows.append({"id": uid, "protein_name": protein_name, "gene_name": gene_name})

    with open(out_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["id", "protein_name", "gene_name"])
        writer.writeheader()
        writer.writerows(rows)

    try:
        resolved = sum(1 for r in rows if r["protein_name"] != r["id"])
        print(f"[OK] {dataset}/ProteinNameMap.csv - {len(rows)} protein, {resolved} resolved")
    except UnicodeEncodeError:
        pass


def main():
    print("=" * 60)
    print("Generate Metadata CSV files from cache")
    print("=" * 60)

    disease_cache = load_json(DISEASE_CACHE)
    protein_cache = load_json(PROTEIN_CACHE)

    print(f"Disease cache: {len(disease_cache)} entries")
    print(f"Protein cache: {len(protein_cache)} entries")
    print()

    # --- Disease Information ---
    print("[Disease] Generating DiseaseInformation.csv...")
    generate_disease_info("C-dataset", disease_cache)
    generate_disease_info("F-dataset", disease_cache)
    generate_b_dataset_disease()

    print()

    # --- Protein Name Map ---
    print("[Protein] Generating ProteinNameMap.csv...")
    for ds in ["B-dataset", "C-dataset", "F-dataset"]:
        generate_protein_name_map(ds, protein_cache)

    print()
    print("=" * 60)
    print("All metadata CSV files generated successfully!")
    print("Next step: Restart python_api to use new metadata.")


if __name__ == "__main__":
    main()
