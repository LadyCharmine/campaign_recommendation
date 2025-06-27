import pandas as pd
import pickle
from sklearn.metrics.pairwise import cosine_similarity
from flask import Flask, request, jsonify
from flask_cors import CORS
import numpy as np

# Muat model dan data
print("Memuat model dan data...")
try:
    df_campaigns = pd.read_excel('daftarcampaign.xlsx')
    indices = pd.Series(df_campaigns.index, index=df_campaigns['Judul']).drop_duplicates()
except Exception as e:
    print(f"Error saat memuat daftarcampaign.xlsx: {e}")
    df_campaigns = None
    indices = None

try:
    with open('tfidf_matrix.pkl', 'rb') as f:
        tfidf_matrix = pickle.load(f)
except Exception as e:
    print(f"Error saat memuat tfidf_matrix.pkl: {e}")
    tfidf_matrix = None

if tfidf_matrix is not None:
    cosine_sim = cosine_similarity(tfidf_matrix, tfidf_matrix)
    print("Model dan data berhasil dimuat. API siap.")
else:
    cosine_sim = None
    print("Gagal memuat model. API mungkin tidak berfungsi dengan benar.")

# Inisialisasi Flask dan CORS
app = Flask(__name__)
CORS(app, resources={r"/recommend": {"origins": "*"}})

# Fungsi rekomendasi
def get_recommendations(title, cosine_sim_matrix, dataframe):
    if title not in indices:
        print(f"Judul '{title}' tidak ditemukan di indices.")
        return []
    
    idx = indices[title]
    sim_scores = list(enumerate(cosine_sim_matrix[idx]))
    sim_scores = sorted(sim_scores, key=lambda x: x[1], reverse=True)
    sim_scores = sim_scores[1:11]
    campaign_indices = [i[0] for i in sim_scores]
    
    # Kolom yang diinginkan
    desired_columns = ['id', 'Judul', 'Deskripsi', 'Yayasan', 'Kategori']
    available_columns = [col for col in desired_columns if col in dataframe.columns]
    print(f"Kolom yang tersedia: {available_columns}")
    
    if not available_columns:
        print("Tidak ada kolom yang valid ditemukan di dataframe.")
        return []
    
    # Pilih kolom yang tersedia
    recommendations = dataframe[available_columns].iloc[campaign_indices]
    # Konversi np.float64 ke float biasa untuk JSON serialization
    recommendations = recommendations.to_dict('records')
    
    # Tambahkan similarity score
    for rec in recommendations:
        rec['similarity'] = float(sim_scores[campaign_indices.index(dataframe.index[dataframe['Judul'] == rec['Judul']].tolist()[0])][1])
    
    print(f"Rekomendasi untuk '{title}': {recommendations}")
    return recommendations

# Rute API
@app.route('/recommend', methods=['GET'])
def recommend():
    campaign_title = request.args.get('title')
    print(f"Menerima permintaan untuk judul: {campaign_title}")

    if not campaign_title:
        print("Parameter 'title' tidak ditemukan.")
        return jsonify({'error': 'Parameter "title" tidak ditemukan.'}), 400

    if cosine_sim is None or df_campaigns is None:
        print("Model atau data tidak berhasil dimuat.")
        return jsonify({'error': 'Model tidak berhasil dimuat di server.'}), 500

    recommendations = get_recommendations(campaign_title, cosine_sim, df_campaigns)

    if not recommendations:
        print(f"Tidak ada rekomendasi untuk judul '{campaign_title}'.")
        return jsonify({'error': f"Judul '{campaign_title}' tidak ditemukan atau tidak ada rekomendasi."}), 404

    return jsonify(recommendations)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)