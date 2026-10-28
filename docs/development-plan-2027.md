# Rencana Pengembangan Eolas HRIS 2027

Oct 28, 2026 · @ilmu

## Ringkasan

Eolas HRIS menambah 9 modul dalam 3 fase, supaya setara dengan HRIS enterprise seperti LinovHR dan bisa dijual sebagai paket tambahan. Fase 1 menyempurnakan modul yang sudah ada sebagian (kuning). Fase 2 dan 3 membangun lapisan manajemen talenta (merah) dan LMS.

| Fase | Modul | Alasan urutan |
| --- | --- | --- |
| 1. Penyempurnaan | Aplikasi mobile ESS, Workflow Management, HR Analytics, Document Management | Paling sering ditanyakan saat demo; Workflow dipakai semua modul berikutnya |
| 2. Kinerja & kompetensi | Performance Management/KPI, Competency Management | Data KPI dan kompetensi menjadi dasar Career Path, Succession, dan LMS |
| 3. Pengembangan talenta | Career Path, Succession Management, LMS | Membutuhkan data dari Fase 2 |

Prinsip pengembangan:

- **Pakai ulang data yang sudah ada**: struktur organisasi, MPP, data karyawan, Sertifikasi & Izin, Input Variabel payroll, dan Audit Log.
- **Tetap jalan di server klien (on-premise)**: tidak bergantung pada layanan cloud, kecuali siaran live YouTube di LMS.
- **Modular**: setiap modul bisa diaktifkan per lisensi, supaya bisa dijual sebagai paket tambahan.
- **Satu mesin persetujuan**: semua pengajuan baru memakai Workflow Management dari Fase 1.

## Fase 1 — Penyempurnaan modul kuning

### 1.1 Aplikasi mobile ESS

Rekomendasi: mulai dari **PWA** (aplikasi web yang bisa dipasang di layar HP), bukan aplikasi native di Play Store. Setiap klien punya server sendiri, sehingga PWA langsung jalan dari alamat server klien tanpa pengaturan tambahan.

- Clock-in/out dengan GPS, foto selfie, dan batas lokasi (geofence) per lokasi kerja
- Antrean absen saat sinyal hilang, terkirim otomatis saat online (penting untuk site tambang dan pabrik)
- Pengajuan cuti, dinas, lembur, klaim benefit, dan pinjaman
- Slip gaji, bukti potong 1721-A1, jadwal cicilan, dan sertifikat
- Menu persetujuan untuk atasan
- Notifikasi push

Selesai bila: karyawan bisa absen dan mengajukan cuti dari HP tanpa membuka versi desktop.

### 1.2 Workflow Management

Mesin persetujuan bertingkat yang sekarang ada di payroll dijadikan umum untuk semua jenis pengajuan.

- Alur persetujuan diatur per jenis pengajuan: cuti, dinas, lembur, klaim, pinjaman, update data, dan rekrutmen
- Penyetuju ditentukan dari jabatan di struktur organisasi, bukan nama orang
- Syarat bercabang, misalnya klaim di atas nominal tertentu harus disetujui direktur
- Delegasi saat penyetuju cuti, dan eskalasi bila melewati batas waktu
- Semua keputusan tercatat di Audit Log

Selesai bila: HR bisa membuat alur baru tanpa bantuan programmer.

### 1.3 HR Analytics

Dashboard ringkasan dikembangkan menjadi halaman analitik dengan filter lokasi, departemen, dan periode.

- Jumlah karyawan, karyawan masuk, dan turnover per bulan
- Absensi, keterlambatan, dan jam lembur
- Biaya gaji per departemen dan lokasi
- Pemenuhan MPP: rencana dibanding aktual
- Waktu rekrutmen per lowongan
- Sertifikat yang akan habis
- Ekspor ke Excel dan PDF, mengikuti hak akses per lokasi

Selesai bila: direksi bisa melihat tren 12 bulan tanpa meminta laporan manual ke HR.

### 1.4 Document Management

Lampiran dokumen per karyawan dikembangkan menjadi pusat dokumen perusahaan dan karyawan.

- Kategori dokumen: kontrak kerja/PKWT, SK, PP/PKB, SOP, dan surat peringatan
- Riwayat versi dan masa berlaku, dengan pengingat yang memakai mesin Sertifikasi & Izin
- **Pembuatan dokumen dari template** (mail merge): SK, PKWT, dan surat peringatan langsung dari data karyawan
- Karyawan membaca dan menyetujui kebijakan baru lewat portal
- Hak akses per kategori dan per lokasi

