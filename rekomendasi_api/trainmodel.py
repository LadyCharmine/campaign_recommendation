# Nama file: trainmodel.py

import pandas as pd
import re
import pickle
import pymysql
from sqlalchemy import create_engine
from sklearn.feature_extraction.text import TfidfVectorizer
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory

def main():
    print("=====================================================")
    print("MEMULAI PROSES SINKRONISASI DAN PELATIHAN MODEL")
    print("=====================================================")

    # --- 1. KONEKSI KE DATABASE DAN AMBIL DATA TERBARU ---
    try:
        # GANTI DENGAN DETAIL KONEKSI DATABASE ANDA
        db_user = 'root'
        db_password = ''
        db_host = 'localhost'
        db_name = 'campaign_recommendation' # PASTIKAN NAMA DATABASE SUDAH BENAR

        print(f"Menghubungkan ke database '{db_name}'...")
        connection_string = f"mysql+pymysql://{db_user}:{db_password}@{db_host}/{db_name}"
        engine = create_engine(connection_string)
        
        # Ambil semua kolom yang ada di file Excel Anda
        query = "SELECT id, Judul, Deskripsi, Yayasan, Kategori, sisa_hari, uploaded_by, uploaded_at, gambar, lokasi, terkumpul, sisahari FROM campaigns"
        df_from_db = pd.read_sql(query, engine)
        
        if df_from_db.empty:
            print("Peringatan: Tidak ada data kampanye di database. Proses dihentikan.")
            return

        print(f"Berhasil mengambil {len(df_from_db)} data kampanye dari database.")

    except Exception as e:
        print(f"FATAL: Gagal mengambil data dari database: {e}")
        return

    # --- 2. UPDATE FILE EXCEL ---
    try:
        print("\nMemperbarui file 'daftarcampaign.xlsx' dengan data terbaru...")
        # Menulis ulang seluruh file Excel dengan data dari database
        df_from_db.to_excel('daftarcampaign.xlsx', index=False, engine='openpyxl')
        print("File 'daftarcampaign.xlsx' berhasil diperbarui.")
    except Exception as e:
        print(f"FATAL: Gagal memperbarui file Excel: {e}")
        return
        
    # --- 3. PRA-PEMROSESAN DATA TEKS (DARI DATA YANG BARU DIAMBIL) ---
    print("\nMemulai pra-pemrosesan teks...")
    df_from_db['content'] = df_from_db['Judul'].astype(str) + ' ' + df_from_db['Deskripsi'].astype(str)
    
    factory_stemmer = StemmerFactory()
    stemmer = factory_stemmer.create_stemmer()
    factory_stopword = StopWordRemoverFactory()
    stopword_remover = factory_stopword.create_stop_word_remover()

    def preprocess_text(text):
        if not isinstance(text, str): return ""
        text = text.lower()
        text = re.sub(r'[^a-zA-Z0-9\s]', '', text)
        text = stopword_remover.remove(text)
        text = stemmer.stem(text)
        return text

    df_from_db['content_cleaned'] = df_from_db['content'].apply(preprocess_text)
    print("Pra-pemrosesan selesai.")

    # --- 4. PEMBUATAN DAN PELATIHAN MODEL TF-IDF ---
    print("\nMelatih model TF-IDF...")
    tfidf_vectorizer = TfidfVectorizer()
    tfidf_matrix = tfidf_vectorizer.fit_transform(df_from_db['content_cleaned'])
    print(f"Pelatihan model TF-IDF selesai. Bentuk matriks: {tfidf_matrix.shape}")

    # --- 5. MENYIMPAN MODEL KE FILE .pkl ---
    print("\nMenyimpan model ke dalam file .pkl...")
    try:
        # Menyimpan Matriks TF-IDF. Ini adalah satu-satunya file model yang dibutuhkan oleh api.py Anda.
        with open('tfidf_matrix.pkl', 'wb') as f:
            pickle.dump(tfidf_matrix, f)

        print("Model matriks TF-IDF (tfidf_matrix.pkl) berhasil disimpan.")
    except Exception as e:
        print(f"FATAL: Gagal saat menyimpan file model: {e}")

    print("\n=====================================================")
    print("PROSES SINKRONISASI DAN PELATIHAN SELESAI")
    print("=====================================================")

if __name__ == '__main__':
    # Pastikan Anda sudah menginstal library yang dibutuhkan di virtual environment Anda
    # pip install sqlalchemy pymysql pandas scikit-learn Sastrawi openpyxl
    main()
