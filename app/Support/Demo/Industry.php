<?php

namespace App\Support\Demo;

/**
 * The industries a demo workspace with system data can be made for. The demo data is built
 * once (a mining contractor, App\Support\Demo\IndustrySkin's starting point) and then renamed
 * to the industry: work locations, organisation units, SubGroups, position disciplines,
 * grade groups, vacancies, cities and the text that mentions them. The scenarios (contracts,
 * leave, overtime, recruitment …) stay the same.
 *
 * A profile:
 *  - locations: old work location => [name, code, type, work schedule scheme, JKK %]
 *  - places:    old place names in text (cities, regencies) => new
 *  - units:     old division / department / section name => new
 *  - job_levels / title_prefixes / disciplines / grade_groups / categories: old => new
 *  - postings:  old vacancy title => [title, position title, description]
 *  - answers:   old field labels and choices of the vacancies => new
 *  - text:      phrases in notes and descriptions => new
 */
class Industry
{
    public const DEFAULT = 'mining';

    public const LABELS = [
        'mining' => 'Pertambangan',
        'manufacturing' => 'Manufaktur',
        'garment' => 'Garmen & Tekstil',
        'hospital' => 'Rumah Sakit',
        'fnb' => 'Bisnis F&B',
        'education' => 'Pendidikan',
        'retail' => 'Retail',
        'logistics' => 'Logistik & Transportasi',
        'construction' => 'Konstruksi',
        'plantation' => 'Perkebunan',
    ];

    /** One line for the request form. */
    public const DESCRIPTIONS = [
        'mining' => 'Kontraktor tambang: head office + 2 site, roster 10:2, operator & mekanik alat berat.',
        'manufacturing' => 'Kantor pusat + 2 pabrik, 3 shift, operator mesin & teknisi maintenance.',
        'garment' => 'Pabrik garmen ekspor: operator jahit, mekanik mesin jahit, target per line.',
        'hospital' => 'Grup rumah sakit: perawat, penunjang medis, jadwal dinas 3 shift.',
        'fnb' => 'Central kitchen + outlet: cook, crew service, barista, shift pagi/malam.',
        'education' => 'Yayasan pendidikan: sekolah, guru, laboran, tenaga kependidikan.',
        'retail' => 'Jaringan toko + gudang distribusi: kasir, pramuniaga, staff gudang.',
        'logistics' => 'Perusahaan logistik: hub, driver, checker, mekanik armada.',
        'construction' => 'Kontraktor: kantor pusat + proyek, pelaksana lapangan, mekanik alat.',
        'plantation' => 'Perkebunan sawit: kebun, afdeling, pemanen, pabrik kelapa sawit.',
    ];

    /** @return array<string, mixed> the profile, empty for mining (the data as built) */
    public static function profile(string $industry): array
    {
        return self::PROFILES[$industry] ?? [];
    }

    private const PROFILES = [
        'manufacturing' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Jakarta', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Pabrik Cikarang', 'CKR', 'site', 'six_day', 0.89],
                'Site Rantau' => ['Pabrik Karawang', 'KRW', 'site', 'six_day', 0.89],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kabupaten Bekasi', 'Kalimantan Tengah' => 'Jawa Barat', 'Kabupaten Tapin' => 'Kabupaten Karawang', 'Kalimantan Selatan' => 'Jawa Barat', 'Murung Raya' => 'Cikarang', 'Puruk Cahu' => 'Cikarang', 'Rantau' => 'Karawang'],
            'units' => ['OPERASI TAMBANG' => 'ASSEMBLY LINE', 'WORKSHOP' => 'MESIN & UTILITAS'],
            'job_levels' => ['Project Manager' => 'Plant Manager', 'Deputy Project Manager' => 'Deputy Plant Manager', 'Section Head Site' => 'Supervisor Pabrik', 'Section Head HO' => 'Supervisor HO',
                'Officer HO/ Group Leader' => 'Officer / Leader Line', 'FGDP' => 'Management Trainee', 'Operator' => 'Operator Produksi', 'Mekanik' => 'Teknisi', 'Nonom' => 'Helper', 'Welder' => 'Welder'],
            'title_prefixes' => ['PROJECT MANAGER' => 'PLANT MANAGER', 'DEPUTY PROJECT MANAGER' => 'DEPUTY PLANT MANAGER', 'SECTION HEAD' => 'SUPERVISOR', 'GROUP LEADER' => 'LEADER', 'FGDP' => 'MANAGEMENT TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'OPERATOR MESIN', 'MECHANIC' => 'TEKNISI MAINTENANCE', 'HSE FIELD' => 'HSE PABRIK'],
            'grade_groups' => ['NONOM' => 'HELPER', 'MEKANIK' => 'TEKNISI'],
            'categories' => ['Mekanik Magang' => 'Teknisi Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Operator Produksi', 'Operator Mesin Injection / Press', 'Dibutuhkan operator mesin injection molding dan press untuk lini produksi Cikarang. Sistem kerja 3 shift.'],
                'Mekanik Alat Berat' => ['Teknisi Maintenance', 'Teknisi Mesin Produksi', 'Perawatan preventif dan perbaikan mesin produksi serta utilitas pabrik. Diutamakan lulusan SMK/D3 Teknik Mesin atau Elektro.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Mesin yang Dikuasai', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman di Lini Produksi (tahun)', 'SIMPER / SIO' => 'Sertifikat K3 / SIO',
                'Dump Truck (HD785 / HD465)' => 'Injection Molding', 'Excavator (PC2000 / PC1250)' => 'Press / Stamping', 'Bulldozer (D375 / D155)' => 'CNC', 'Grader' => 'Assembly',
                'Engine' => 'Mekanikal', 'Powertrain' => 'Utilitas', 'Hydraulic' => 'PLC & Otomasi', 'Electrical' => 'Elektrikal',
                'Operator Dump Truck / Excavator' => 'Operator Mesin Injection / Press', 'Mekanik Heavy Equipment' => 'Teknisi Mesin Produksi'],
            'text' => ['roster 10:2' => 'sistem 3 shift', 'prosedur P2H dan safety' => 'SOP mesin dan K3', 'Uji praktik unit di site' => 'Uji praktik di lini produksi',
                'Training overhaul engine di workshop principal' => 'Training perawatan mesin di principal', 'Nonom / WOM' => 'Helper / Operator', 'Site Section Head' => 'Supervisor Pabrik', 'Site Department Head' => 'Dept Head Pabrik'],
        ],

