"""
fetch_disease_names.py
Tra cứu tên bệnh từ OMIM ID (định dạng Dxxxxxx) qua EBI OLS4 API.
Áp dụng cho C-dataset và F-dataset.
Output: scripts/cache/disease_name_map.json
"""
import os
import json
import time
import requests
import pandas as pd

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(BASE_DIR, "AMDGT", "data")
CACHE_DIR = os.path.join(BASE_DIR, "scripts", "cache")
os.makedirs(CACHE_DIR, exist_ok=True)
CACHE_FILE = os.path.join(CACHE_DIR, "disease_name_map.json")

DATASETS_WITH_OMIM = ["C-dataset", "F-dataset"]


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
    except UnicodeEncodeError:
        print(f"[Cache] Saved {len(data)} entries (path contains non-ASCII chars)")


def collect_omim_ids():
    """Thu thập tất cả OMIM ID từ C và F dataset, deduplicate."""
    ids = set()
    for ds in DATASETS_WITH_OMIM:
        path = os.path.join(DATA_DIR, ds, "DiseaseFeature.csv")
        if not os.path.exists(path):
            print(f"[WARN] Not found: {path}")
            continue
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                omim_id = line.strip().split(",")[0]
                if omim_id.startswith("D") and omim_id[1:].isdigit():
                    ids.add(omim_id)
    return sorted(ids)


def fetch_name_from_ols(omim_id: str) -> str:
    """
    Tra tên bệnh từ OMIM ID qua EBI OLS4 API.
    omim_id format: 'D102100' -> query: 'OMIM:102100'
    """
    omim_num = omim_id[1:]  # Bỏ chữ D ở đầu
    obo_id = f"OMIM:{omim_num}"
    url = f"https://www.ebi.ac.uk/ols4/api/terms?obo_id={obo_id}&ontology=omim"
    try:
        resp = requests.get(url, timeout=10)
        if resp.status_code == 200:
            data = resp.json()
            terms = data.get("_embedded", {}).get("terms", [])
            if terms:
                return terms[0].get("label", omim_id)
    except Exception as e:
        print(f"  [ERR] {omim_id}: {e}")
    return None


def fetch_name_from_mesh(omim_id: str) -> str:
    """Fallback: thử tìm qua NCBI E-utilities (MeSH/OMIM lookup)."""
    omim_num = omim_id[1:]
    url = f"https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=omim&id={omim_num}&retmode=json"
    try:
        resp = requests.get(url, timeout=10)
        if resp.status_code == 200:
            data = resp.json()
            result = data.get("result", {})
            entry = result.get(omim_num, {})
            title = entry.get("title", "")
            if title:
                return title.title()  # Chuyển thành Title Case
    except Exception as e:
        print(f"  [NCBI ERR] {omim_id}: {e}")
    return None


def main():
    print("=" * 60)
    print("Fetch Disease Names from OMIM IDs")
    print("=" * 60)

    cache = load_cache()
    omim_ids = collect_omim_ids()
    print(f"Total unique OMIM IDs: {len(omim_ids)}")

    # Lọc ra những ID chưa có trong cache
    pending = [id_ for id_ in omim_ids if id_ not in cache]
    print(f"Already cached: {len(omim_ids) - len(pending)}")
    print(f"Need to fetch: {len(pending)}")

    success, failed = 0, 0
    for i, omim_id in enumerate(pending):
        print(f"[{i+1}/{len(pending)}] {omim_id} ... ", end="", flush=True)

        # Thử EBI OLS trước
        name = fetch_name_from_ols(omim_id)

        # Fallback sang NCBI nếu không tìm thấy
        if not name:
            time.sleep(0.2)
            name = fetch_name_from_mesh(omim_id)

        if name:
            cache[omim_id] = name
            print(f"OK: {name}")
            success += 1
        else:
            cache[omim_id] = omim_id  # Giữ nguyên ID nếu không tìm được
            print("NOT FOUND - kept original ID")
            failed += 1

        # Rate limit: 3 requests/giây
        time.sleep(0.35)

        # Save cache mỗi 50 IDs
        if (i + 1) % 50 == 0:
            save_cache(cache)

    save_cache(cache)
    print(f"\n{'='*60}")
    print(f"Done! Success: {success}, Failed (kept ID): {failed}")
    print(f"Success rate: {success/(success+failed)*100:.1f}%")
    print(f"Cache saved to: {CACHE_FILE}")


if __name__ == "__main__":
    main()
