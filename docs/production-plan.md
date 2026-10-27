# Rencana Pengembangan Production — Eolas HRIS v1.0

> Disusun 27 Okt 2026 oleh tim Production, menanggapi Rencana Marketing Q4 2026.
> Basis: branch `eloas`, payroll fase 2 sudah live (mesin BPJS/PPh21, potong gapok, simulasi gaji).
> Rincian aturan payroll ada di [payroll-plan.md](payroll-plan.md); dokumen ini mengatur **urutan, isi, dan jadwal** semua pekerjaan.

## Status

- **27 Okt 2026:** minggu 1 dan 2 selesai: server demo siap iklan (captcha `/demo` & `/login`, batas 30 workspace, pembersihan otomatis), aplikasi demo 504 karyawan, payroll fase 3 (proses gaji, persetujuan, slip PDF, portal, rekap), 2FA untuk akun payroll, audit log, matriks fitur untuk Marketing. Berikutnya: minggu 3.
- **27 Okt 2026 (lanjutan):** minggu 3 selesai: payroll fase 4 (komponen & Input Variabel dengan import Excel dan persetujuan, Pinjaman Karyawan dengan jadwal angsuran, tunda, pelunasan, pengajuan dari portal), edisi klien (`EOLAS_EDITION=client`) dan lisensi Ed25519 (`eolas:license:issue`, `eolas:license:check`, batas karyawan aktif, halaman Lisensi). Kunci privat lisensi: `/root/eolas-license/eolas-license-private.key` di server (wajib dibackup di luar server). Berikutnya: minggu 4.
- **27 Okt 2026 (lanjutan 2):** minggu 4 selesai: payroll fase 5 (THR per hari raya, final pay, tarik deklarasi/Cuti Besar/lumpsum ke Input Variabel) dan **paket pilot on-premise 1.0.0-pilot.1**: `installer/` (install.sh, compose klien + Caddy HTTPS, eolas-backup/restore/update dengan rollback otomatis, PANDUAN.md), dibangun dengan `installer/build-package.sh <versi>` ke `dist/`. Diuji di server bersih (Docker-in-Docker): instalasi, backup, update, update gagal → rollback, restore. Image tidak lagi memuat file .sql/dokumen internal. Belum: settlement uang muka dinas (data realisasi belum ada). Berikutnya: minggu 5.
- **27 Okt 2026 (lanjutan 3):** minggu 5 selesai: payroll fase 6 (file transfer umum/BCA/Mandiri, iuran BPJS, PPh 21 BPMP Coretax, jurnal per lokasi, Laporan Tahunan 1721-A1 + ekspor BPA1 Coretax, ringkasan 1721-A1 di portal, widget biaya gaji di dashboard) dan modul Sertifikasi & Izin Kerja (jenis STR/SIP/SIMPER/KIMPER/POP/SKK/K3/SIO/Halal, scan, pengingat 90/60/30/0 hari ke HR dan karyawan, data demo per industri, kartu dashboard, portal). Format bank & Coretax disusun dari template umum: **wajib dicocokkan dengan template terbaru bank / DJP** sebelum klien pertama. Berikutnya: minggu 6 (upah borongan garmen, data demo payroll 3 bulan, regresi penuh → v1.0).

## Keputusan (27 Okt 2026)

| Topik | Keputusan |
|---|---|
| Payroll | **Diprioritaskan.** Fase 3–6 diselesaikan semua sebelum v1.0. |
| Domain | Diganti nanti. SEO dan Search Console menunggu domain baru. |
| Platform klien on-premise | **Linux + Docker saja** untuk sekarang. Windows Server tidak didukung. |

## Target

**Eolas v1.0 (8 Des 2026).** Isinya:

- HRIS lengkap: HR inti, kehadiran, dan **payroll end-to-end** (run bulanan, slip, THR, pinjaman, laporan bank/BPJS/pajak).
- Server demo yang aman untuk iklan.
- Paket instalasi on-premise berlisensi untuk Linux.
- Fitur khusus rumah sakit (STR/SIP) dan garmen (upah borongan).

**Paket pilot on-premise:** 24 Nov 2026.

---

## A. Payroll (prioritas utama)

Penamaan tabel dan kode mengikuti refactor (English); teks UI tetap Bahasa Indonesia.

### A1. Fase 3: Proses gaji bulanan (28 Okt – 10 Nov)

