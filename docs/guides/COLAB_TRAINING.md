# Google Colab Training Guide

Huong dan nay gom dung quy trinh da debug de chay repo `AMDGT_CaiTien` tren Google Colab Linux.

Tai lieu nay uu tien:

- clone repo sach
- cai moi truong on dinh tren Colab GPU
- restart runtime dung luc
- smoke test truoc khi train dai
- luu log va checkpoint len Google Drive

## 0. Chuan bi runtime

Vao:

- `Runtime -> Change runtime type`
- chon `GPU`

Kiem tra nhanh:

```python
!nvidia-smi
import sys, platform, torch
print(sys.version)
print(platform.platform())
print("cuda available:", torch.cuda.is_available())
```

Neu `!nvidia-smi` khong ra GPU hoac `torch.cuda.is_available()` la `False` thi khong nen chay `--device cuda`.

## 1. Clone repo sach

```python
%cd /content
!rm -rf /content/AMDGT_CaiTien
!git clone https://github.com/HuynhTuanKiet05/AMDGT_CaiTien.git

%cd /content/AMDGT_CaiTien
!git log --oneline -1
```

Neu repo da ton tai va chi muon cap nhat dung ban moi nhat:

```python
%cd /content/AMDGT_CaiTien
!git pull origin main
```

Neu ban muon mot cell an toan de dam bao thu muc `/content/AMDGT_CaiTien` luon ton tai truoc moi buoc setup/train, dung cell nay:

```python
%cd /content/AMDGT_CaiTien
!pip uninstall -y torch torchvision torchaudio dgl dglgo torchdata numpy pandas scikit-learn networkx
```

## 2. Kiem tra thu muc dang co tren Colab

Neu bi loi `No such file or directory`, kiem tra nhanh:

```python
!pwd
!ls /content
!find /content -maxdepth 2 -type d | sort
```

## 3. Cai moi truong

### Cach nhanh khuyen nghi

Truoc tien, chay lai cell dam bao repo ton tai neu day la runtime moi hoac ban vua `Disconnect and delete runtime`.

```python
import os

%cd /content

if not os.path.exists("/content/AMDGT_CaiTien"):
    !git clone https://github.com/HuynhTuanKiet05/AMDGT_CaiTien.git
else:
    print("Repo da ton tai, cap nhat ban moi nhat...")
    %cd /content/AMDGT_CaiTien
    !git pull origin main
    %cd /content

%cd /content/AMDGT_CaiTien
!git log --oneline -1
```

Sau do moi setup:

```python
%cd /content/AMDGT_CaiTien
!bash scripts/colab_setup.sh
```

Sau do restart runtime:

```python
import os, signal
os.kill(os.getpid(), signal.SIGKILL)
```

Khi runtime len lai:

```python
import os

%cd /content

if not os.path.exists("/content/AMDGT_CaiTien"):
    !git clone https://github.com/HuynhTuanKiet05/AMDGT_CaiTien.git
else:
    %cd /content/AMDGT_CaiTien
    !git pull origin main
    %cd /content

%cd /content/AMDGT_CaiTien
```

### Neu `colab_setup.sh` loi, cai thu cong

```python
%cd /content/AMDGT_CaiTien
!python -m pip install --upgrade pip setuptools wheel
!python -m pip uninstall -y torch torchvision torchaudio dgl dglgo torchdata
!python -m pip install --no-cache-dir --force-reinstall \
  torch==2.4.1 torchvision==0.19.1 torchaudio==2.4.1 \
  --index-url https://download.pytorch.org/whl/cu121
!python -m pip install --no-cache-dir --force-reinstall \
```

### 2.3. Restart runtime

```python
import os, signal
os.kill(os.getpid(), signal.SIGKILL)
```

Sau khi runtime len lai, chay tiep.

### 2.4. Cai cac package train

```python
%cd /content/AMDGT_CaiTien
!pip install --no-cache-dir --force-reinstall \
  numpy==1.26.4 \
  pandas==2.2.2 \
  scikit-learn==1.6.1 \
  networkx==3.2.1 \
  torchdata==0.8.0 \
  pyTelegramBotAPI \
  "jedi>=0.19.1"
```

### 2.5. Cai DGL

```python
!pip install --no-cache-dir --force-reinstall \
  dgl==2.4.0+cu121 \
  -f https://data.dgl.ai/wheels/torch-2.4/cu121/repo.html
!python -m pip install --no-cache-dir --force-reinstall -r requirements.txt
```

Restart runtime sau khi cai xong:

```python
import os, signal
os.kill(os.getpid(), signal.SIGKILL)
```

## 4. Kiem tra moi truong sau khi cai

