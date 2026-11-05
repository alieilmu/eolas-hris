# Rencana Mingguan Fase 1 — Eolas HRIS 1.1

> Disusun 28 Okt 2026 oleh tim Production, dari [development-plan-2027.md](development-plan-2027.md).
> Isi Fase 1: lisensi per modul, Workflow Management, aplikasi mobile ESS (PWA), HR Analytics, dan Document Management.
> Target rilis: **Eolas 1.1** (paket on-premise `1.1.0`).

## Keputusan (28 Okt 2026)

| Topik | Keputusan |
|---|---|
| Aplikasi mobile | **PWA dulu.** Bila nanti perlu Play Store, PWA dibungkus (TWA) tanpa ditulis ulang. |
| LMS lama | Ditinjau dulu. Bila stack berbeda, dihubungkan sebagai modul terpisah (login tunggal + sinkron data), bukan dipindahkan. |
| Kapasitas | Fase 1 dihitung **13 minggu + 1 minggu cadangan**, sedikit lebih dari satu kuartal karena Workflow. |
| Paket | Talent dan LMS dijual **terpisah**, dengan diskon bila dibeli bersama. Fase 1 masuk paket Core. |
| Prasyarat | **Regresi penuh dan rilis paket `1.0.0`** sebelum Fase 1 dimulai (minggu 0). Upah borongan garmen dan data demo payroll 3 bulan tetap ditunda. |

## Jadwal

Tanggal mengikuti peta jalan (Fase 1 mulai Januari 2027). Bila minggu 0 selesai lebih awal, seluruh jadwal bisa dimajukan.

| Minggu | Tanggal | Isi | Bagian |
|---|---|---|---|
| 0 | sebelum Fase 1 | Regresi penuh, rilis `1.0.0` | Prasyarat |
| 1 | 4 – 8 Jan 2027 | Lisensi per modul; inti mesin Workflow | Fondasi |
| 2 | 11 – 15 Jan | Workflow: syarat, notifikasi, kotak "Persetujuan Saya" | Workflow |
| 3 | 18 – 22 Jan | Migrasi cuti, dinas, lembur ke Workflow | Workflow |
| 4 | 25 – 29 Jan | Migrasi klaim, pinjaman, update data, rekrutmen; delegasi & eskalasi | Workflow |
| 5 | 1 – 5 Feb | Editor alur untuk HR (tanpa programmer) | Workflow |
| 6 | 8 – 12 Feb | Fondasi PWA: pasang di HP, tampilan mobile portal | Mobile ESS |
| 7 | 15 – 19 Feb | Absen dari HP: GPS, geofence, selfie, persetujuan data pribadi | Mobile ESS |
| 8 | 22 – 26 Feb | Antrean absen offline; pengajuan & persetujuan dari HP | Mobile ESS |
| 9 | 1 – 5 Mar | Notifikasi push; uji di HP Android & iPhone | Mobile ESS |
| 10 | 8 – 12 Mar | HR Analytics: data tren 12 bulan dan halaman analitik | Analytics |
| 11 | 15 – 19 Mar | Analytics: ekspor, akses direksi, data demo | Analytics |
| 12 | 22 – 26 Mar | Document Management: kategori, versi, masa berlaku, hak akses | Dokumen |
| 13 | 29 Mar – 2 Apr | Template dokumen massal; persetujuan kebijakan di portal; rilis `1.1.0` | Dokumen + rilis |
| cadangan | 5 – 9 Apr | Perbaikan hasil uji pilot dan HP nyata | — |

---

## Minggu 0 — Prasyarat v1.0

- Regresi penuh: semua feature test, `tsc`, E2E alur utama (login + 2FA, karyawan, absensi, cuti, proses gaji sampai dikunci, slip di portal, laporan).
- Uji installer di **VM Ubuntu 24.04 asli** (bukan Docker-in-Docker): pasang Docker, timer systemd backup, Let's Encrypt.
- Bangun `installer/build-package.sh 1.0.0`, perbarui matriks fitur, tag `v1.0.0`.

**Selesai bila:** paket `1.0.0` terpasang bersih di VM Ubuntu asli dan lolos uji backup → update → restore.

**Status 28 Okt 2026: selesai.** Diuji di container Ubuntu 24.04 dengan systemd asli (bukan VM): pemasangan Docker dari internet, instalasi, lisensi, backup lewat timer, restore, update `1.0.0-pilot.1 → 1.0.0`. Belum diuji: Let's Encrypt dengan domain publik (butuh VPS uji), dan Debian 12 / Ubuntu 22.04.

## Minggu 1 — Lisensi per modul + inti Workflow

