#!/bin/bash

# Nama file terenkripsi dan nama file sementara
ENC_FILE="installpanel.enc"
TMP_FILE=".tmp_installpanel.sh"
PASSWORD="Xiters!"

# Cek apakah file terenkripsi ada
if [[ ! -f "$ENC_FILE" ]]; then
  echo "✗ File terenkripsi '$ENC_FILE' tidak ditemukan."
  exit 1
fi

# Dekripsi menggunakan OpenSSL AES-256-CBC
openssl enc -aes-256-cbc -d -pbkdf2 -in "$ENC_FILE" -out "$TMP_FILE" -pass pass:"$PASSWORD" 2>/dev/null

# Cek apakah dekripsi berhasil
if [[ $? -ne 0 ]]; then
  echo "✗ Gagal mendekripsi file. Pastikan password benar."
  rm -f "$TMP_FILE"
  exit 1
fi

# Beri permission dan jalankan file
chmod +x "$TMP_FILE"
bash "$TMP_FILE"

# Hapus file sementara setelah dijalankan
rm -f "$TMP_FILE"