```python
%cd /content/AMDGT_CaiTien
import torch, dgl, numpy, pandas, sklearn, networkx, torchdata

print("torch:", torch.__version__)
print("cuda:", torch.cuda.is_available())
print("gpu:", torch.cuda.get_device_name(0) if torch.cuda.is_available() else "cpu")
print("dgl:", dgl.__version__)
print("numpy:", numpy.__version__)
print("pandas:", pandas.__version__)
print("sklearn:", sklearn.__version__)
print("networkx:", networkx.__version__)
print("scipy:", scipy.__version__)
```

Neu cell tren chay duoc thi moi truong da on.

## 4. Smoke test truoc khi train dai

Nen chay smoke test truoc khi train 1000 epoch.

```python
%cd /content/AMDGT_CaiTien
!python scripts/colab_train.py --dataset C-dataset --preset smoke
```

## 5. Mount Google Drive de luu ket qua

Mac dinh `train_final.py` hien da bat early stopping:

- neu AUC khong cai thien trong `180` epoch thi train se tu dung
- co the doi nguong bang `--patience`

## 5. Train ban improved

`train_final.py` hien da co san `DATASET_PRESETS` cho B / C / F va bat mac dinh
phase A + B + C1 (AdamW + LR warmup + focal loss + EMA + ranking loss + BUG-09
fix). Khong can truyen tay `--lr`, `--neighbor`, `--hgt_layer`, ...; chi can
chi dinh dataset va `--save_checkpoints`:

```python
%cd /content/AMDGT_CaiTien
!python train_final.py \
  --dataset C-dataset \
  --k_fold 10 \
  --epochs 1000 \
  --patience 180 \
  --device cuda \
  --save_checkpoints
```

Neu muon tat bot tinh nang de so sanh ablation, them flag `--no-use_focal`,
`--no-use_ema`, `--no-use_ranking`, `--no-filter_assoc_positives_only`, v.v.

## 6. Train va luu ket qua len Google Drive

Nen mount Drive truoc khi chay dai de tranh mat checkpoint khi runtime ngat.

### 6.1. Mount Drive

```python
from google.colab import drive
drive.mount('/content/drive')

import os, time
RUN_DIR = f"/content/drive/MyDrive/AMDGT_CaiTien_runs/Cdataset_{time.strftime('%Y%m%d_%H%M%S')}"
os.makedirs(RUN_DIR, exist_ok=True)
print("RUN_DIR =", RUN_DIR)
```

Luu y:

- Khong viet truc tiep `"{RUN_DIR}"` trong shell command neu chua tao f-string Python truoc.
- Neu khong can luu len Drive, bo qua phan nay va khong truyen `--result_root`.

## 6. Smoke test truoc khi train dai

### Cach 1: smoke test truc tiep bang `train_final.py`

```python
%cd /content/AMDGT_CaiTien
!python train_final.py \
  --dataset C-dataset \
  --k_fold 10 \
  --epochs 1000 \
  --patience 180 \
  --device cuda \
  --save_checkpoints \
  --result_root /content/drive/MyDrive/AMDGT_CaiTien_runs/C-dataset_run1
```

### Cach 2: dung launcher Colab

```python
%cd /content/AMDGT_CaiTien/AMDGT

Neu smoke test pass, moi chay train dai.

## 7. Train ban mac dinh cua `AMDGT_CaiTien`

Day la lenh train dung bo tham so `C-dataset` hien tai:

```python
%cd /content/AMDGT_CaiTien
cmd = f"""
python train_final.py \
  --dataset C-dataset \
  --device cuda \
  --epochs 1000 \
  --k_fold 10 \
  --neighbor 5 \
  --lr 1e-4 \
  --weight_decay 1e-3 \
  --hgt_layer 2 \
  --hgt_in_dim 256 \
  --hgt_head_dim 32 \
  --gt_out_dim 256 \
  --save_checkpoints \
  --result_root "{RUN_DIR}"
"""
print(cmd)
!{cmd} 2>&1 | tee -a "{RUN_DIR}/console.log"
```

## 8. Train bien the `rvg` de doi chieu

### 8.1. `No such file or directory: /content/AMDGT_CaiTien`

Repo chua duoc clone.

Chay lai:

```python
%cd /content/AMDGT_CaiTien
!python train_final.py \
  --dataset C-dataset \
  --device cuda \
  --epochs 1000 \
  --k_fold 10 \
  --neighbor 5 \
  --lr 1e-4 \
  --weight_decay 1e-3 \
  --hgt_layer 2 \
  --hgt_in_dim 256 \
  --hgt_head_dim 32 \
  --gt_out_dim 256 \
  --fusion_mode rvg \
  --pair_mode mul_mlp \
  --gate_mode vector \
  --save_checkpoints \
  --result_root "{RUN_DIR}/rvg_run"