Selesai bila: HR bisa membuat SK untuk 50 karyawan sekaligus dari satu template.

## Fase 2 — Performance Management / KPI

Modul ini dibangun pertama di Fase 2, karena skor kinerja dipakai untuk bonus, promosi, dan pemetaan talenta.

**Alur satu periode penilaian:**

1. HR membuka periode, misalnya semester atau tahunan, dan memilih karyawan yang dinilai.
2. KPI perusahaan diturunkan ke departemen, lalu ke individu, mengikuti struktur organisasi.
3. Atasan dan karyawan menyepakati KPI, bobot, dan target.
4. Realisasi diisi selama periode: manual, impor Excel, atau ditarik dari modul lain seperti absensi.
5. Karyawan menilai diri sendiri, lalu atasan menilai. Penilaian 360° dari rekan bersifat opsional.
6. HR dan manajemen melakukan kalibrasi skor antar-departemen.
7. Skor akhir dikunci, karyawan bisa melihat hasilnya di portal.

**Fitur utama:**

- Pustaka KPI per jabatan, bisa dipakai ulang setiap periode
- Rumus skor: semakin tinggi semakin baik, atau semakin rendah semakin baik (misalnya angka kecelakaan kerja)
- Grade akhir, misalnya A sampai E, dengan rentang skor yang bisa diatur
- Catatan coaching dan rencana perbaikan kinerja (PIP)
- Persetujuan target dan hasil memakai Workflow Management

**Terhubung ke:**

- **Payroll**: bonus kinerja masuk otomatis ke Input Variabel
- **Succession**: skor KPI menjadi sumbu kinerja pada matriks 9-box
- **Career Path**: KPI minimal sebagai syarat promosi

## Fase 2 — Competency Management

Modul ini menjawab satu pertanyaan: kemampuan apa yang dibutuhkan setiap jabatan, dan seberapa jauh karyawan saat ini dari kebutuhan itu.

**Fitur utama:**

- **Kamus kompetensi**: kompetensi inti, manajerial, dan teknis, masing-masing dengan level 1–5 dan contoh perilaku di setiap level
- **Profil kompetensi per jabatan**: level yang dibutuhkan, terhubung ke struktur organisasi dan MPP
- **Sertifikasi wajib per jabatan**: memakai modul Sertifikasi & Izin yang sudah ada, misalnya STR untuk perawat atau sertifikat K3 untuk pengawas
- **Penilaian kompetensi**: oleh karyawan sendiri dan atasan, bisa dijadwalkan bersamaan dengan periode KPI
- **Analisis gap**: per karyawan, per departemen, dan per jabatan
- **Rencana pengembangan individu (IDP)**: dari gap yang ditemukan

**Terhubung ke:**

- **LMS**: gap kompetensi otomatis menyarankan course yang relevan
- **Succession**: skor kompetensi menjadi sumbu potensi pada matriks 9-box
- **Career Path**: level kompetensi sebagai syarat naik jabatan
- **Rekrutmen**: profil kompetensi jabatan dipakai sebagai kriteria penilaian kandidat

## Fase 3 — Career Path dan Succession Management

Kedua modul dibangun bersamaan karena memakai data yang sama: skor KPI, level kompetensi, dan sertifikat.

### Career Path

- **Jenjang jabatan** per rumpun pekerjaan, misalnya Operator → Leader → Supervisor → Superintendent
- **Syarat naik jabatan** yang bisa diatur: masa kerja, skor KPI minimal, level kompetensi, dan sertifikat wajib
- **Tingkat kesiapan (%)** setiap karyawan terhadap jabatan berikutnya, dihitung otomatis dari syarat di atas
- **Aspirasi karier** diisi karyawan lewat portal: jabatan yang diincar dan minat rotasi
- **Usulan promosi, mutasi, dan rotasi** lewat Workflow Management; setelah disetujui, data karyawan dan payroll terbarui otomatis

### Succession Management