**Lisensi per modul**
- Payload lisensi ditambah `modules`, misalnya `["core", "talent", "lms"]`. Lisensi lama tanpa `modules` dibaca sebagai `["core"]`.
- `App\Support\Modules`: daftar modul dan menu yang dimilikinya; edisi SaaS dan workspace demo mendapat semua modul.
- Middleware `module:<nama>` untuk rute; menu yang tidak dibeli disembunyikan di sidebar dan portal.
- `eolas:license:issue --modules=core,talent`; halaman **Lisensi** menampilkan modul aktif.

**Inti mesin Workflow**
- Tabel: `workflow_definitions` (jenis pengajuan, versi, aktif), `workflow_steps` (urutan, tipe penyetuju, syarat), `workflow_instances` (pengajuan, langkah sekarang, status), `workflow_actions` (siapa, keputusan, catatan, waktu).
- Tipe penyetuju:
  - atasan langsung, dari jabatan atasan di struktur organisasi;
  - atasan dari atasan (naik N tingkat);
  - jabatan tertentu;
  - pengguna dengan akses menu di lokasi karyawan (**perilaku sekarang**, dipakai sebagai alur bawaan).
- Bila jabatan penyetuju kosong, naik ke atasan berikutnya; bila sampai puncak tetap kosong, jatuh ke HR.
- `Workflow::start()`, `approve()`, `reject()`, `cancel()`; setiap keputusan masuk Audit Log.

**Selesai bila:** lisensi `core` saja menyembunyikan menu modul tambahan, dan satu pengajuan uji bisa melewati alur 2 langkah (atasan → HR) di test.

**Status 28 Okt 2026: selesai** (dikerjakan lebih awal dari jadwal Januari).
- Lisensi: `App\Support\Modules` (`core`, `talent`, `lms`); menu add-on tertutup lewat `User::menuLevel`, jadi sidebar, rute `menu:` dan halaman awal ikut tertutup; middleware `module:<nama>`; prop `modules` untuk sidebar dan portal (`module` di item menu); `eolas:license:issue --modules=core,talent`; halaman Lisensi dan `eolas:license:check` menampilkan modul. Talent dan LMS belum punya menu; menunya didaftarkan di `Modules::MENUS` saat Fase 2–3.
- Workflow: tabel `workflow_definitions/steps/instances/actions`; `App\Support\Workflow\Workflow` (`start`, `approve`, `reject`, `cancel`, `canDecide`, `canCancel`, `of`) dan `Approvers`. Tipe penyetuju: `superior` (nilai = naik N tingkat; 1 = atasan langsung), `position`, `menu`. Penyetuju dihitung saat langkah dimulai (`current_approvers`); keputusan dikunci per baris supaya tidak dobel; pemohon tidak pernah menyetujui pengajuannya sendiri. Jenis tanpa alur otomatis mendapat alur bawaan 1 langkah HR (menu + lokasi). Model pengajuan yang mengimplementasikan `Approvable` diberi tahu saat alur selesai. Semua keputusan masuk Audit Log (grup "Persetujuan").
- Belum ada modul yang memakai Workflow; migrasi cuti/dinas/lembur di minggu 3.
- Test: `ModuleLicenseTest` (5), `WorkflowTest` (7); total 226 test lolos.

## Minggu 2 — Syarat, notifikasi, kotak persetujuan

- Syarat per langkah: nominal (klaim, pinjaman), jumlah hari (cuti, dinas), lokasi, jenis cuti/klaim, golongan jabatan. Contoh: klaim di atas Rp5 juta menambah langkah direktur.
- Mode langkah: satu orang cukup, atau semua penyetuju di langkah itu harus setuju.
- Notifikasi memakai `RequestNotifier` yang ada: ke penyetuju berikutnya, ke pemohon saat disetujui/ditolak.
- Halaman **Persetujuan Saya**: semua pengajuan yang menunggu keputusan pengguna, lintas jenis, dengan setujui/tolak massal.
- Riwayat persetujuan (siapa, kapan, catatan) tampil di detail setiap pengajuan.