```

## 9. Dung launcher `scripts/colab_train.py`

Launcher nay la mot wrapper cho Colab. Vi du:

```python
%cd /content/AMDGT_CaiTien
!python scripts/colab_train.py --dataset C-dataset --preset full --device cuda --mount-drive
```

Preset co san:

- `smoke`
- `standard`
- `full`

## 10. Cac loi da gap va cach xu ly

### 10.1. `No such file or directory: /content/AMDGT_CaiTien`

Repo chua duoc clone hoac dang o ten thu muc khac.

```python
!ls /content
%cd /content
!git clone https://github.com/HuynhTuanKiet05/AMDGT_CaiTien.git

%cd /content/AMDGT_CaiTien
```

### 10.2. `python3: can't open file '/content/train_final.py'`

Thuong la do `%cd /content/AMDGT_CaiTien` da that bai, nen notebook van dang o `/content`.

Chay:

```python
!pwd
!ls /content
```

### 10.3. `Found no NVIDIA driver on your system`

Ban dang chay `--device cuda` nhung runtime hien tai khong co GPU.

Kiem tra:

```python
!nvidia-smi
import torch
print(torch.cuda.is_available())
```

Neu khong co GPU, doi tam sang:

```python
--device cpu
```

### 10.4. `Missing dataset files in AMDGT/data/c-dataset`

Linux phan biet hoa thuong. Dung:

```python
--dataset C-dataset
```

khong dung:

```python
--dataset c-dataset
```

### 10.5. `tee: {RUN_DIR}/console.log: No such file or directory`

Ban dang truyen literal string `{RUN_DIR}` thay vi f-string Python.

Dung theo mau:

```python
cmd = f"""python train_final.py --result_root "{RUN_DIR}" """
!{cmd} 2>&1 | tee -a "{RUN_DIR}/console.log"
```

### 10.6. `ModuleNotFoundError: No module named 'dgl'`

DGL chua cai hoac cai hong. Chay lai:

```python
!python -m pip install --no-cache-dir --force-reinstall \
  dgl==2.4.0+cu121 \
  -f https://data.dgl.ai/wheels/torch-2.4/cu121/repo.html
```

Sau do restart runtime.

### 10.7. Warning dependency conflict cua Colab

Colab co nhieu package he thong khong lien quan den train.

Neu cac package sau import duoc va smoke test pass, co the tiep tuc:
Da duoc fix trong repo. Chi can:

```python
%cd /content/AMDGT_CaiTien
!git pull origin main
```

### 8.4. `module 'dgl.function' has no attribute 'src_mul_edge'`

Day la loi API DGL cu. Repo da duoc fix de dung DGL 2.x.

Chi can:

```python
%cd /content/AMDGT_CaiTien
!git pull origin main
```

### 8.5. Warning `Ignoring invalid distribution ~vidia-cublas-cu12`

Thuong la package cu bi doi ten trong runtime Colab. Neu `torch`, `dgl` va smoke test van pass thi co the bo qua.

### 8.6. `ModuleNotFoundError: No module named 'torchdata.datapipes'`

Colab/Kaggle dang ship `torchdata>=0.10`, phien ban nay da bo submodule
`datapipes`, trong khi DGL 2.x van can `from torchdata.datapipes.iter import
IterDataPipe`.

Giai phap: pin `torchdata<0.10` truoc khi import DGL, roi restart runtime:

```python
!pip install -q "torchdata<0.10"
import os
os.kill(os.getpid(), 9)
```

Sau khi runtime khoi dong lai, chay tiep cac cell install / train nhu binh
thuong. Notebook `scripts/kaggle_notebook.ipynb` da tu lo buoc pin nay o
Cell 2.

### 8.7. Warning dependency conflict cua Colab

Colab co rat nhieu package he thong khong lien quan den train model.

Muc tieu khong phai lam toan bo Colab "sach", ma la dam bao cac package can cho train dung version:
- `torch`
- `torchvision`
- `torchaudio`
- `dgl`
- `numpy`
- `pandas`
- `scikit-learn`
- `networkx`
- `scipy`

## 11. Thu tu chay khuyen nghi

Thu tu an toan nhat:

1. Bat GPU runtime
2. Clone `AMDGT_CaiTien`
3. Cai moi truong
4. Restart runtime
5. Kiem tra version
6. Mount Drive
7. Tao `RUN_DIR`
8. Smoke test
9. Train dai

## 12. Ghi chu quan trong

- Luon dung `C-dataset`, khong dung `c-dataset`.
- Neu repo da co san, uu tien `git fetch origin` + `git reset --hard origin/main`.
- Sau moi lan doi `torch`, `dgl`, `numpy`, nen restart runtime.
- `scripts/colab_setup.sh` la cach nhanh nhat; cai thu cong la fallback khi runtime Colab bi ban.
- Neu smoke test fail, khong nen chay 1000 epoch ngay.

## 13. Nguon install chinh thuc

- PyTorch previous versions: `https://pytorch.org/get-started/previous-versions/`
- DGL wheel index for torch 2.4 / cu121: `https://data.dgl.ai/wheels/torch-2.4/cu121/repo.html`