- **Posisi kritikal** ditandai dari struktur organisasi dan MPP
- **Matriks 9-box**: sumbu kinerja dari skor KPI, sumbu potensi dari skor kompetensi
- **Talent pool** untuk karyawan berpotensi tinggi
- **Kandidat pengganti** per posisi kritikal, dengan status kesiapan: siap sekarang, siap 1–2 tahun, atau siap 3 tahun lebih
- **Risiko kekosongan**: posisi kritikal tanpa pengganti siap, atau pejabat yang mendekati pensiun
- **Rencana pengembangan** untuk setiap kandidat, terhubung ke LMS

Selesai bila: direksi bisa melihat posisi kritikal mana saja yang belum punya pengganti siap, dalam satu halaman.

## Fase 3 — Learning Management System (LMS)

LMS Eolas mengikuti LMS yang sudah kamu miliki: course berisi soal, video, sertifikat, podcast, siaran live YouTube, dan game interaktif. Langkah pertama tim Production adalah meninjau kode LMS yang ada, lalu memutuskan apakah dipindahkan ke Eolas atau dihubungkan sebagai modul.

| Bagian | Isi |
| --- | --- |
| Course | Susunan bab dan materi, urutan wajib atau bebas, syarat lulus per course |
| Video pembelajaran | Unggah ke server klien atau tautan YouTube; progres tonton tercatat |
| Podcast | Pemutar audio di dalam course, bisa didengar dari HP |
| Live YouTube | Jadwal sesi, pengingat ke peserta, siaran live tersemat, rekaman tersimpan di course |
| Soal | Bank soal; pilihan ganda, benar-salah, dan esai; soal diacak; batas waktu; nilai lulus; batas percobaan |
| Sertifikat | Template, nomor otomatis, kode QR verifikasi; otomatis masuk ke modul Sertifikasi & Izin beserta masa berlakunya |
| Game interaktif | Kuis live antar karyawan, poin, lencana, dan papan peringkat per departemen |

**Yang membuat LMS ini lebih dari LMS biasa** (karena tersambung dengan HRIS):

- **Penugasan otomatis**: course wajib dikirim ke karyawan baru, ke jabatan tertentu, atau ke karyawan yang punya gap kompetensi
- **Pelatihan wajib dengan masa berlaku**, misalnya K3 tahunan; pengingat ulang sebelum sertifikat habis
- **Laporan pelatihan** per karyawan, departemen, dan lokasi untuk kebutuhan audit
- Diakses dari aplikasi mobile ESS (Fase 1)

Catatan: siaran live YouTube tetap membutuhkan internet, walaupun Eolas diinstal di server klien.

## Timeline, harga, dan keputusan

Perkiraan: ketiga fase selesai dalam tiga kuartal, Januari–September 2027. Angka ini masih asumsi karena kapasitas tim Production belum dihitung.

&#91;embedded content: peta jalan pengembangan · 3 fase, 9 modul\]

Fase 2 harus selesai sebelum Career Path dan Succession, karena matriks 9-box membutuhkan skor KPI dan kompetensi.

**Dampak ke paket harga** (draf, perlu divalidasi ke calon klien):

| Paket | Isi | Usulan harga lisensi |
| --- | --- | --- |
| Core | Modul yang ada sekarang + seluruh Fase 1 | Sesuai tier Starter–Enterprise yang sudah disepakati |
| Talent (tambahan) | KPI, Competency, Career Path, Succession | +30–40% dari lisensi Core |
| LMS (tambahan) | Course, soal, video, podcast, live, sertifikat, game | +20–25% dari lisensi Core |

Fase 1 sebaiknya masuk paket Core tanpa biaya tambahan, karena pesaing sudah menyertakan aplikasi mobile dan alur persetujuan di paket dasarnya.

**Keputusan (28 Okt 2026):**

- [x] Aplikasi mobile: **PWA dulu**; dibungkus TWA bila nanti perlu Play Store.
- [x] LMS lama: ditinjau dulu; bila stack berbeda, **dihubungkan sebagai modul terpisah** (login tunggal + sinkron data).
- [x] Kapasitas: Fase 1 butuh 13 minggu + 1 minggu cadangan (sedikit lebih dari satu kuartal).
- [x] Paket Talent dan LMS: **dijual terpisah**, diskon bila dibeli bersama.
- [x] Regresi penuh dan rilis `1.0.0` sebelum Fase 1 dimulai.

Rencana mingguan Fase 1: [phase-1-plan.md](phase-1-plan.md).
