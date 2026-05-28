import pandas as pd
import os

d = r'D:\LapTrinh\Đồ án cơ sở\AMDGT_CaiTien\AMDGT\data\B-dataset'

drug = pd.read_csv(os.path.join(d, 'Drug_mol2vec.csv'), header=None)
dis = pd.read_csv(os.path.join(d, 'DiseaseFeature.csv'), header=None)
pro = pd.read_csv(os.path.join(d, 'Protein_ESM.csv'), header=None)
drdi = pd.read_csv(os.path.join(d, 'DrugDiseaseAssociationNumber.csv'))

print(f"Drug features: {drug.shape} -> dim={drug.shape[1]-1}")
print(f"Disease features: {dis.shape} -> dim={dis.shape[1]-1}")
print(f"Protein features: {pro.shape} -> dim={pro.shape[1]-1}")
print(f"DrugDisease matrix: {drdi.shape}")
print(f"Num drugs: {drug.shape[0]}")
print(f"Num diseases: {dis.shape[0]}")
print(f"Num proteins: {pro.shape[0]}")