| Bagian | Isi |
|---|---|
| **Tabel** | `payroll_runs`, `payslips`, `payslip_lines` |
| **`payroll_runs`** | periode, jenis (`regular`/`thr`/`bonus`/`final`), lokasi (null = semua lokasi), cutoff, tanggal bayar, status, dan siapa/kapan untuk tiap langkah |
| **`payslips`** | Snapshot jabatan, lokasi, PTKP, kategori TER, NPWP, rekening, hari kerja. Total bruto, potongan, PPh21, take home pay, dan iuran perusahaan. |
| **`payslip_lines`** | Komponen dengan nama snapshot, nominal, kena pajak, dasar BPJS, dan ref sumber (lembur/input/angsuran) |
| **Mesin** | `PayrollCalculator::month()` (sudah ada) menjadi dasar `PayrollRun::calculate()`. Ditambah:<ul><li>pro-rata masuk/keluar di tengah periode (hari kalender, atau hari kerja roster);</li><li>batas bawah UMK untuk dasar BPJS Kes, dan iuran anggota keluarga tambahan;</li><li>Desember: PPh setahun memakai PPh Jan–Nov **dari slip tersimpan**, bukan hitung ulang.</li></ul> |
| **Antrean** | Hitung run lewat queue job dengan progres. Target: 500 karyawan < 60 detik. |
| **Alur status** | `draft → calculated → submitted → approved → paid → locked`<ul><li>Hitung ulang hanya bisa di `draft`/`calculated`.</li><li>Run yang terkunci tidak bisa diubah; koreksinya menjadi baris penyesuaian di periode berikut.</li><li>Satu karyawan hanya boleh ada di satu run reguler per periode.</li></ul> |
| **Review** | Tabel hasil per karyawan dengan drill-down slip.<ul><li>Peringatan: rekening kosong, NPWP/NIK tidak valid, gaji pokok belum ada, selisih > 10% dari bulan lalu.</li><li>Ringkasan per lokasi/departemen.</li></ul> |
| **Slip PDF** | `barryvdh/laravel-dompdf`; PDF berpassword tanggal lahir (opsi di pengaturan). |
| **Portal** | Self-Service → **Slip Gaji**: daftar slip dari run berstatus `paid`/`locked`, unduh PDF. |
| **Rekap** | Ekspor Excel per run (`TableExcel`): rekap per karyawan, per komponen, per lokasi/departemen. |
| **Akses** | Menu baru:<ul><li>`payroll-run`: lihat / ubah = hitung & ajukan;</li><li>`payroll-approval`: setuju, kunci, tandai dibayar.</li></ul>Dibatasi lokasi lewat `AccessScope`. Pemisahan tugas: pembuat run tidak bisa menyetujui run-nya sendiri. |
| **Keamanan** | Tabel `audit_logs`: lihat slip orang lain, ubah gaji, setujui/kunci run, unduh rekap/file bank. **2FA TOTP wajib** untuk akun bermenu payroll. |

**Selesai bila:** satu run reguler 500 karyawan demo bisa dihitung, direview, disetujui, dikunci; slip muncul di portal dan PDF-nya terunduh; Desember konsisten dengan Jan–Nov tersimpan.

### A2. Fase 4: Input variabel & pinjaman (11 – 17 Nov)

- **`payroll_components`** (master): kode, nama, jenis (pendapatan/potongan), sifat (tetap/tidak tetap/tidak teratur), flag kena pajak, dasar BPJS, dasar THR, dan urutan di slip.
  - Seed bawaan: Bonus, Insentif, Komisi, Reimbursement, Uang Makan, Koreksi.
- **`payroll_inputs`**: input per karyawan per periode. Bisa diinput manual atau di-**import Excel**, dengan persetujuan dan lampiran. Input yang disetujui otomatis masuk ke run.
- **`loans` / `loan_installments`**:
  - jadwal angsuran otomatis, dipotong di setiap run;
  - pelunasan lebih cepat dan penundaan satu periode;
  - validasi batas potongan (maks. 50% gaji bersih, bisa diatur).
- **Portal:** karyawan mengajukan pinjaman dan reimbursement, dengan notifikasi ke approver memakai pola pengajuan yang sudah ada.

### A3. Fase 5: THR, final pay, integrasi modul (18 – 24 Nov)