**Status 28 Okt 2026: selesai.**
- Syarat: `App\Support\Workflow\Conditions`, aturan `{field, op, value}` di `workflow_steps.conditions` (semua harus terpenuhi). Field: `amount`, `days`, `category` (dari pengajuan), `work_location`, `job_level` (otomatis dari karyawan). Operator `> >= < <= in not_in`. Fakta dibekukan saat pengajuan dibuat (`workflow_instances.facts`). Langkah yang syaratnya tidak terpenuhi dilewati; bila tidak ada langkah yang berlaku, pengajuan langsung disetujui (editor minggu 5 harus memperingatkan alur tanpa langkah tanpa syarat).
- Mode langkah `any` / `all` (`workflow_steps.mode`); pada `all` setiap penyetuju satu suara.
- Notifikasi (lonceng): penyetuju saat langkah dimulai (`workflow:{id}:{langkah}`, ditutup setelah diputuskan), pemohon dan pembuat pengajuan saat disetujui/ditolak (`workflow-result:{id}`). Karyawan belum punya lonceng di portal; untuk atasan di portal muncul menu **Persetujuan** dengan jumlahnya.
- Halaman **Persetujuan Saya** (`/approvals`, sidebar Ringkasan dengan angka; portal untuk atasan): tab Menunggu saya / Sudah saya putuskan / Pengajuan saya, filter jenis, setujui/tolak satu-satu atau massal (penolakan wajib alasan), riwayat per langkah (`ApprovalHistory`), pemohon bisa membatalkan.
- `Workflow::start(..., facts:, summary:, url:)`; komponen `ApprovalHistory` siap dipasang di detail pengajuan saat migrasi minggu 3–4.
- Test: `WorkflowTest` 12 test; total 231 lolos. Uji browser: admin (desktop) dan portal (HP) tanpa error JS, setuju lewat dialog berhasil.

## Minggu 3 — Migrasi cuti, dinas, lembur

- Alur bawaan dibuat persis sama dengan perilaku sekarang (satu langkah: HR dengan akses menu dan lokasi). **Tidak ada yang berubah bagi klien sampai HR mengubah alurnya.**
- Pengajuan yang sedang berjalan saat update tetap selesai dengan cara lama; pengajuan baru memakai Workflow.
- Saldo cuti dan kehadiran hanya berubah setelah langkah terakhir disetujui.
- Test regresi: semua test cuti, dinas, dan lembur yang ada harus tetap lolos.

**Status 28 Okt 2026: selesai.**
- Migrasi `2026_11_06` membuat alur bawaan `leave`, `business_trip`, `overtime` (1 langkah HR, menu + lokasi). Waktu pembuatannya (`Workflow::since`) menandai perpindahan: lembur yang tercatat sebelumnya selesai dengan cara lama.
- Cuti & dinas dari portal: `Workflow::start` dengan fakta hari, jenis cuti, uang muka. Notifikasi untuk HR tetap sama persis (ref, isi, link); atasan diarahkan ke Persetujuan Saya. Tombol setujui/tolak HR di Pengajuan Dinas memutus langkah Workflow (ditolak dengan pesan "Menunggu langkah …" bila bukan gilirannya); pengajuan tanpa Workflow diverifikasi seperti dulu. Absensi terisi dan saldo cuti terpotong hanya setelah langkah terakhir (`LeaveTravelRequest::workflowCompleted`). Batal di portal / Persetujuan Saya / dihapus HR menutup alurnya.
- Lembur: dibuat massal oleh proses mesin absen lewat `Workflow::startMany` (insert per 500, tanpa notifikasi/audit per baris); atasan mendapat satu notifikasi ringkasan per proses; lembur yang hilang saat proses ulang menarik alurnya. Verifikasi HR di Kehadiran → Lembur memutus langkah Workflow (yang bukan gilirannya dilewati dan dihitung), satu entri Audit Log per aksi massal; "batalkan" mengulang alur dari langkah pertama. Karyawan tidak bisa membatalkan lembur mesin.
- Tampilan: langkah yang sedang berjalan + riwayat (`FlowNote`) di Pengajuan Dinas, portal karyawan, dan tabel lembur.
- Syarat baru: `hours` (jam lembur); `category` untuk lembur = jenis hari.
- Test: `Time/ApprovalFlowTest` (7); 231 test lama tetap lolos tanpa diubah (kecuali `WorkflowTest` menyesuaikan alur bawaan dari migrasi). Regresi browser 41 halaman bersih.

## Minggu 4 — Migrasi sisa pengajuan; delegasi & eskalasi

- Migrasi: deklarasi benefit, pinjaman, pengajuan update data, persetujuan rekrutmen (penawaran kerja).
- Input Variabel dan Proses Gaji **tetap** memakai alur status payroll yang ada (pemisahan tugas dan 2FA sudah teruji).
- **Delegasi:** penyetuju menunjuk pengganti untuk rentang tanggal; otomatis aktif bila penyetuju sedang cuti yang disetujui.
- **Eskalasi:** batas waktu per langkah (misalnya 3 hari kerja); lewat batas, diingatkan lalu naik ke atasan penyetuju. Dijalankan scheduler tiap jam.

