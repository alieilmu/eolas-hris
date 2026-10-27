# Panduan Instalasi & Administrasi Eolas HRIS (On-Premise)

Panduan ini untuk tim IT perusahaan yang memasang Eolas HRIS di server sendiri. Semua perintah dijalankan sebagai `root` (atau dengan `sudo`).

## 1. Kebutuhan server

| | Minimum | Disarankan (> 300 karyawan) |
|---|---|---|
| Sistem operasi | Ubuntu 22.04 / 24.04 LTS, Debian 12 | sama |
| CPU | 2 vCPU | 4 vCPU |
| RAM | 4 GB | 8 GB |
| Disk | 40 GB | 80 GB SSD |
| Jaringan | Port 80 dan 443 terbuka untuk pengguna | sama |

- Windows Server **tidak** didukung. Gunakan VM Linux di atas Hyper-V/VMware bila server fisiknya Windows.
- Server boleh tanpa internet: paket berisi semua image yang dibutuhkan. Internet hanya diperlukan untuk memasang Docker (bila belum ada) dan untuk sertifikat Let's Encrypt.
- Mesin absensi (ADMS: ZKTeco, Solution, Fingerspot) diarahkan ke alamat server ini, port 80, path `/iclock/`.

## 2. Persiapan

1. Tentukan alamat aplikasi:
   - **Domain publik** (mis. `hris.perusahaan.co.id`) → HTTPS otomatis dari Let's Encrypt. Arahkan DNS domain ke IP server, dan buka port 80 & 443 dari internet.
   - **Hanya jaringan kantor** (domain internal atau IP) → HTTPS dengan sertifikat internal, atau sertifikat dari CA perusahaan (`certs/cert.pem` + `certs/key.pem` di folder paket).
2. Siapkan file **`license.json`** dari Eolas (bisa juga dipasang setelah instalasi).
3. Salin paket `eolas-hris-<versi>.tar.gz` ke server, lalu ekstrak:

```bash
tar -xzf eolas-hris-1.0.0.tar.gz && cd eolas-hris-1.0.0
```

## 3. Instalasi

```bash
sudo ./install.sh
```

Installer akan:

1. memeriksa sistem operasi, CPU, RAM, dan disk;
2. memasang Docker bila belum ada;
3. menanyakan nama perusahaan, alamat aplikasi, mode HTTPS, serta nama, email, dan password Super Admin;
4. memasang aplikasi di `/opt/eolas` dengan password database acak;
5. menyiapkan data awal dan akun Super Admin, lalu memasang lisensi;
6. mengaktifkan backup harian pukul 02:30 ke `/var/backups/eolas`.

Untuk instalasi otomatis tanpa tanya jawab:

```bash
sudo EOLAS_ADMIN_PASSWORD='...' ./install.sh --non-interactive \
  --company "PT Contoh Sejahtera" --domain hris.contoh.co.id --tls letsencrypt \
  --admin-name "Admin HR" --admin-email hr@contoh.co.id --license /root/license.json
```

Pilihan `--tls`:

| Mode | Kapan dipakai |
|---|---|
| `letsencrypt` | Domain publik |
| `internal` | Jaringan kantor; pasang root CA Caddy di komputer pengguna |
| `custom` | Sertifikat dari CA perusahaan |
| `none` | HTTP saja, hanya untuk uji coba |

## 4. Setelah instalasi

1. Buka alamat aplikasi, lalu login dengan email Super Admin.
2. **Aktifkan Verifikasi 2 Langkah (2FA)** di menu kiri bawah. Akun dengan menu payroll wajib memakainya.
3. Ikuti **Sesi Awal**:
   - profil perusahaan;
   - format Nomor Induk (NRP);
   - struktur organisasi;
   - import data karyawan dari Excel.
4. Atur pengguna lain di **Administrasi → Pengguna & Akses** (menu dan lokasi per akun).
5. Cek **Administrasi → Lisensi**: nama perusahaan, batas karyawan aktif, dan masa support.
6. Simpan salinan **`/opt/eolas/.env`** di tempat aman, misalnya password manager perusahaan. File ini berisi kunci aplikasi; tanpa kunci ini, 2FA dan data terenkripsi tidak bisa dipulihkan.

## 5. Lisensi

- Lisensi menentukan **jumlah karyawan aktif maksimum** dan **masa support**.
- Bila batas karyawan tercapai, karyawan baru tidak bisa ditambahkan; karyawan yang sudah ada tetap berjalan normal.
- Bila masa support habis, aplikasi tetap berjalan, tetapi update tidak bisa dipasang.
- Lisensi baru (perpanjangan atau penambahan kapasitas) dipasang di **Administrasi → Lisensi → Ganti lisensi**.
- Cek dari server:

```bash
docker exec -u www-data eolas-app php artisan eolas:license:check
```

## 6. Backup & pemulihan

- Backup otomatis berjalan setiap hari pukul 02:30. Isinya database, file (lampiran, logo, lisensi), dan `.env`.
- Retensi: 7 backup harian, 4 mingguan, 6 bulanan, di `/var/backups/eolas`.
- **Salin folder backup ke luar server** (NAS, storage cloud perusahaan). Backup di server yang sama tidak melindungi dari kerusakan disk.

Backup manual:

```bash
sudo eolas-backup
```

Memulihkan backup (data sekarang diganti):

```bash
sudo eolas-restore /var/backups/eolas/daily/20261201-023000
```

Status jadwal backup:

```bash
systemctl list-timers eolas-backup.timer
```

Disarankan **uji pemulihan setiap 3 bulan** di server uji.

## 7. Update

Eolas mengirim paket versi baru (`eolas-hris-<versi>.tar.gz`). Ekstrak, lalu:

```bash
sudo eolas-update /path/eolas-hris-1.1.0
```

`eolas-update` akan:

1. memeriksa lisensi dan masa support;
2. membuat backup;
3. memuat versi baru;
4. menjalankan migrasi database otomatis;
5. memeriksa kesehatan aplikasi.

Bila versi baru gagal, aplikasi **otomatis kembali** ke versi lama beserta database dari backup tadi.

## 8. Operasional

| Keperluan | Perintah |
|---|---|
| Status layanan | `docker compose --project-directory /opt/eolas ps` |
| Log aplikasi | `docker logs --tail 100 eolas-app` |
| Log antrean (proses gaji) | `docker logs --tail 100 eolas-queue` |
| Restart | `docker compose --project-directory /opt/eolas restart` |
| Berhenti / mulai | `docker compose --project-directory /opt/eolas stop` / `up -d` |
| Root CA HTTPS internal | `docker exec eolas-caddy cat /data/caddy/pki/authorities/local/root.crt` |

## 9. Keamanan

- Buka ke pengguna hanya port 80 dan 443, plus 22 untuk admin dengan SSH key. Contoh dengan ufw:

```bash
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable
```

- Database tidak dibuka ke luar server; hanya aplikasi yang mengaksesnya di jaringan Docker internal.
- Pasang update keamanan sistem operasi rutin (`apt upgrade`). Docker ikut terupdate dari repositori resminya.
- Aktifkan 2FA untuk semua admin. Pantau **Administrasi → Audit Log** untuk akses ke data gaji.

## 10. Bantuan

Saat menghubungi tim support Eolas, sertakan:

- versi (`cat /opt/eolas/VERSION`);
- nomor lisensi;
- potongan log yang relevan.

**Jangan kirim isi file `.env`.**
