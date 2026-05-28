"""
fetch_protein_names.py
Tra cứu tên protein, tên gene từ UniProt Accession ID qua UniProt REST API.
Áp dụng cho cả B-dataset, C-dataset, F-dataset.
Hỗ trợ batch mode (100 IDs/request) để tăng tốc.
Output: scripts/cache/protein_name_map.json
"""
import os
import json
import time
import requests

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(BASE_DIR, "AMDGT", "data")
CACHE_DIR = os.path.join(BASE_DIR, "scripts", "cache")
os.makedirs(CACHE_DIR, exist_ok=True)
CACHE_FILE = os.path.join(CACHE_DIR, "protein_name_map.json")

ALL_DATASETS = ["B-dataset", "C-dataset", "F-dataset"]
BATCH_SIZE = 100  # UniProt cho phép batch tối đa 500, dùng 100 để an toàn


def load_cache():
    if os.path.exists(CACHE_FILE):
        with open(CACHE_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return {}


def save_cache(data):
    with open(CACHE_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    try:
        print(f"[Cache] Saved {len(data)} entries to {CACHE_FILE}")
    except Exception:
        print(f"[Cache] Saved {len(data)} entries (path contains non-ASCII chars)")


def collect_uniprot_ids():
    """Thu thập tất cả UniProt Accession ID từ 3 dataset, deduplicate."""
    ids = set()
    for ds in ALL_DATASETS:
        path = os.path.join(DATA_DIR, ds, "ProteinInformation.csv")
        if not os.path.exists(path):
            print(f"[WARN] Not found: {path}")
            continue
        with open(path, "r", encoding="utf-8") as f:
            next(f)  # Bỏ header
            for line in f:
                uniprot_id = line.strip().split(",")[0]
                if uniprot_id:
                    ids.add(uniprot_id)
        print(f"  {ds}: loaded IDs")
    return sorted(ids)


def fetch_batch(uniprot_ids: list) -> dict:
    """
    Gọi UniProt REST API theo batch.
    Trả về dict: {uniprot_id: {"protein_name": ..., "gene_name": ...}}
    """
    id_query = " OR ".join(uniprot_ids)
    url = "https://rest.uniprot.org/uniprotkb/search"
    params = {
        "query": f"accession:({id_query})",
        "fields": "accession,protein_name,gene_names",
        "format": "json",
        "size": BATCH_SIZE,
    }
    result = {}
    try:
        resp = requests.get(url, params=params, timeout=30)
        if resp.status_code == 200:
            data = resp.json()
            for entry in data.get("results", []):
                acc = entry.get("primaryAccession", "")
                # Lấy tên protein
                pname_obj = entry.get("proteinDescription", {})
                rec_name = pname_obj.get("recommendedName", {})
                full_name = rec_name.get("fullName", {}).get("value", "")
                if not full_name:
                    # Fallback về submitted name
                    submitted = pname_obj.get("submissionNames", [])
                    if submitted:
                        full_name = submitted[0].get("fullName", {}).get("value", "")

                # Lấy tên gene
                genes = entry.get("genes", [])
                gene_name = ""
                if genes:
                    g = genes[0]
                    gene_name = (g.get("geneName") or {}).get("value", "")
                    if not gene_name and g.get("synonyms"):
                        gene_name = g["synonyms"][0].get("value", "")

                if acc:
                    result[acc] = {
                        "protein_name": full_name or acc,
                        "gene_name": gene_name,
                    }
        else:
            print(f"  [HTTP {resp.status_code}] batch failed")
    except Exception as e:
        print(f"  [ERR] batch: {e}")
    return result


def main():
    print("=" * 60)
    print("Fetch Protein Names from UniProt IDs (Batch Mode)")
    print("=" * 60)

    cache = load_cache()
    all_ids = collect_uniprot_ids()
    print(f"Total unique UniProt IDs: {len(all_ids)}")

    pending = [id_ for id_ in all_ids if id_ not in cache]
    print(f"Already cached: {len(all_ids) - len(pending)}")
    print(f"Need to fetch: {len(pending)}")

    total_batches = (len(pending) + BATCH_SIZE - 1) // BATCH_SIZE
    success = 0

    for batch_idx in range(total_batches):
        batch = pending[batch_idx * BATCH_SIZE: (batch_idx + 1) * BATCH_SIZE]
        print(f"\n[Batch {batch_idx+1}/{total_batches}] Fetching {len(batch)} IDs...", flush=True)

        batch_result = fetch_batch(batch)
        for uid in batch:
            if uid in batch_result:
                cache[uid] = batch_result[uid]
                success += 1
            else:
                # Không tìm thấy: giữ ID
                cache[uid] = {"protein_name": uid, "gene_name": ""}

        found = len(batch_result)
        print(f"  Found: {found}/{len(batch)}")

        # Save mỗi 5 batch
        if (batch_idx + 1) % 5 == 0:
            save_cache(cache)

        time.sleep(0.5)  # Rate limit

    save_cache(cache)
    failed = len(pending) - success
    print(f"\n{'='*60}")
    print(f"Done! Success: {success}, Not found: {failed}")
    print(f"Success rate: {success/len(pending)*100:.1f}%" if pending else "Nothing to fetch.")
    print(f"Cache saved to: {CACHE_FILE}")


if __name__ == "__main__":
    main()