**Status 28 Okt 2026: selesai.**
- Deklarasi benefit, pinjaman, dan pengajuan update data dari portal (serta pinjaman yang dicatat HR) memulai Workflow `claim` / `loan` / `data-change`. Alur bawaannya tetap 1 langkah HR (menu + lokasi), dengan notifikasi yang sama persis seperti sebelumnya. Tombol HR di halaman modul memutus langkah Workflow (`Workflow::verify`, pesan "Menunggu langkah …" bila bukan gilirannya); pengajuan lama tanpa Workflow diverifikasi seperti dulu.
- Aturan modul diperiksa di persetujuan (`ChecksDecision`): plafon deklarasi dicek ulang di persetujuan terakhir; pinjaman tidak disetujui oleh pencatatnya (kecuali Super Admin), angsuran harus dalam batas, periode mulai belum ditutup. Pinjaman aktif (angsuran terjadwal) dan profil karyawan berubah hanya setelah langkah terakhir. Memutus pinjaman di Persetujuan Saya butuh 2FA, sama seperti halaman Pinjaman.
- **Penawaran Kerja (baru):** di jendela kandidat, HR mengajukan penawaran (jabatan, lokasi, gaji, tanggal mulai) → Workflow `job-offer`. Penyetuju dicari seolah kandidat sudah duduk di jabatan yang ditawarkan (mis. atasan jabatan itu = manajer perekrut); `workflow_instances.employee_id` kini boleh kosong. Kandidat tidak bisa dijadikan karyawan selama penawarannya menunggu; setelah disetujui, jabatan dan tanggal mulai terisi otomatis di form "Jadikan Karyawan". Belum wajib: tanpa penawaran, alur rekrutmen tetap seperti dulu.
- **Delegasi:** tombol Delegasi di Persetujuan Saya; pengganti untuk rentang tanggal atau "setiap saya cuti" (aktif pada hari cuti yang disetujui). Pengganti melihat dan memutus pengajuan atas nama penyetuju (tercatat "Nama a.n. Penyetuju", `workflow_actions.on_behalf_of`) dan ikut menerima notifikasi langkah. Satu tingkat saja.
- **Eskalasi:** `workflow_steps.due_days` (hari kerja, Senin–Jumat di luar hari libur). `eolas:workflow:escalate` tiap jam: lewat batas → pengingat sekali; satu hari kerja kemudian → atasan dari penyetuju yang belum memutus ditambahkan sebagai penyetuju (untuk langkah "semua setuju": menggantikan yang terlambat), atau Super Admin bila tidak ada atasan. Sekali per langkah; tercatat di riwayat ("Dieskalasi") dan Audit Log. Alur bawaan tidak punya batas waktu, jadi diaturnya lewat editor alur (minggu 5).
- Test: `WorkflowModulesTest` (8); 246 test lolos. `NotificationTest` disesuaikan: karyawan kini menerima notifikasi hasil deklarasinya (sama seperti cuti/dinas sejak minggu 3). Data demo: deklarasi dan update data yang menunggu masuk Workflow. Regresi browser 43 halaman bersih.

## Minggu 5 — Editor alur untuk HR

- Halaman **Pengaturan → Alur Persetujuan**: daftar jenis pengajuan, susun langkah dengan tarik-geser, pilih tipe penyetuju, syarat, batas waktu.
- **Simulasi:** pilih karyawan dan contoh pengajuan → tampil siapa saja penyetujunya, sebelum alur disimpan.
- Peringatan saat alur tidak bisa menemukan penyetuju untuk sebagian karyawan (misalnya jabatan atasan kosong).
- Alur diberi versi; pengajuan yang sudah berjalan tetap memakai versi lamanya.

**Selesai bila:** HR membuat alur cuti 3 langkah dengan syarat "lebih dari 5 hari → tambah manajer" tanpa bantuan programmer, dan simulasinya benar.

**Status 28 Okt 2026: selesai.**
- **Pengaturan → Alur Persetujuan** (`/settings/workflows`, Super Admin): daftar 7 jenis pengajuan; langkah disusun dengan tarik-geser atau tombol naik/turun; per langkah: penyetuju (atasan 1–5 tingkat, jabatan tertentu, atau HR dengan akses menu di lokasi karyawan), "salah satu cukup / semua harus setuju", batas waktu hari kerja (eskalasi minggu 4), dan syarat (nominal, hari, jam, jenis, lokasi, golongan; hanya yang dimiliki jenis pengajuan itu). Validasi di server (`FlowEditor::validate`).
- **Simulasi** draf yang belum disimpan: pilih karyawan dan contoh pengajuan → tiap langkah berlaku atau dilewati, siapa penyetujunya, dan alasannya bila jatuh ke HR / Super Admin atau diteruskan ke jabatan lebih atas (`Approvers::explain`).
- **Periksa cakupan:** untuk setiap langkah atasan/jabatan, jumlah karyawan aktif yang tidak menemukan penyetuju di struktur organisasi (jatuh ke HR), alasan dan contoh namanya; dihitung per kombinasi jabatan + lokasi.
- **Versi:** menyimpan membuat versi baru yang aktif; pengajuan yang berjalan tetap di versinya. Riwayat versi bisa dimuat lagi ke editor. Tercatat di Audit Log ("Alur persetujuan diubah").
- Test: `WorkflowEditorTest` (3), termasuk kriteria selesai (alur cuti 3 langkah, manajer hanya bila > 5 hari, simulasi dan pengajuan nyata cocok); 249 test lolos. Regresi browser 43 halaman bersih; editor + simulasi + cakupan diuji di browser.