- **Run THR** per hari raya, berdasarkan agama di profil: Idulfitri, Natal, Nyepi, Waisak, Imlek.
  - Proporsional masa kerja (Permenaker 6/2016).
  - Simulasi sebelum dihitung; slip THR.
  - Pajak memakai TER bulan dibayar, atas gabungan gaji + THR.
- **Run final** untuk karyawan keluar: sisa gaji pro-rata, PPh21 setahun di bulan keluar, dan pelunasan sisa pinjaman.
- **Sumber penghasilan lain** yang otomatis menjadi input:
  - Cuti Besar diuangkan;
  - lumpsum cuti lapangan;
  - selisih settlement uang muka dinas;
  - deklarasi benefit yang disetujui (dibayar lewat payroll, kena PPh21, sesuai keputusan 24 Okt).

### A4. Fase 6: Laporan (25 Nov – 1 Des)

| Laporan | Format |
|---|---|
| File transfer bank | Excel/CSV umum + format **BCA** dan **Mandiri** (bank lain menyusul sesuai klien) |
| Iuran BPJS Ketenagakerjaan & Kesehatan | Rekap per program per lokasi; format upload SIPP / e-Dabu |
| PPh21 bulanan | Format impor **Coretax** (Bukti Potong 21 bulanan) |
| 1721-A1 | PDF per karyawan + format impor Coretax; tampil juga di portal |
| Jurnal gaji | Excel per akun (mapping akun per komponen di master) |
| Dashboard | Widget biaya gaji 12 bulan, komposisi, dan lembur, untuk akun bermenu payroll |

### A5. Data demo payroll (berjalan bersama A1–A4)

- `EolasDemoSeeder` membuat riwayat run **3 bulan terakhir berstatus terkunci** untuk 500 karyawan.
- Hasilnya: slip, rekap, dan laporan langsung terlihat saat demo.
- Workspace kosong (sesi awal) hanya mendapat master komponen dan tarif.
- Waktu provisioning dijaga ≤ 2 menit; bila lewat, run contoh dibuat setelah workspace aktif.

---

## B. Server demo siap iklan (28 Okt – 3 Nov)

| Item | Isi |
|---|---|
| **Anti-spam `/demo`** | Honeypot + rate limit per IP (sekarang). Captcha Cloudflare Turnstile (perlu site key dari akun Cloudflare). |
| **Batas kapasitas** | Maks. 30 workspace aktif (`config/eolas.php`). Bila penuh, pendaftar masuk daftar tunggu dan admin diberi notifikasi. |
| **Pembersihan otomatis** | `php artisan eolas:prune-workspaces`: drop workspace yang berakhir > 7 hari. Dijalankan service `scheduler` baru di `docker-compose.yml`. |
| **Verifikasi email** | Ditunda sampai email bisnis/domain baru ada. Saat ini `MAIL_MAILER=log`, jadi email tidak terkirim. |
| **Aplikasi utama** | Diisi ulang dengan data 500 karyawan (setelah backup). |
| **Uji isolasi tenant** | Feature test: workspace A tidak bisa membuka data, lampiran, maupun slip workspace B. |

---

## C. Paket instalasi on-premise — Linux (11 – 24 Nov)

