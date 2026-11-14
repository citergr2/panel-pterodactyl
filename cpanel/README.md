# Ptero Portal PHP — Pterodactyl 1.11

Dashboard PHP untuk mengelola user/server melalui **PTLA (Application API)** dan mengontrol server yang diizinkan melalui **PTLC (Client API)**.

## Fitur
- Portal admin dengan login sendiri.
- Daftar user dan server melalui PTLA.
- Membuat user melalui PTLA.
- Mengambil daftar node dan lokasi dari PTLA.
- Form pembuatan server dengan ID owner, egg, allocation, Docker image, limits, environment JSON, dan startup override.
- Daftar server yang dapat diakses melalui PTLC.
- Perintah start/stop/restart via PTLC.
- Proteksi CSRF, output escaping, cookie sesi HttpOnly/SameSite, pemeriksaan TLS, dan API key tidak dikirim ke JavaScript.

## Persyaratan VPS
- Ubuntu 22.04/24.04 atau distro Linux modern.
- PHP 8.1+ (disarankan 8.2), ekstensi `curl`, `mbstring`, `json`, `session`.
- Nginx atau Apache dengan PHP-FPM.
- Domain dan HTTPS aktif untuk penggunaan publik.
- Pterodactyl Panel 1.11 dapat diakses dari VPS web.

## 1. Instal paket (Ubuntu/Debian)
Contoh untuk Nginx + PHP 8.2; sesuaikan versi PHP dan web server jika sudah terpasang.

```bash
sudo apt update
sudo apt install nginx php8.2-fpm php8.2-curl php8.2-mbstring php8.2-json unzip
sudo mkdir -p /var/www/ptero-portal
```

Upload ZIP ke VPS lalu ekstrak:
```bash
sudo unzip ptero_portal_full.zip -d /var/www/ptero-portal
sudo chown -R www-data:www-data /var/www/ptero-portal
sudo find /var/www/ptero-portal -type d -exec chmod 750 {} \;
sudo find /var/www/ptero-portal -type f -exec chmod 640 {} \;
```
Folder hasil ekstrak berisi folder `ptero_portal_full`; pindahkan isi folder tersebut ke document root atau sesuaikan `root` Nginx.

## 2. Konfigurasi environment
Atur nilai di konfigurasi PHP-FPM / service environment, jangan masukkan key ke frontend.

- `PTERO_PANEL_URL=https://panel.domainkamu.com`
- `PTERO_PTLA_KEY=ptla_...`
- `PTERO_ADMIN_PASSWORD=buat-password-random-panjang`

Jika memakai PHP-FPM, environment tidak selalu diteruskan otomatis dari shell. Cara paling rapi adalah membuat pool khusus di `/etc/php/8.2/fpm/pool.d/ptero-portal.conf`, misalnya:

```ini
[ptero-portal]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm-ptero-portal.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 5
clear_env = yes
env[PTERO_PANEL_URL] = https://panel.domainkamu.com
env[PTERO_PTLA_KEY] = ptla_ganti_dengan_key_asli
env[PTERO_ADMIN_PASSWORD] = ganti_dengan_password_random_panjang
php_admin_flag[display_errors] = off
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_samesite] = Lax
```

**Jangan commit file pool yang berisi key ke Git atau membagikannya.** Batasi izin file konfigurasi, lalu:
```bash
sudo chown root:root /etc/php/8.2/fpm/pool.d/ptero-portal.conf
sudo chmod 600 /etc/php/8.2/fpm/pool.d/ptero-portal.conf
sudo systemctl restart php8.2-fpm
```
Sesuaikan socket dan versi PHP pada konfigurasi Nginx.

## 3. Nginx minimal
Contoh server block; ganti domain dan socket sesuai setup kamu. Tambahkan sertifikat TLS dengan Certbot atau penyedia TLS tepercaya.

```nginx
server {
    listen 80;
    server_name portal.domainkamu.com;
    root /var/www/ptero-portal/ptero_portal_full;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm-ptero-portal.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Setelah mengaktifkan HTTPS, paksa redirect HTTP ke HTTPS. Cookie `Secure` akan aktif otomatis ketika PHP menerima request HTTPS; pastikan konfigurasi reverse proxy meneruskan HTTPS dengan benar.

## 4. PTLA dan izin
Buat Application API key di panel admin dan berikan hanya izin yang dibutuhkan:
- Users: read/create sesuai kebutuhan.
- Servers: read/create/delete sesuai kebutuhan.
- Nodes: read.
- Locations: read.
- Eggs: read pada node yang dipilih.

Gunakan key terpisah dengan hak minimum jika panel mendukungnya. Key PTLA adalah kredensial administrator berisiko tinggi.

## 5. PTLC
Masukkan Client API key melalui dashboard setelah login. Key disimpan di sesi PHP dan tidak masuk ke file konfigurasi. Endpoint Client API hanya bisa mengakses server yang diizinkan key tersebut. Logout akan menghapus sesi portal.

## Catatan dan keterbatasan
- **Buat server:** Allocation ID harus benar-benar tersedia dan kompatibel dengan node yang dipilih. Form menampilkan node, tetapi saat ini egg dan allocation ID dimasukkan manual; pemilih egg/allocation dinamis dan validasi keterkaitan node masih perlu ditambahkan.
- **Environment:** JSON harus sesuai persis dengan variabel yang diminta egg. Contoh di form hanya contoh Minecraft Java, bukan konfigurasi universal.
- **Pagination:** halaman awal hanya membaca sampai 100 item; pagination penuh belum diterapkan.
- **Produksi:** tambahkan rate limit/login lockout, audit log, pembatasan IP admin/VPN, backup, dan pemantauan. Sebaiknya tambah dialog konfirmasi kuat untuk tindakan destruktif.
- Tidak ada key yang disertakan dalam paket. Endpoint dan key belum bisa diuji terhadap panel nyata sebelum kamu mengonfigurasikannya.