## Minggu 6 — Fondasi PWA

- `manifest.webmanifest` dengan nama dan ikon dari Branding perusahaan; service worker untuk tampilan dasar saat sinyal hilang.
- Tombol "Pasang aplikasi" di portal (Android), petunjuk "Tambahkan ke Layar Utama" (iPhone).
- Portal karyawan dirombak untuk layar HP: navigasi bawah, beranda berisi tombol absen, saldo cuti, pengajuan terakhir, slip terbaru.
- Sesi login di HP lebih lama (opsi di pengaturan), dengan PIN atau biometrik HP untuk membuka kembali.
- **Installer:** peringatan bahwa mode `--tls internal` tidak cocok untuk aplikasi mobile; PANDUAN.md menyarankan `letsencrypt` atau `custom`.

**Status 3 Nov 2026: selesai.**
- **Manifest dan ikon dari Branding:** `/manifest.webmanifest` (nama, nama pendek, warna tema, `start_url /?source=app`, standalone) per workspace (dibaca dengan cookie sesi). Ikon PNG 180/192/512 dan versi *maskable* digambar GD dari logo yang diunggah, atau huruf awal nama aplikasi dengan warna brand (`App\Support\Pwa\AppIcon`). Tag `apple-touch-icon` / `theme-color` di `<head>`.
- **Service worker** `/sw.js`: menyimpan file build (cache-first) dan halaman `/offline`; halaman lain selalu dari server, jadi data karyawan tidak tersimpan di HP. Versi mengikuti build, rilis baru mengganti cache lama. Bar "Tidak ada koneksi" di semua layout.
- **Pasang aplikasi:** kartu di Beranda — Android memakai prompt bawaan, iPhone menampilkan langkah Bagikan → Tambahkan ke Layar Utama; bisa ditutup.
- **Portal untuk HP:** navigasi bawah (Beranda, Slip, Pinjaman, Sertifikat, Data Saya; Persetujuan masuk bar bila ada; lebih dari 5 → "Lainnya"), Beranda dibuka dengan kartu *Kehadiran hari ini* (jam masuk/pulang dari mesin + jadwal shift), tombol Klaim/Cuti/Dinas, ringkasan sisa cuti, slip terbaru, sisa plafon, dan 3 pengajuan terakhir. Toast di atas bar bawah. Tombol absen dari HP menyusul di minggu 7 (kartu kehadiran sudah disiapkan).
- **Login panjang + kunci:** Pengaturan → Branding → *Aplikasi HP*: lama login di aplikasi (bawaan 30 hari, 0 = mati). Login dari aplikasi terpasang memakai remember-me sepanjang itu + cookie penanda HP. Saat sesi pendek habis atau aplikasi dibuka lagi (sessionStorage baru), muncul layar kunci: PIN 6 angka (bukan angka sama/berurutan, dibuat dengan password) atau sidik jari / wajah (WebAuthn platform, ES256/RS256, verifikasi tanda tangan + counter di server, `Passkeys`). 5× PIN salah → keluar. Tanpa PIN/biometrik, login panjang berakhir dan karyawan diminta masuk lagi. Akun → *Keamanan Aplikasi HP* (`/account/app`) untuk PIN dan perangkat. Tercatat di Audit Log.
- **Installer:** peringatan untuk `--tls internal` / `none`; PANDUAN.md menjelaskan kebutuhan HTTPS tepercaya untuk aplikasi HP.
- Test: `MobileAppTest` (7) — manifest/ikon/SW, login panjang + PIN, 5× salah, tanpa PIN, pengaturan 0 hari, aturan PIN, passkey (registrasi + assertion, origin salah, counter mundur); 256 test lolos. Browser (viewport HP, mode standalone): login aplikasi, buat PIN, kunci saat dibuka ulang, PIN salah/benar, cache offline service worker; regresi desktop bersih. Sidik jari belum diuji di HP fisik (minggu 9).

## Minggu 7 — Absen dari HP

- Lokasi kerja ditambah titik koordinat dan radius (geofence), bisa lebih dari satu titik per lokasi (misalnya pit dan kantor site).
- Clock-in/out dari HP: GPS, akurasi GPS, selfie dari kamera, tercatat di modul Kehadiran (CI/CO) dengan sumber "mobile".
- Di luar geofence: ditolak atau diterima dengan tanda "di luar lokasi" (opsi per lokasi kerja).
- **UU PDP:** persetujuan karyawan sebelum absen pertama; masa simpan foto (bawaan 90 hari, lalu dihapus otomatis); setiap HR membuka foto tercatat di Audit Log.
- HR melihat peta dan foto untuk absen bertanda.