| Item | Isi |
|---|---|
| **Edisi klien** | `EOLAS_EDITION=client`: satu perusahaan, tanpa landing, `/demo`, trial, maupun workspace. Login langsung ke sesi awal. |
| **Lisensi** | File `license.json` ditandatangani Ed25519 (`sodium`). Isinya: nama perusahaan, tier, batas karyawan aktif, dan masa support. Public key ditanam di aplikasi; private key hanya di tim Production.<ul><li>Karyawan melebihi batas → tidak bisa menambah karyawan.</li><li>Support habis → aplikasi tetap jalan, tetapi update ditolak.</li><li>Banner di Pengaturan.</li></ul> |
| **Generator lisensi** | Perintah internal `eolas:license:issue` (tidak ikut dikirim ke klien). |
| **Installer** | `install.sh` untuk Ubuntu 22.04/24.04 dan Debian 12:<ul><li>cek spesifikasi;</li><li>pasang Docker;</li><li>buat `.env` dan password acak;</li><li>pasang lisensi;</li><li>`docker compose up`;</li><li>HTTPS (Caddy/Let's Encrypt atau sertifikat klien).</li></ul> |
| **Distribusi** | Image Docker siap pakai (`docker save` tarball atau registry privat). Klien tidak build dari source. |
| **Backup & update** | `eolas-backup` harian (mysqldump + storage, retensi 7/4/6). `eolas-update`: backup otomatis → ganti image → migrate → cek kesehatan → rollback bila gagal. |
| **Dokumen** | Spesifikasi minimum (2 vCPU, 4 GB RAM, 40 GB), panduan instalasi, admin, backup/restore, dan troubleshooting. |
| **Proteksi kode** | ionCube ditunda (di luar anggaran). Perlindungan lewat kontrak + lisensi bertanda tangan; kode dikirim sebagai image Docker. |
| **Uji** | Pasang dari nol di VM bersih, import data contoh, jalankan payroll, update versi, lalu restore backup. |

---

## D. Fitur industri

- **Rumah sakit – STR/SIP** (25 Nov – 1 Des).
  - Dibuat sebagai modul umum **Lisensi & Sertifikasi**, karena juga berguna untuk tambang (SIMPER, KIMPER, POP/POM) dan konstruksi (SKK).
  - Jenis dokumen bisa diatur; nomor, terbit, berlaku sampai, lampiran.
  - Pengingat 90/60/30 hari ke HR dan karyawan, tampilan "akan habis" di dashboard, data demo per industri.
- **Garmen – upah borongan** (2 – 8 Des, setelah A2).
  - Tarif per operasi/produk; input hasil harian per karyawan (manual atau import Excel).
  - Hasil menjadi komponen payroll; jaminan upah minimum (top-up ke UMK bila hasil di bawahnya).
  - Data demo garmen.

## E. Pendukung Marketing

- **Matriks fitur "siap vs roadmap"** dan naskah demo 30 menit: 4 Nov, diperbarui di setiap rilis.
- **Meta description dan Open Graph** di halaman publik: 1 hari, dikerjakan bersama perpindahan domain. Sitemap dan Search Console juga setelah domain.

---

## Jadwal

| Minggu | Tanggal | Payroll | Lainnya |
|---|---|---|---|
| 1 | 28 Okt – 3 Nov | A1: tabel, mesin run, pro-rata, antrean | **B** demo siap iklan |
| 2 | 4 – 10 Nov | A1: persetujuan, kunci, slip PDF, portal, rekap, 2FA + audit | E: matriks fitur |
| 3 | 11 – 17 Nov | A2: komponen, input variabel, pinjaman | C: edisi klien, lisensi |
| 4 | 18 – 24 Nov | A3: THR, final pay, integrasi | C: installer, backup/update, dokumen → **paket pilot** |
| 5 | 25 Nov – 1 Des | A4: laporan bank/BPJS/Coretax/1721-A1/jurnal | D: STR/SIP |
| 6 | 2 – 8 Des | A5 dirapikan, regresi penuh | D: borongan garmen → **v1.0** |

**Cara kerja setiap fase:**

1. Unit/feature test (contoh resmi DJP & BPJS untuk angka).
2. Dry run migrasi di MySQL salinan data live, termasuk provisioning workspace baru.
3. E2E Playwright.
4. Deploy dengan tag image rollback (`pre-payroll3`, dst.).
5. Push ke `alieilmu/eolas-hris`.

HC Puspita produksi tidak disentuh.

## Keputusan tambahan (27 Okt 2026)

| # | Topik | Keputusan |
|---|---|---|
| 1 | Format file bank | Umum + BCA + Mandiri |
| 2 | Captcha | Cloudflare Turnstile (akun Cloudflare sudah dibuat). Site key & secret key diisi di `.env` (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`); tanpa key, honeypot + rate limit tetap jalan |
| 3 | ionCube | **Ditunda** (di luar anggaran). Paket on-premise dilindungi kontrak dan lisensi saja |
| 4 | Reseed aplikasi utama demo ke 500 karyawan | Ya, setelah backup (minggu 1) |
| 5 | Slip PDF berpassword | Ya, password = tanggal lahir (ddmmyyyy) |

## Risiko

- **Kapasitas server bersama** (2 CPU, sisa RAM ±1,4 GB). Run payroll 500 karyawan dan provisioning demo lewat antrean yang sama. Bila iklan berjalan, pertimbangkan VPS demo terpisah.
- **Format Coretax/SIPP bisa berubah.** Format disimpan sebagai template yang mudah diganti, dan diuji dengan file contoh resmi terbaru sebelum rilis.
- **Pajak dan BPJS** diuji terhadap contoh resmi. Tetap perlu review oleh praktisi payroll/pajak sebelum klien pertama go-live.