        'garment' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Jakarta', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Pabrik Garmen Bogor', 'BGR', 'site', 'six_day', 0.54],
                'Site Rantau' => ['Pabrik Garmen Sukabumi', 'SKB', 'site', 'six_day', 0.54],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kabupaten Bogor', 'Kalimantan Tengah' => 'Jawa Barat', 'Kabupaten Tapin' => 'Kabupaten Sukabumi', 'Kalimantan Selatan' => 'Jawa Barat', 'Murung Raya' => 'Bogor', 'Puruk Cahu' => 'Bogor', 'Rantau' => 'Sukabumi'],
            'units' => ['OPERASI TAMBANG' => 'SEWING', 'WORKSHOP' => 'MEKANIK MESIN'],
            'job_levels' => ['Project Manager' => 'Factory Manager', 'Deputy Project Manager' => 'Deputy Factory Manager', 'Section Head Site' => 'Supervisor Line', 'Section Head HO' => 'Supervisor HO',
                'Officer HO/ Group Leader' => 'Officer / Leader Line', 'FGDP' => 'Management Trainee', 'Operator' => 'Operator Jahit', 'Mekanik' => 'Mekanik Mesin', 'Nonom' => 'Helper', 'Welder' => 'Cutter'],
            'title_prefixes' => ['PROJECT MANAGER' => 'FACTORY MANAGER', 'DEPUTY PROJECT MANAGER' => 'DEPUTY FACTORY MANAGER', 'SECTION HEAD' => 'SUPERVISOR', 'GROUP LEADER' => 'LEADER', 'FGDP' => 'MANAGEMENT TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'OPERATOR JAHIT', 'MECHANIC' => 'MEKANIK MESIN JAHIT', 'HSE FIELD' => 'HSE PABRIK'],
            'grade_groups' => ['NONOM' => 'HELPER', 'OPERATOR' => 'OPERATOR JAHIT', 'WELDER' => 'CUTTER'],
            'categories' => ['Operator Magang' => 'Penjahit Magang', 'Mekanik Magang' => 'Mekanik Mesin Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Operator Jahit', 'Penjahit / Operator Sewing', 'Dibutuhkan operator jahit berpengalaman mesin single needle dan overlock untuk produksi garmen ekspor. Target harian per line.'],
                'Mekanik Alat Berat' => ['Mekanik Mesin Jahit', 'Mekanik Mesin Garmen', 'Perawatan dan setting mesin jahit industri, mesin cutting dan boiler. Diutamakan berpengalaman di pabrik garmen.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Mesin yang Dikuasai', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman Menjahit (tahun)', 'SIMPER / SIO' => 'Sertifikat / Surat Pengalaman',
                'Dump Truck (HD785 / HD465)' => 'Single Needle', 'Excavator (PC2000 / PC1250)' => 'Overlock', 'Bulldozer (D375 / D155)' => 'Kansai / Feed of Arm', 'Grader' => 'Bartack',
                'Engine' => 'Mesin Jahit Industri', 'Powertrain' => 'Boiler & Setrika', 'Hydraulic' => 'Mesin Cutting', 'Electrical' => 'Elektrikal',
                'Operator Dump Truck / Excavator' => 'Penjahit / Operator Sewing', 'Mekanik Heavy Equipment' => 'Mekanik Mesin Garmen'],
            'text' => ['roster 10:2' => 'target per line dan lembur', 'prosedur P2H dan safety' => 'standar kualitas jahitan', 'Uji praktik unit di site' => 'Uji praktik jahit di line',
                'Training overhaul engine di workshop principal' => 'Training setting mesin jahit', 'Rapat evaluasi produksi' => 'Rapat evaluasi output', 'Nonom / WOM' => 'Helper', 'Site Section Head' => 'Supervisor Line', 'Site Department Head' => 'Dept Head Pabrik'],
        ],

        'hospital' => [
            'locations' => [
                'Head Office' => ['Kantor Manajemen Grup', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['RS Sehat Bandung', 'BDG', 'site', 'six_day', 0.54],
                'Site Rantau' => ['RS Sehat Cirebon', 'CRB', 'site', 'six_day', 0.54],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kota Bandung', 'Kalimantan Tengah' => 'Jawa Barat', 'Kabupaten Tapin' => 'Kota Cirebon', 'Kalimantan Selatan' => 'Jawa Barat', 'Murung Raya' => 'Bandung', 'Puruk Cahu' => 'Bandung', 'Rantau' => 'Cirebon'],
            'units' => ['OPERASIONAL' => 'PELAYANAN MEDIS', 'PRODUKSI' => 'KEPERAWATAN', 'MAINTENANCE' => 'PENUNJANG MEDIS', 'OPERASI TAMBANG' => 'RAWAT INAP', 'WORKSHOP' => 'LABORATORIUM'],
            'job_levels' => ['Project Manager' => 'Direktur RS', 'Deputy Project Manager' => 'Wakil Direktur RS', 'Section Head Site' => 'Kepala Ruangan', 'Section Head HO' => 'Kepala Seksi HO',
                'Departement Head' => 'Kepala Instalasi', 'Officer HO/ Group Leader' => 'Officer / Ketua Tim', 'FGDP' => 'Dokter Umum', 'Operator' => 'Perawat', 'Mekanik' => 'Tenaga Penunjang Medis', 'Nonom' => 'Pekarya', 'Welder' => 'Bidan'],
            'title_prefixes' => ['PROJECT MANAGER' => 'DIREKTUR RS', 'DEPUTY PROJECT MANAGER' => 'WAKIL DIREKTUR RS', 'SECTION HEAD' => 'KEPALA', 'DEPT HEAD' => 'KEPALA INSTALASI', 'GROUP LEADER' => 'KETUA TIM', 'FGDP' => 'DOKTER'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'PERAWAT PELAKSANA', 'MECHANIC' => 'ANALIS LABORATORIUM', 'HSE FIELD' => 'K3RS'],
            'grade_groups' => ['NONOM' => 'PEKARYA', 'OPERATOR' => 'PERAWAT', 'MEKANIK' => 'PENUNJANG MEDIS', 'WELDER' => 'BIDAN'],
            'categories' => ['Mekanik Magang' => 'Analis Magang', 'Operator Magang' => 'Perawat Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Perawat Pelaksana', 'Perawat Rawat Inap', 'Dibutuhkan perawat (D3 Keperawatan / Ners) dengan STR aktif untuk ruang rawat inap. Jadwal dinas 3 shift.'],
                'Mekanik Alat Berat' => ['Analis Laboratorium', 'Analis Kesehatan', 'Pemeriksaan laboratorium klinik: hematologi, kimia klinik dan mikrobiologi. Wajib STR Ahli Teknologi Laboratorium Medik.'],
                'Staff HRGA' => ['Staff SDM', 'Staff SDM & Umum', 'Administrasi kepegawaian, kredensial tenaga medis dan payroll di kantor manajemen.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Unit Pengalaman', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman Klinis (tahun)', 'SIMPER / SIO' => 'STR / SIP',
                'Dump Truck (HD785 / HD465)' => 'Rawat Inap', 'Excavator (PC2000 / PC1250)' => 'IGD', 'Bulldozer (D375 / D155)' => 'ICU', 'Grader' => 'Kamar Operasi',
                'Engine' => 'Hematologi', 'Powertrain' => 'Imunologi', 'Hydraulic' => 'Kimia Klinik', 'Electrical' => 'Mikrobiologi',
                'Operator Dump Truck / Excavator' => 'Perawat Rawat Inap', 'Mekanik Heavy Equipment' => 'Analis Kesehatan', 'Staff HRGA' => 'Staff SDM & Umum'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia jadwal dinas 3 shift', 'prosedur P2H dan safety' => 'SOP keselamatan pasien', 'Uji praktik unit di site' => 'Uji kompetensi klinis di ruangan',
                'Training overhaul engine di workshop principal' => 'Pelatihan BTCLS', 'Pengambilan sparepart & koordinasi vendor' => 'Koordinasi vendor alat kesehatan', 'Rapat evaluasi produksi' => 'Rapat evaluasi mutu pelayanan',
                'Nonom / WOM' => 'Pekarya', 'Site Section Head' => 'Kepala Ruangan', 'Site Department Head' => 'Kepala Instalasi', 'Deputy Project Manager / Project Manager' => 'Wakil Direktur / Direktur RS'],
        ],

        'fnb' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Jakarta', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Central Kitchen Tangerang', 'CKT', 'site', 'six_day', 0.24],
                'Site Rantau' => ['Outlet Bandung', 'BDG', 'site', 'six_day', 0.24],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kota Tangerang', 'Kalimantan Tengah' => 'Banten', 'Kabupaten Tapin' => 'Kota Bandung', 'Kalimantan Selatan' => 'Jawa Barat', 'Murung Raya' => 'Tangerang', 'Puruk Cahu' => 'Tangerang', 'Rantau' => 'Bandung'],
            'units' => ['OPERASIONAL' => 'OPERASIONAL OUTLET', 'PRODUKSI' => 'KITCHEN', 'MAINTENANCE' => 'SERVICE', 'OPERASI TAMBANG' => 'HOT KITCHEN', 'WORKSHOP' => 'FRONT OF HOUSE'],
            'job_levels' => ['Project Manager' => 'Area Manager', 'Deputy Project Manager' => 'Outlet Manager', 'Section Head Site' => 'Head Chef / Supervisor', 'Section Head HO' => 'Supervisor HO',
                'Officer HO/ Group Leader' => 'Officer / Captain', 'FGDP' => 'Management Trainee', 'Operator' => 'Crew Kitchen', 'Mekanik' => 'Crew Service', 'Nonom' => 'Steward', 'Welder' => 'Barista'],
            'title_prefixes' => ['PROJECT MANAGER' => 'AREA MANAGER', 'DEPUTY PROJECT MANAGER' => 'OUTLET MANAGER', 'SECTION HEAD' => 'SUPERVISOR', 'GROUP LEADER' => 'CAPTAIN', 'FGDP' => 'MANAGEMENT TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'COOK', 'MECHANIC' => 'WAITER / WAITRESS', 'HSE FIELD' => 'FOOD SAFETY'],
            'grade_groups' => ['NONOM' => 'STEWARD', 'OPERATOR' => 'CREW KITCHEN', 'MEKANIK' => 'CREW SERVICE', 'WELDER' => 'BARISTA'],
            'categories' => ['Mekanik Magang' => 'Crew Magang', 'Operator Magang' => 'Cook Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Cook', 'Cook Hot Kitchen', 'Dibutuhkan cook berpengalaman masakan Indonesia dan western untuk central kitchen. Sistem shift, sertifikat food handler diutamakan.'],
                'Mekanik Alat Berat' => ['Waiter / Waitress', 'Crew Service Outlet', 'Melayani tamu dengan ramah di outlet Bandung: order, penyajian dan kasir. Bersedia kerja shift dan akhir pekan.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Bagian Dapur yang Dikuasai', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman di Dapur (tahun)', 'SIMPER / SIO' => 'Sertifikat Food Handler',
                'Dump Truck (HD785 / HD465)' => 'Hot Kitchen', 'Excavator (PC2000 / PC1250)' => 'Cold Kitchen', 'Bulldozer (D375 / D155)' => 'Grill', 'Grader' => 'Pastry',
                'Spesialisasi' => 'Keahlian', 'Engine' => 'Melayani tamu', 'Powertrain' => 'Host', 'Hydraulic' => 'Barista', 'Electrical' => 'Kasir',
                'Operator Dump Truck / Excavator' => 'Cook Hot Kitchen', 'Mekanik Heavy Equipment' => 'Crew Service Outlet'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia shift pagi/malam dan akhir pekan', 'prosedur P2H dan safety' => 'standar hygiene dan food safety', 'Uji praktik unit di site' => 'Uji masak di dapur',
                'Training overhaul engine di workshop principal' => 'Training standar resep', 'Pengambilan sparepart & koordinasi vendor' => 'Koordinasi supplier bahan baku', 'Rapat evaluasi produksi' => 'Rapat evaluasi penjualan',
                'Nonom / WOM' => 'Steward / Crew', 'Site Section Head' => 'Head Chef / Supervisor', 'Site Department Head' => 'Outlet Manager', 'Deputy Project Manager / Project Manager' => 'Outlet Manager / Area Manager'],
        ],

        'education' => [
            'locations' => [
                'Head Office' => ['Kantor Yayasan', 'YYS', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Sekolah Depok', 'DPK', 'site', 'five_day', 0.24],
                'Site Rantau' => ['Sekolah Bekasi', 'BKS', 'site', 'five_day', 0.24],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kota Depok', 'Kalimantan Tengah' => 'Jawa Barat', 'Kabupaten Tapin' => 'Kota Bekasi', 'Kalimantan Selatan' => 'Jawa Barat', 'Murung Raya' => 'Depok', 'Puruk Cahu' => 'Depok', 'Rantau' => 'Bekasi'],
            'units' => ['OPERASIONAL' => 'AKADEMIK', 'PRODUKSI' => 'PENGAJARAN', 'MAINTENANCE' => 'SARANA PRASARANA', 'OPERASI TAMBANG' => 'SMA', 'WORKSHOP' => 'LABORATORIUM'],
            'job_levels' => ['Project Manager' => 'Kepala Sekolah', 'Deputy Project Manager' => 'Wakil Kepala Sekolah', 'Section Head Site' => 'Koordinator', 'Section Head HO' => 'Kepala Bagian Yayasan',
                'Officer HO/ Group Leader' => 'Staff / Wali Kelas', 'FGDP' => 'Guru Bantu', 'Operator' => 'Guru', 'Mekanik' => 'Laboran / Teknisi', 'Nonom' => 'Tenaga Kebersihan', 'Welder' => 'Satpam'],
            'title_prefixes' => ['PROJECT MANAGER' => 'KEPALA SEKOLAH', 'DEPUTY PROJECT MANAGER' => 'WAKIL KEPALA SEKOLAH', 'SECTION HEAD' => 'KOORDINATOR', 'GROUP LEADER' => 'WALI KELAS', 'FGDP' => 'GURU BANTU'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'GURU MATA PELAJARAN', 'MECHANIC' => 'LABORAN', 'HSE FIELD' => 'K3 SEKOLAH'],
            'grade_groups' => ['NONOM' => 'TENAGA UMUM', 'OPERATOR' => 'GURU', 'MEKANIK' => 'LABORAN', 'WELDER' => 'KEAMANAN'],
            'categories' => ['Mekanik Magang' => 'Laboran Magang', 'Operator Magang' => 'Guru Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Guru SMA', 'Guru Mata Pelajaran SMA', 'Dibutuhkan guru S1 kependidikan untuk SMA. Mengajar 24 JP per minggu, memahami Kurikulum Merdeka.'],
                'Mekanik Alat Berat' => ['Laboran', 'Laboran IPA & Komputer', 'Menyiapkan praktikum dan merawat peralatan laboratorium sekolah. Diutamakan D3/S1 bidang terkait.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Mata Pelajaran', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman Mengajar (tahun)', 'SIMPER / SIO' => 'Sertifikat Pendidik',
                'Dump Truck (HD785 / HD465)' => 'Matematika', 'Excavator (PC2000 / PC1250)' => 'Fisika', 'Bulldozer (D375 / D155)' => 'Kimia', 'Grader' => 'Biologi',
                'Spesialisasi' => 'Laboratorium', 'Engine' => 'Lab Komputer', 'Powertrain' => 'Lab Bahasa', 'Hydraulic' => 'Lab Kimia', 'Electrical' => 'Lab Fisika',
                'Operator Dump Truck / Excavator' => 'Guru Mata Pelajaran SMA', 'Mekanik Heavy Equipment' => 'Laboran IPA & Komputer'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia mengajar 24 JP per minggu', 'prosedur P2H dan safety' => 'Kurikulum Merdeka', 'Uji praktik unit di site' => 'Micro teaching di kelas',
                'Training overhaul engine di workshop principal' => 'Pelatihan guru penggerak', 'Pengambilan sparepart & koordinasi vendor' => 'Koordinasi pengadaan buku', 'Rapat evaluasi produksi kuartal III' => 'Rapat evaluasi akademik semester ganjil',
                'Nonom / WOM' => 'Tenaga Umum', 'Site Section Head' => 'Koordinator', 'Site Department Head' => 'Wakil Kepala Sekolah', 'Deputy Project Manager / Project Manager' => 'Wakil Kepala / Kepala Sekolah'],
        ],

        'retail' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Jakarta', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Gudang Distribusi Cikarang', 'DC', 'site', 'six_day', 0.54],
                'Site Rantau' => ['Toko Cabang Bandung', 'BDG', 'site', 'six_day', 0.24],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kabupaten Bekasi', 'Kalimantan Tengah' => 'Jawa Barat', 'Kabupaten Tapin' => 'Kota Bandung', 'Kalimantan Selatan' => 'Jawa Barat', 'Murung Raya' => 'Cikarang', 'Puruk Cahu' => 'Cikarang', 'Rantau' => 'Bandung'],
            'units' => ['OPERASIONAL' => 'OPERASIONAL TOKO', 'PRODUKSI' => 'STORE OPERATION', 'MAINTENANCE' => 'LOGISTIK', 'OPERASI TAMBANG' => 'KASIR & PRAMUNIAGA', 'WORKSHOP' => 'GUDANG'],
            'job_levels' => ['Project Manager' => 'Area Manager', 'Deputy Project Manager' => 'Store Manager', 'Section Head Site' => 'Supervisor Toko', 'Section Head HO' => 'Supervisor HO',
                'Officer HO/ Group Leader' => 'Officer / Leader', 'FGDP' => 'Management Trainee', 'Operator' => 'Crew Toko', 'Mekanik' => 'Crew Gudang', 'Nonom' => 'Helper', 'Welder' => 'Kasir'],
            'title_prefixes' => ['PROJECT MANAGER' => 'AREA MANAGER', 'DEPUTY PROJECT MANAGER' => 'STORE MANAGER', 'SECTION HEAD' => 'SUPERVISOR', 'GROUP LEADER' => 'LEADER', 'FGDP' => 'MANAGEMENT TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'PRAMUNIAGA', 'MECHANIC' => 'STAFF GUDANG', 'HSE FIELD' => 'LOSS PREVENTION'],
            'grade_groups' => ['NONOM' => 'HELPER', 'OPERATOR' => 'CREW TOKO', 'MEKANIK' => 'CREW GUDANG', 'WELDER' => 'KASIR'],
            'categories' => ['Mekanik Magang' => 'Crew Gudang Magang', 'Operator Magang' => 'Crew Toko Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Pramuniaga / Kasir', 'Crew Toko', 'Dibutuhkan crew toko untuk kasir, pramuniaga dan display produk di cabang Bandung. Kerja shift pagi/siang.'],
                'Mekanik Alat Berat' => ['Staff Gudang', 'Staff Gudang Distribusi', 'Penerimaan barang, picking & packing, dan stock opname di gudang distribusi Cikarang.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Posisi yang Diminati', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman Retail (tahun)', 'SIMPER / SIO' => 'Sertifikat / Surat Pengalaman',
                'Dump Truck (HD785 / HD465)' => 'Kasir', 'Excavator (PC2000 / PC1250)' => 'Pramuniaga', 'Bulldozer (D375 / D155)' => 'Visual Merchandising', 'Grader' => 'Customer Service',
                'Spesialisasi' => 'Pengalaman Gudang', 'Engine' => 'Receiving', 'Powertrain' => 'Forklift', 'Hydraulic' => 'Picking & Packing', 'Electrical' => 'Stock Opname',
                'Operator Dump Truck / Excavator' => 'Crew Toko', 'Mekanik Heavy Equipment' => 'Staff Gudang Distribusi'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia shift pagi/siang', 'prosedur P2H dan safety' => 'SOP pelayanan dan kas', 'Uji praktik unit di site' => 'Uji praktik kasir di toko',
                'Training overhaul engine di workshop principal' => 'Training sistem POS', 'Pengambilan sparepart & koordinasi vendor' => 'Koordinasi supplier', 'Rapat evaluasi produksi' => 'Rapat evaluasi penjualan',
                'Nonom / WOM' => 'Helper / Crew', 'Site Section Head' => 'Supervisor Toko', 'Site Department Head' => 'Store Manager', 'Deputy Project Manager / Project Manager' => 'Store Manager / Area Manager'],
        ],

        'logistics' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Jakarta', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Hub Cikarang', 'CKR', 'site', 'six_day', 1.27],
                'Site Rantau' => ['Hub Surabaya', 'SBY', 'site', 'six_day', 1.27],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kabupaten Bekasi', 'Kalimantan Tengah' => 'Jawa Barat', 'Kabupaten Tapin' => 'Kota Surabaya', 'Kalimantan Selatan' => 'Jawa Timur', 'Murung Raya' => 'Cikarang', 'Puruk Cahu' => 'Cikarang', 'Rantau' => 'Surabaya'],
            'units' => ['PRODUKSI' => 'TRANSPORTASI', 'MAINTENANCE' => 'MAINTENANCE ARMADA', 'OPERASI TAMBANG' => 'PENGIRIMAN', 'WORKSHOP' => 'BENGKEL ARMADA'],
            'job_levels' => ['Project Manager' => 'Branch Manager', 'Deputy Project Manager' => 'Hub Manager', 'Section Head Site' => 'Supervisor Hub', 'Section Head HO' => 'Supervisor HO',
                'Officer HO/ Group Leader' => 'Officer / Koordinator', 'FGDP' => 'Management Trainee', 'Operator' => 'Driver', 'Nonom' => 'Helper / Kenek', 'Welder' => 'Checker'],
            'title_prefixes' => ['PROJECT MANAGER' => 'BRANCH MANAGER', 'DEPUTY PROJECT MANAGER' => 'HUB MANAGER', 'SECTION HEAD' => 'SUPERVISOR', 'GROUP LEADER' => 'KOORDINATOR', 'FGDP' => 'MANAGEMENT TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'DRIVER', 'MECHANIC' => 'MEKANIK ARMADA'],
            'grade_groups' => ['NONOM' => 'HELPER', 'OPERATOR' => 'DRIVER', 'WELDER' => 'CHECKER'],
            'categories' => ['Operator Magang' => 'Driver Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Driver Truk', 'Driver Truk CDD / Tronton', 'Dibutuhkan driver berpengalaman antarkota untuk armada CDD, Fuso dan tronton. Wajib SIM B1/B2 Umum.'],
                'Mekanik Alat Berat' => ['Mekanik Armada', 'Mekanik Truk', 'Perawatan berkala dan perbaikan armada truk di bengkel hub. Diutamakan lulusan SMK/D3 Otomotif.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Jenis Kendaraan', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman Mengemudi (tahun)', 'SIMPER / SIO' => 'SIM B1 / B2 Umum',
                'Dump Truck (HD785 / HD465)' => 'CDD', 'Excavator (PC2000 / PC1250)' => 'Fuso', 'Bulldozer (D375 / D155)' => 'Tronton', 'Grader' => 'Trailer',
                'Operator Dump Truck / Excavator' => 'Driver Truk CDD / Tronton', 'Mekanik Heavy Equipment' => 'Mekanik Truk'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia rute antarkota dan shift 24 jam', 'prosedur P2H dan safety' => 'P2H kendaraan dan safety driving', 'Uji praktik unit di site' => 'Uji praktik mengemudi',
                'Training overhaul engine di workshop principal' => 'Training overhaul engine truk', 'Rapat evaluasi produksi' => 'Rapat evaluasi pengiriman',
                'Nonom / WOM' => 'Helper / Kenek', 'Site Section Head' => 'Supervisor Hub', 'Site Department Head' => 'Hub Manager', 'Deputy Project Manager / Project Manager' => 'Hub Manager / Branch Manager'],
        ],

        'construction' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Jakarta', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Proyek Tol Semarang', 'SMG', 'site', 'six_day', 1.27],
                'Site Rantau' => ['Proyek Gedung Makassar', 'MKS', 'site', 'six_day', 1.27],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kota Semarang', 'Kalimantan Tengah' => 'Jawa Tengah', 'Kabupaten Tapin' => 'Kota Makassar', 'Kalimantan Selatan' => 'Sulawesi Selatan', 'Murung Raya' => 'Semarang', 'Puruk Cahu' => 'Semarang', 'Rantau' => 'Makassar'],
            'units' => ['PRODUKSI' => 'PELAKSANAAN PROYEK', 'MAINTENANCE' => 'PERALATAN', 'OPERASI TAMBANG' => 'SIPIL & STRUKTUR', 'WORKSHOP' => 'WORKSHOP ALAT'],
            'job_levels' => ['Section Head Site' => 'Site Engineer', 'Section Head HO' => 'Supervisor HO', 'Officer HO/ Group Leader' => 'Officer / Mandor', 'FGDP' => 'Engineer Trainee',
                'Operator' => 'Tukang / Pelaksana', 'Nonom' => 'Pekerja Harian'],
            'title_prefixes' => ['SECTION HEAD' => 'SITE ENGINEER', 'GROUP LEADER' => 'MANDOR', 'FGDP' => 'ENGINEER TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'TUKANG BESI & BEKISTING', 'MECHANIC' => 'MEKANIK ALAT'],
            'grade_groups' => ['NONOM' => 'PEKERJA HARIAN', 'OPERATOR' => 'TUKANG'],
            'categories' => ['Operator Magang' => 'Tukang Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Tukang / Pelaksana Lapangan', 'Tukang Besi & Bekisting', 'Dibutuhkan tukang besi, bekisting dan pengecoran berpengalaman untuk proyek jalan tol Semarang. Kontrak per proyek.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Keahlian', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman Proyek (tahun)', 'SIMPER / SIO' => 'SKT / Sertifikat Kompetensi',
                'Dump Truck (HD785 / HD465)' => 'Pembesian', 'Excavator (PC2000 / PC1250)' => 'Bekisting', 'Bulldozer (D375 / D155)' => 'Pengecoran', 'Grader' => 'Finishing',
                'Operator Dump Truck / Excavator' => 'Tukang Besi & Bekisting'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia penempatan proyek dan kontrak per proyek', 'Uji praktik unit di site' => 'Uji praktik di lapangan proyek', 'Rapat evaluasi produksi' => 'Rapat evaluasi progres proyek',
                'Nonom / WOM' => 'Pekerja Harian', 'Site Section Head' => 'Site Engineer'],
        ],

        'plantation' => [
            'locations' => [
                'Head Office' => ['Kantor Pusat Pekanbaru', 'HO', 'head_office', 'five_day', 0.24],
                'Site Murung Raya' => ['Kebun Pelalawan', 'PLW', 'site', 'six_day', 1.27],
                'Site Rantau' => ['Pabrik Kelapa Sawit Siak', 'PKS', 'site', 'six_day', 1.27],
            ],
            'places' => ['Kabupaten Murung Raya' => 'Kabupaten Pelalawan', 'Kalimantan Tengah' => 'Riau', 'Kabupaten Tapin' => 'Kabupaten Siak', 'Kalimantan Selatan' => 'Riau', 'Murung Raya' => 'Pelalawan', 'Puruk Cahu' => 'Pangkalan Kerinci', 'Rantau' => 'Siak'],
            'units' => ['OPERASIONAL' => 'OPERASIONAL KEBUN', 'PRODUKSI' => 'TANAMAN', 'MAINTENANCE' => 'PABRIK & TEKNIK', 'OPERASI TAMBANG' => 'AFDELING I', 'WORKSHOP' => 'PROSES PKS'],
            'job_levels' => ['Project Manager' => 'Estate Manager', 'Deputy Project Manager' => 'Asisten Kepala', 'Section Head Site' => 'Asisten Afdeling', 'Section Head HO' => 'Supervisor HO',
                'Officer HO/ Group Leader' => 'Officer / Mandor', 'FGDP' => 'Asisten Trainee', 'Operator' => 'Pemanen', 'Mekanik' => 'Operator PKS', 'Nonom' => 'Pekerja Rawat', 'Welder' => 'Welder'],
            'title_prefixes' => ['PROJECT MANAGER' => 'ESTATE MANAGER', 'DEPUTY PROJECT MANAGER' => 'ASISTEN KEPALA', 'SECTION HEAD' => 'ASISTEN', 'GROUP LEADER' => 'MANDOR', 'FGDP' => 'ASISTEN TRAINEE'],
            'disciplines' => ['PRODUCTION OPERATOR' => 'PEMANEN', 'MECHANIC' => 'OPERATOR PKS', 'HSE FIELD' => 'K3 KEBUN'],
            'grade_groups' => ['NONOM' => 'PEKERJA RAWAT', 'OPERATOR' => 'PEMANEN', 'MEKANIK' => 'OPERATOR PKS'],
            'categories' => ['Mekanik Magang' => 'Operator PKS Magang', 'Operator Magang' => 'Pemanen Magang'],
            'postings' => [
                'Operator Alat Berat' => ['Pemanen Sawit', 'Pemanen TBS', 'Dibutuhkan pemanen tandan buah segar (TBS) berpengalaman untuk afdeling di Pelalawan. Premi basis borong.'],
                'Mekanik Alat Berat' => ['Operator PKS', 'Operator Pabrik Kelapa Sawit', 'Mengoperasikan stasiun sterilizer, press dan boiler di PKS Siak. Kerja shift, diutamakan berpengalaman di PKS.'],
            ],
            'answers' => ['Unit yang Dikuasai' => 'Pekerjaan yang Dikuasai', 'Pengalaman Mengoperasikan (tahun)' => 'Pengalaman di Kebun (tahun)', 'SIMPER / SIO' => 'Surat Pengalaman Kerja',
                'Dump Truck (HD785 / HD465)' => 'Panen TBS', 'Excavator (PC2000 / PC1250)' => 'Pemupukan', 'Bulldozer (D375 / D155)' => 'Penyemprotan', 'Grader' => 'Perawatan Piringan',
                'Spesialisasi' => 'Stasiun PKS', 'Engine' => 'Sterilizer', 'Powertrain' => 'Kernel Plant', 'Hydraulic' => 'Press', 'Electrical' => 'Boiler',
                'Operator Dump Truck / Excavator' => 'Pemanen TBS', 'Mekanik Heavy Equipment' => 'Operator Pabrik Kelapa Sawit'],
            'text' => ['bersedia penempatan site dan roster 10:2' => 'bersedia tinggal di emplasmen dengan premi basis borong', 'prosedur P2H dan safety' => 'standar panen dan K3', 'Uji praktik unit di site' => 'Uji praktik panen di blok',
                'Training overhaul engine di workshop principal' => 'Training operasional PKS', 'Rapat evaluasi produksi' => 'Rapat evaluasi produksi TBS',
                'Nonom / WOM' => 'Pekerja Rawat', 'Site Section Head' => 'Asisten Afdeling', 'Site Department Head' => 'Asisten Kepala', 'Deputy Project Manager / Project Manager' => 'Asisten Kepala / Estate Manager'],
        ],
    ];
}