**Status 3 Nov 2026: selesai.**
- **Area absen (geofence):** Pengaturan Waktu & Kehadiran → *Absen HP*: per lokasi kerja mode *Tidak dipakai* / *Hanya di dalam area (di luar ditolak)* / *Di luar area diterima, ditandai*, dan titik area sebanyak yang perlu (mis. Kantor site + Pit) dengan radius; koordinat bisa diambil dari lokasi HR saat itu, dicek di Google Maps. Tabel `work_location_geofences`, kolom `work_locations.mobile_attendance`. Diubah tercatat di Audit Log. Demo: semua lokasi mode "ditandai" dengan area kantor (+ pit di site).
- **Absen dari HP:** tombol *Absen Masuk / Absen Pulang* di kartu Kehadiran hari ini (Beranda). Layar absen: kamera depan langsung (cadangan: ambil foto), GPS akurasi tinggi dengan perkiraan "di dalam area X / N m dari X". Server menghitung ulang jarak (haversine, titik terdekat dari tepi area), memakai **jam server**, menolak tap ganda < 60 detik, menyimpan foto di disk privat workspace, lalu memasukkan log lewat `AttendanceIngest` (sumber `mobile`, status 0/1) sehingga Resume Absensi dan Lembur ikut terproses. Kolom baru di `attendance_logs`: lat/long, akurasi, area terdekat, jarak, `outside_geofence`, foto.
- **UU PDP:** persetujuan sekali sebelum absen pertama (apa yang dicatat, tujuan, masa simpan, siapa yang bisa melihat, hak karyawan), tersimpan dengan versinya (`employees.attendance_consent_*`) dan di Audit Log; versi baru meminta ulang. Masa simpan foto (bawaan 90 hari, 7–365) di pengaturan; `eolas:attendance:purge-photos` tiap hari 02:15 menghapus foto lama, absen tetap ada. Foto dibuka HR lewat `/time/clock/photo/{id}` (hak menu Kehadiran, lokasi sesuai akses, `no-store`), setiap pembukaan tercatat "Foto absen dibuka".
- **HR:** Kehadiran → tab *Absen HP*: bawaan hanya yang di luar area, bisa semua; per absen: denah (digambar sendiri — lingkaran area, titik GPS dan akurasinya, garis jarak, skala; posisi tidak dikirim ke layanan peta), tautan Google Maps, dan foto yang dimuat hanya saat diklik.
- Test: `MobileAttendanceTest` (5) — pengaturan area dan masa simpan, persetujuan, absen di dalam area (pit), tap ganda, absen pulang, tolak vs tandai di luar area, tab HR + foto teraudit, hapus foto setelah 90 hari (410), jarak haversine; 261 test lolos. Browser (HP dengan GPS & kamera palsu Chromium): HR mengatur area lewat UI, karyawan setuju → foto → masuk di pit → pulang dari 5 km ditandai; HR membuka denah dan foto. Regresi desktop bersih. Belum diuji di HP fisik (minggu 9).

## Minggu 8 — Absen offline; pengajuan dari HP

- Antrean absen di HP (IndexedDB) saat sinyal hilang, terkirim otomatis saat online.
- Simpan jam HP dan jam server; tandai selisih mencurigakan; antrean lebih dari 24 jam ditolak (batas bisa diatur).
- Formulir mobile: cuti, dinas, lembur, klaim benefit (foto kuitansi dari kamera), pinjaman. Semuanya lewat Workflow.
- Menu persetujuan untuk atasan di HP (memakai halaman Persetujuan Saya).

