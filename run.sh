#!/bin/bash

# ===== KONFIGURASI =====
ENCRYPTED_FILE="installpanel.sh.enc"
TEMP_FILE="/tmp/.tmp_installpanel.sh"
PASSWORD="Xiters!"

# ===== DEKRIPSI =====
openssl enc -aes-256-cbc -pbkdf2 -d -in "$ENCRYPTED_FILE" -out "$TEMP_FILE" -pass pass:"$PASSWORD"
if [[ $? -ne 0 ]]; then
  echo "❌ Gagal dekripsi. Cek password atau file terenkripsi."
  exit 1
fi

# ===== IZINKAN EKSEKUSI =====
chmod +x "$TEMP_FILE"

# ===== JALANKAN FILE =====
"$TEMP_FILE"

# ===== HAPUS FILE SEMENTARA =====
rm -f "$TEMP_FILE"
