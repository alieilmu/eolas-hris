import {
    Building2,
    CalendarClock,
    HandCoins,
    LayoutDashboard,
    SlidersHorizontal,
    Smartphone,
    Users,
    type LucideIcon,
} from 'lucide-react';

/** One feature of a module, with the page it lives on and the access menu that opens it. */
export interface GuideFeature {
    name: string;
    desc: string;
    href?: string;
    menu?: string;
}

/** A module of Eolas HRIS as the landing page and the dashboard explain it. */
export interface GuideModule {
    id: string;
    name: string;
    icon: LucideIcon;
    tagline: string;
    desc: string;
    /** Tailwind classes for the module's chip (icon background and text). */
    tone: string;
    features: GuideFeature[];
}

export const GUIDE_MODULES: GuideModule[] = [
    {
        id: 'dashboard',
        name: 'Dashboard',
        icon: LayoutDashboard,
        tagline: 'Satu layar untuk semua angka penting',
        desc: 'Ringkasan real-time dari seluruh modul: jumlah karyawan, man onsite, kehadiran, pengajuan yang menunggu, recruitment, Comben, dan MPP. Bisa difilter per lokasi kerja.',
        tone: 'bg-emerald-100 text-emerald-700',
        features: [
            { name: 'KPI utama', desc: 'Karyawan aktif, karyawan baru, kontrak PKWT yang akan habis, dan karyawan keluar tahun ini.', href: '/dashboard' },
            { name: 'Man Onsite 30 hari', desc: 'Grafik harian siapa yang onsite, dinas, cuti, off, atau absen, termasuk rencana roster ke depan.', href: '/dashboard' },
            { name: 'Pengajuan menunggu', desc: 'Antrian persetujuan dari portal karyawan, langsung menuju halaman verifikasinya.', href: '/dashboard' },
            { name: 'Filter lokasi', desc: 'Semua angka bisa dipersempit ke satu lokasi kerja atau site.', href: '/dashboard' },
        ],
    },
    {
        id: 'recruitment',
        name: 'Recruitment',
        icon: Users,
        tagline: 'Dari lowongan sampai tanda tangan kontrak',
        desc: 'Kelola proses rekrutmen dari form lamaran publik, seleksi pelamar, sampai papan tahapan kandidat. Kandidat yang lolos bisa langsung dijadikan karyawan.',
        tone: 'bg-sky-100 text-sky-700',
        features: [
            { name: 'Form lamaran publik', desc: 'Buat form lowongan dengan pertanyaan dan unggahan berkas sendiri. Pelamar mengisi di halaman /lowongan tanpa login.', href: '/recruitment/form', menu: 'recruitment' },
            { name: 'Seleksi pelamar', desc: 'Lihat jawaban dan berkas pelamar, lalu teruskan ke tracking atau tolak.', href: '/recruitment/pelamar', menu: 'recruitment' },
            { name: 'Recruitment Tracking', desc: 'Papan kanban per tahapan (psikotes, interview, MCU, offering). Kandidat dipindah dengan drag & drop, lengkap dengan catatan dan CV.', href: '/recruitment/tracking', menu: 'recruitment' },
            { name: 'Laporan & ekspor', desc: 'Rekap rekrutmen per periode, posisi, dan tahapan, bisa diekspor ke Excel.', href: '/recruitment/laporan', menu: 'recruitment' },
        ],
    },
    {
        id: 'manpower',
        name: 'Manpower',
        icon: Building2,
        tagline: 'Data karyawan, organisasi & perencanaan',
        desc: 'Pusat data karyawan: profil lengkap, kontrak PKWT, keluarga, penempatan, struktur organisasi, dan Man Power Planning per lokasi kerja.',
        tone: 'bg-green-100 text-green-700',
        features: [
            { name: 'Data karyawan & kelengkapan', desc: 'Profil 19 kelompok data (identitas, pajak, BPJS, bank, keluarga) dengan persentase kelengkapan. Mendukung import dan export Excel.', href: '/manpower/karyawan', menu: 'karyawan' },
            { name: 'NRP otomatis', desc: 'NRP 8 digit disusun dari jenis karyawan, lokasi penerimaan (POH), tahun masuk, dan nomor urut.', href: '/manpower/master-data', menu: 'master-personalia' },
            { name: 'Struktur Organisasi', desc: 'Rancang plan jabatan per lokasi dan lihat bagan organisasi berisi orangnya. Nama jabatan tersusun otomatis dari penempatan.', href: '/manpower/org-structure', menu: 'org-structure' },
            { name: 'Man Power Planning', desc: 'Plan vs actual per jabatan, dengan proyeksi promosi, rotasi, keluar, dan pensiun. Gap dihitung otomatis, biaya diperkirakan dari skala upah, dan revisi perlu persetujuan.', href: '/manpower/mpp', menu: 'mpp' },
            { name: 'Pengajuan Update Data', desc: 'Karyawan mengajukan perubahan datanya dari portal, lalu HR menyetujui atau menolak.', href: '/manpower/pengajuan-data', menu: 'update-data' },
        ],
    },
    {
        id: 'attendance',
        name: 'Attendance',
        icon: CalendarClock,
        tagline: 'Roster, absensi, dinas & cuti',
        desc: 'Rencanakan roster kerja (termasuk pola site, misalnya 10 hari kerja 2 hari off), catat absensi aktual, dan kelola perjalanan dinas serta cuti beserta tiket dan uang muka.',
        tone: 'bg-teal-100 text-teal-700',
        features: [
            { name: 'Roster', desc: 'Rencana shift bulanan per karyawan dari pola roster. Bisa diisi massal dan diimpor dari Excel.', href: '/attendance/roster', menu: 'roster' },
            { name: 'Absensi', desc: 'Kehadiran aktual (hadir, sakit, izin, mangkir) dengan rekap statistik dan ekspor.', href: '/attendance/absensi', menu: 'absensi' },
            { name: 'Trip & Leave', desc: 'Dinas dan cuti dengan kota, tiket pesawat atau kereta, dan uang muka. Cuti lapangan memotong kuota secara otomatis.', href: '/attendance/trip-leave', menu: 'trip-leave' },
            { name: 'Pengajuan Dinas', desc: 'Verifikasi pengajuan dari portal, status uang, pembelian tiket, dan import massal.', href: '/attendance/pengajuan-dinas', menu: 'pengajuan-dinas' },
            { name: 'Kehadiran (CI/CO)', desc: 'Clock in / clock out dari mesin absen dibandingkan dengan roster: lengkap, tanpa CI/CO, tidak hadir, tidak sesuai roster, terlambat. Otomatis mengisi Absensi.', href: '/attendance/kehadiran', menu: 'kehadiran' },
            { name: 'Mesin Absen', desc: 'Tambah atau keluarkan mesin absen (ZKTeco/Solution/Fingerspot ADMS) sendiri; data CI/CO masuk real-time, atau import file dari mesin.', href: '/attendance/kehadiran?tab=mesin', menu: 'kehadiran' },
            { name: 'Lembur', desc: 'Dihitung dari CI/CO sesuai PP 35/2021 & skema ESDM, jam istirahat tidak dihitung; disetujui atasan/HR sebelum dibayar.', href: '/attendance/kehadiran?tab=lembur', menu: 'lembur-approve' },
        ],
    },
    {
        id: 'comben',
        name: 'Comben',
        icon: HandCoins,
        tagline: 'Benefit, plafon & cuti',
        desc: 'Kompensasi dan benefit: deklarasi pengobatan dan kacamata yang dicek terhadap plafon, monitoring sisa plafon, buku cuti tahunan dan cuti besar, serta skala upah.',
        tone: 'bg-lime-100 text-lime-700',
        features: [
            { name: 'Deklarasi', desc: 'Klaim pengobatan (per keluarga) dan kacamata (per anggota keluarga), dicek otomatis terhadap plafon, dengan lampiran nota.', href: '/comben/deklarasi', menu: 'deklarasi' },
            { name: 'Monitoring Plafon', desc: 'Pemakaian dan sisa plafon setiap karyawan dalam satu tabel, bisa diekspor.', href: '/comben/monitoring', menu: 'monitoring-plafon' },
            { name: 'Cuti Tahunan & Besar', desc: 'Kuota tahunan FIFO dengan masa berlaku 2 tahun dan hutang cuti. Setiap 5 tahun ada pilihan Cuti Besar: diambil atau diuangkan.', href: '/comben/cuti', menu: 'cuti' },
            { name: 'Data Gaji', desc: 'Gaji pokok dari Struktur Skala Upah (golongan & step per karyawan, ada riwayat), tunjangan jabatan per SubGroup, dan status PTKP otomatis.', href: '/comben/data-gaji', menu: 'data-gaji' },
            { name: 'Master Comben', desc: 'Golongan, skala upah, tunjangan jabatan, plafon per SubGroup, aturan cuti lapangan, dan lumpsum.', href: '/comben/master-data', menu: 'master-comben' },
        ],
    },
    {
        id: 'portal',
        name: 'Portal Karyawan',
        icon: Smartphone,
        tagline: 'Layanan mandiri untuk karyawan',
        desc: 'Karyawan masuk dengan NRP untuk melihat plafon, mengajukan deklarasi, cuti, dan dinas, memilih Cuti Besar, dan memperbarui data dirinya. Semua pengajuan masuk ke notifikasi HR.',
        tone: 'bg-emerald-50 text-emerald-600',
        features: [
            { name: 'Plafon & riwayat', desc: 'Sisa plafon pengobatan dan kacamata serta riwayat pengajuan.' },
            { name: 'Ajukan cuti & dinas', desc: 'Form cuti dengan sisa kuota real-time, form dinas dengan tiket dan uang muka.' },
            { name: 'Data Saya', desc: 'Perubahan data pribadi dikirim ke HR untuk disetujui.' },
            { name: 'Notifikasi HR', desc: 'Setiap pengajuan muncul di lonceng notifikasi admin.' },
        ],
    },
    {
        id: 'settings',
        name: 'Settings',
        icon: SlidersHorizontal,
        tagline: 'Hak akses & white label',
        desc: 'Atur akun admin dengan akses per menu (lihat atau ubah) dan per lokasi, buat akun portal karyawan secara massal, dan sesuaikan logo serta warna aplikasi.',
        tone: 'bg-slate-100 text-slate-700',
        features: [
            { name: 'Akun & Akses', desc: 'Peran Super Admin, Admin (akses per menu dan per lokasi), dan Karyawan. Ada reset password massal.', href: '/settings/akun', menu: 'settings' },
            { name: 'White Label', desc: 'Nama aplikasi, nama perusahaan, logo, favicon, dan dua warna tema.', href: '/settings/white-label', menu: 'settings' },
        ],
    },
];