**Status 4 Nov 2026: selesai.**
- **Antrean absen offline:** tanpa sinyal (atau koneksi putus saat mengirim), absen disimpan di IndexedDB HP — posisi GPS, akurasi, foto, jam HP saat tombol ditekan, id unik — dan layar menampilkan "tersimpan di HP". Aplikasi mengirimnya sendiri saat online (saat dibuka, saat sinyal kembali, dan tiap 30 detik selama ada antrean); kartu di portal menampilkan absen yang belum terkirim, yang terkirim, dan yang ditolak beserta alasannya. Kirim ulang dengan id yang sama tidak membuat absen ganda; antrean milik akun lain di HP yang sama tidak dikirim (409). Dibuka tanpa sinyal sama sekali: halaman offline (disimpan service worker beserta file build-nya) tetap menampilkan tombol Absen Masuk / Pulang bagi karyawan yang memakai Absen HP dan sudah setuju, memakai data area yang tersimpan saat Beranda terakhir dibuka (dihapus saat keluar).
- **Jam HP vs jam server:** setiap absen HP menyimpan jam HP saat tombol ditekan, jam server saat diterima, dan selisih jam HP (server − HP saat mengirim). Absen langsung memakai jam server; absen offline memakai jam HP dikoreksi selisih itu, jadi HP yang jamnya salah tetap tercatat pada jam sebenarnya. Selisih di atas toleransi (bawaan 5 menit) menandai absen "Jam HP tidak sesuai". Antrean lebih tua dari batas (bawaan 24 jam, 1–168) ditolak. Keduanya di Pengaturan Waktu & Kehadiran → Absen HP. Kehadiran → tab Absen HP: filter *Ditandai* (di luar area atau jam HP), *Dikirim offline*, *Semua*; detail menampilkan jam HP, jam diterima, dan selisihnya.
- **Formulir dari HP:** cuti, dinas, klaim benefit, pinjaman (sudah lewat Workflow sejak minggu 3–4) diperiksa di layar HP; unggah lampiran di HP kini punya tombol **Kamera** (kamera belakang langsung, untuk foto nota/kuitansi). Baru: **Ajukan Lembur** di Beranda (tanggal hari ini s.d. 7 hari ke belakang, jam mulai–selesai, pekerjaan) untuk lembur yang tidak tercatat mesin; jam dibayar dihitung seperti proses absen (jenis hari dari roster/hari libur, skema lokasi, pembulatan, minimum, batas harian), lalu masuk alur persetujuan "Lembur". Proses ulang absen tidak mengubahnya; karyawan bisa membatalkan selama menunggu (lembur dari mesin tetap tidak bisa). HR melihat "Diajukan dari HP" dan alasannya. Beranda menampilkan *Lembur saya* 60 hari. Demo: beberapa akun portal punya pengajuan lembur yang menunggu.
- **Persetujuan di HP:** atasan di portal melihat nota/form pengajuan langsung di kartu Persetujuan (lampiran dibuka lewat hak sebagai penyetuju alur, `Workflow::involves`); tautan ke halaman HR tidak ditampilkan untuk akun karyawan.
- Test: `MobileOfflineTest` (5) — absen offline pada jam HP + kirim ulang + akun lain, jam HP salah dikoreksi dan ditandai, batas 24 jam dan pengaturannya, lembur dari portal lewat alur (validasi, proses ulang, batal), atasan menyetujui dengan lampiran.

## Minggu 9 — Notifikasi push; uji HP nyata

- Web Push (VAPID) dengan kunci per instalasi. **Opsional:** butuh internet keluar dari server; tanpa itu notifikasi tetap tampil di dalam aplikasi.
- Pengaturan notifikasi per karyawan: pengajuan, persetujuan, slip gaji terbit, sertifikat habis.
- Uji di HP nyata: Android (Chrome) dan iPhone (Safari, iOS 16.4+, PWA terpasang). Uji sinyal putus di tengah absen.

**Selesai bila:** karyawan bisa absen (termasuk saat offline) dan mengajukan cuti dari HP tanpa membuka versi desktop, dan atasan menyetujuinya dari HP.

**Status 5 Nov 2026: kode selesai, menunggu deploy dan uji di HP nyata.**
- **Web Push (VAPID) tanpa paket tambahan:** kunci ES256 dibuat sekali per instalasi (`storage/app/push/vapid.json`), pesan dienkripsi untuk HP (RFC 8291, aes128gcm) dan ditandatangani (RFC 8292), dikirim lewat antrean (`SendPush`, per workspace). Hanya layanan push yang dikenal (FCM, Apple, Mozilla, Windows; `PUSH_HOSTS`). HP yang sudah tidak berlangganan (404/410) atau gagal 30× berturut-turut dihapus. `PUSH_ENABLED=false` atau server tanpa internet: notifikasi tetap di lonceng. Server Eolas sudah dicek bisa menjangkau FCM dan Apple.
- **Semua notifikasi yang ada ikut ke HP** (`RequestReceived` → `PushChannel`), dengan tag per pengajuan sehingga notifikasi lama di HP diganti. Baru: **Slip gaji terbit** saat proses gaji disetujui.
- **Akun → Notifikasi HP** (`/account/notifications`, admin dan karyawan): aktifkan/matikan di HP ini, pilihan per jenis (Pengajuan saya, Persetujuan, Slip gaji terbit, Sertifikat akan habis), daftar perangkat, kirim tes. iPhone di Safari: petunjuk pasang ke Layar Utama dulu (iOS 16.4+). Kartu *Aktifkan notifikasi* di Beranda HP. Keluar dari akun menghentikan langganan HP itu; HP yang dipakai akun lain pindah ke akun tersebut.
- **Lonceng di portal karyawan** (sebelumnya hanya di sidebar admin): hasil pengajuan, slip, sertifikat.
- Service worker: `push` → notifikasi sistem; ketuk → membuka halamannya.
- Test: `PushNotificationTest` (5): enkripsi didekripsi seperti HP dan JWT VAPID diverifikasi, endpoint/kunci tidak valid ditolak, HP pindah akun, jenis dimatikan hanya di lonceng, 201/500/410/tanpa koneksi, push dimatikan di server; `PayrollRunTest`: notifikasi slip; 271 test lolos.

## Minggu 10 — HR Analytics: data dan halaman

- Tren 12 bulan dihitung dari data yang ada (tanggal masuk/keluar, absensi, slip gaji); ditambah tabel ringkasan bulanan yang diisi scheduler supaya halaman tetap cepat untuk ribuan karyawan.
- Halaman **Analitik** dengan filter lokasi, departemen, periode:
  - jumlah karyawan, karyawan masuk, turnover per bulan;
  - absensi, keterlambatan, jam lembur;
  - biaya gaji per departemen dan lokasi;
  - pemenuhan MPP: rencana dibanding aktual;
  - waktu rekrutmen per lowongan;
  - sertifikat yang akan habis.
- Semua angka mengikuti akses lokasi pengguna.

## Minggu 11 — Analytics: ekspor dan akses direksi

- Ekspor Excel dan PDF (laporan siap cetak untuk rapat direksi).
- Menu `analytics` tersendiri: direksi bisa melihat analitik tanpa akses ke data karyawan per orang atau slip gaji.
- Data demo: riwayat 12 bulan untuk aplikasi demo dan workspace baru, supaya grafik langsung terisi.
- Uji kecepatan dengan 500 dan 3.000 karyawan.

**Selesai bila:** direksi melihat tren 12 bulan tanpa meminta laporan manual ke HR.

## Minggu 12 — Document Management

- Pusat dokumen dengan kategori: kontrak kerja/PKWT, SK, PP/PKB, SOP, surat peringatan, dokumen perusahaan.
- Riwayat versi; masa berlaku dengan pengingat yang memakai mesin Sertifikasi & Izin (90/60/30/0 hari). PKWT yang akan habis tampil di dashboard.
- Hak akses per kategori dan per lokasi; setiap unduhan tercatat di Audit Log.
- Lampiran karyawan yang sudah ada dipindahkan ke pusat dokumen tanpa kehilangan file.

## Minggu 13 — Template dokumen; rilis 1.1

- **Template dokumen (mail merge):** HR mengunggah template (SK, PKWT, SP) dengan isian seperti `{nama}`, `{jabatan}`, `{gaji_pokok}`, `{tanggal_masuk}`; nomor surat otomatis.
- Pembuatan massal: pilih 50 karyawan → PDF per orang (dan satu ZIP), langsung tersimpan di dokumen masing-masing karyawan.
- **Kebijakan di portal:** HR menerbitkan kebijakan baru, karyawan membaca dan menyetujui di portal/HP, HR memantau siapa yang belum.
- Regresi Fase 1, paket `1.1.0`, uji update `1.0.0 → 1.1.0` di VM, matriks fitur diperbarui.

**Selesai bila:** HR membuat SK untuk 50 karyawan sekaligus dari satu template, dan paket `1.1.0` lolos uji update dari `1.0.0`.

---

## Aturan yang berlaku setiap minggu

- Setiap minggu ditutup dengan: feature test baru + seluruh test lama lolos, `tsc` bersih, deploy ke server demo (dengan image rollback `pre-*`), migrasi semua workspace, commit dan push.
- Data demo ikut diperbarui untuk fitur baru, supaya Marketing bisa langsung memperagakannya.
- Fitur yang menyentuh data pribadi (foto, lokasi, dokumen) wajib tercatat di Audit Log dan mengikuti akses lokasi.
- Modul Fase 1 masuk paket **Core**; pengecekan `module:` tetap dipasang supaya Fase 2–3 tinggal memakainya.

## Risiko

| Risiko | Penanganan |
|---|---|
| Migrasi persetujuan mengganggu pengajuan yang sedang berjalan | Alur bawaan meniru perilaku lama; pengajuan lama selesai dengan cara lama; test regresi per jenis pengajuan |
| Struktur organisasi klien tidak lengkap (atasan kosong) | Penyetuju naik ke atasan berikutnya, lalu jatuh ke HR; editor alur memberi peringatan |
| HTTPS klien on-premise tidak dipercaya HP | Installer memperingatkan mode `internal`; panduan menyarankan Let's Encrypt atau CA perusahaan |
| Notifikasi push butuh internet | Dijadikan opsional; notifikasi di dalam aplikasi tetap jalan |
| Absen palsu (GPS palsu, jam HP diubah) | Tanda akurasi GPS, selisih jam HP–server, batas antrean offline, selfie |
| Kecepatan analitik pada data besar | Tabel ringkasan bulanan; uji 3.000 karyawan |
