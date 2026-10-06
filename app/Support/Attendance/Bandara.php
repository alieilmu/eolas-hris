<?php

namespace App\Support\Attendance;

/** Indonesian airports with scheduled flights, for the flight ticket asked with a cuti / dinas. */
class Bandara
{
    /** IATA code => [nama bandara, kota, provinsi]. */
    public const ALL = [
        // Jawa
        'CGK' => ['Soekarno-Hatta', 'Jakarta / Tangerang', 'Banten'],
        'HLP' => ['Halim Perdanakusuma', 'Jakarta', 'DKI Jakarta'],
        'BDO' => ['Husein Sastranegara', 'Bandung', 'Jawa Barat'],
        'KJT' => ['Kertajati', 'Majalengka', 'Jawa Barat'],
        'CBN' => ['Cakrabhuwana', 'Cirebon', 'Jawa Barat'],
        'SRG' => ['Jenderal Ahmad Yani', 'Semarang', 'Jawa Tengah'],
        'SOC' => ['Adi Soemarmo', 'Solo / Surakarta', 'Jawa Tengah'],
        'CXP' => ['Tunggul Wulung', 'Cilacap', 'Jawa Tengah'],
        'PWL' => ['Jenderal Besar Soedirman', 'Purbalingga', 'Jawa Tengah'],
        'YIA' => ['Yogyakarta International', 'Yogyakarta / Kulon Progo', 'DI Yogyakarta'],
        'JOG' => ['Adisutjipto', 'Yogyakarta', 'DI Yogyakarta'],
        'SUB' => ['Juanda', 'Surabaya / Sidoarjo', 'Jawa Timur'],
        'MLG' => ['Abdul Rachman Saleh', 'Malang', 'Jawa Timur'],
        'BWX' => ['Banyuwangi', 'Banyuwangi', 'Jawa Timur'],
        'JBB' => ['Notohadinegoro', 'Jember', 'Jawa Timur'],
        'SUP' => ['Trunojoyo', 'Sumenep', 'Jawa Timur'],
        // Bali & Nusa Tenggara
        'DPS' => ['I Gusti Ngurah Rai', 'Denpasar', 'Bali'],
        'LOP' => ['Zainuddin Abdul Madjid', 'Lombok / Praya', 'Nusa Tenggara Barat'],
        'BMU' => ['Sultan Muhammad Salahuddin', 'Bima', 'Nusa Tenggara Barat'],
        'SWQ' => ['Sultan Muhammad Kaharuddin', 'Sumbawa Besar', 'Nusa Tenggara Barat'],
        'KOE' => ['El Tari', 'Kupang', 'Nusa Tenggara Timur'],
        'LBJ' => ['Komodo', 'Labuan Bajo', 'Nusa Tenggara Timur'],
        'ENE' => ['H. Hasan Aroeboesman', 'Ende', 'Nusa Tenggara Timur'],
        'MOF' => ['Frans Seda', 'Maumere', 'Nusa Tenggara Timur'],
        'TMC' => ['Tambolaka', 'Sumba Barat Daya', 'Nusa Tenggara Timur'],
        'WGP' => ['Umbu Mehang Kunda', 'Waingapu', 'Nusa Tenggara Timur'],
        // Kalimantan
        'BPN' => ['Sultan Aji Muhammad Sulaiman Sepinggan', 'Balikpapan', 'Kalimantan Timur'],
        'AAP' => ['Aji Pangeran Tumenggung Pranoto', 'Samarinda', 'Kalimantan Timur'],
        'BEJ' => ['Kalimarau', 'Berau / Tanjung Redeb', 'Kalimantan Timur'],
        'GHS' => ['Melalan', 'Kutai Barat / Melak', 'Kalimantan Timur'],
        'DTD' => ['Datah Dawai', 'Mahakam Ulu', 'Kalimantan Timur'],
        'TRK' => ['Juwata', 'Tarakan', 'Kalimantan Utara'],
        'TJS' => ['Tanjung Harapan', 'Tanjung Selor', 'Kalimantan Utara'],
        'NNX' => ['Nunukan', 'Nunukan', 'Kalimantan Utara'],
        'BDJ' => ['Syamsudin Noor', 'Banjarmasin / Banjarbaru', 'Kalimantan Selatan'],
        'TJG' => ['Warukin', 'Tanjung / Tabalong', 'Kalimantan Selatan'],
        'KBU' => ['Gusti Syamsir Alam (Stagen)', 'Kotabaru', 'Kalimantan Selatan'],
        'BTW' => ['Bersujud', 'Batulicin / Tanah Bumbu', 'Kalimantan Selatan'],
        'PKY' => ['Tjilik Riwut', 'Palangka Raya', 'Kalimantan Tengah'],
        'SMQ' => ['H. Asan', 'Sampit', 'Kalimantan Tengah'],
        'PKN' => ['Iskandar', 'Pangkalan Bun', 'Kalimantan Tengah'],
        'PNK' => ['Supadio', 'Pontianak', 'Kalimantan Barat'],
        'KTG' => ['Rahadi Oesman', 'Ketapang', 'Kalimantan Barat'],
        'SQG' => ['Tebelian', 'Sintang', 'Kalimantan Barat'],
        // Sumatera
        'KNO' => ['Kualanamu', 'Medan / Deli Serdang', 'Sumatera Utara'],
        'DTB' => ['Sisingamangaraja XII (Silangit)', 'Siborong-borong / Danau Toba', 'Sumatera Utara'],
        'FLZ' => ['Dr. Ferdinand Lumban Tobing', 'Sibolga', 'Sumatera Utara'],
        'GNS' => ['Binaka', 'Gunungsitoli / Nias', 'Sumatera Utara'],
        'AEG' => ['Aek Godang', 'Padang Sidempuan', 'Sumatera Utara'],
        'BTJ' => ['Sultan Iskandar Muda', 'Banda Aceh', 'Aceh'],
        'LSW' => ['Malikus Saleh', 'Lhokseumawe', 'Aceh'],
        'MEQ' => ['Cut Nyak Dhien', 'Meulaboh / Nagan Raya', 'Aceh'],
        'SBG' => ['Maimun Saleh', 'Sabang', 'Aceh'],
        'PDG' => ['Minangkabau', 'Padang', 'Sumatera Barat'],
        'PKU' => ['Sultan Syarif Kasim II', 'Pekanbaru', 'Riau'],
        'BTH' => ['Hang Nadim', 'Batam', 'Kepulauan Riau'],
        'TNJ' => ['Raja Haji Fisabilillah', 'Tanjungpinang', 'Kepulauan Riau'],
        'NTX' => ['Raden Sadjad', 'Natuna / Ranai', 'Kepulauan Riau'],
        'DJB' => ['Sultan Thaha', 'Jambi', 'Jambi'],
        'PLM' => ['Sultan Mahmud Badaruddin II', 'Palembang', 'Sumatera Selatan'],
        'LLJ' => ['Silampari', 'Lubuklinggau', 'Sumatera Selatan'],
        'BKS' => ['Fatmawati Soekarno', 'Bengkulu', 'Bengkulu'],
        'TKG' => ['Radin Inten II', 'Bandar Lampung', 'Lampung'],
        'PGK' => ['Depati Amir', 'Pangkal Pinang', 'Kepulauan Bangka Belitung'],
        'TJQ' => ['H.A.S. Hanandjoeddin', 'Tanjung Pandan / Belitung', 'Kepulauan Bangka Belitung'],
        // Sulawesi
        'UPG' => ['Sultan Hasanuddin', 'Makassar / Maros', 'Sulawesi Selatan'],
        'MXB' => ['Andi Jemma', 'Masamba', 'Sulawesi Selatan'],
        'MDC' => ['Sam Ratulangi', 'Manado', 'Sulawesi Utara'],
        'GTO' => ['Djalaluddin', 'Gorontalo', 'Gorontalo'],
        'PLW' => ['Mutiara SIS Al-Jufrie', 'Palu', 'Sulawesi Tengah'],
        'LUW' => ['Syukuran Aminuddin Amir', 'Luwuk', 'Sulawesi Tengah'],
        'PSJ' => ['Kasiguncu', 'Poso', 'Sulawesi Tengah'],
        'TLI' => ['Sultan Bantilan', 'Tolitoli', 'Sulawesi Tengah'],
        'MJU' => ['Tampa Padang', 'Mamuju', 'Sulawesi Barat'],
        'KDI' => ['Haluoleo', 'Kendari', 'Sulawesi Tenggara'],
        'BUW' => ['Betoambari', 'Baubau', 'Sulawesi Tenggara'],
        'WNI' => ['Matahora', 'Wakatobi', 'Sulawesi Tenggara'],
        // Maluku & Papua
        'AMQ' => ['Pattimura', 'Ambon', 'Maluku'],
        'LUV' => ['Karel Sadsuitubun', 'Langgur / Tual', 'Maluku'],
        'SXK' => ['Mathilda Batlayeri', 'Saumlaki', 'Maluku'],
        'TTE' => ['Sultan Babullah', 'Ternate', 'Maluku Utara'],
        'DJJ' => ['Dortheys Hiyo Eluay (Sentani)', 'Jayapura', 'Papua'],
        'BIK' => ['Frans Kaisiepo', 'Biak', 'Papua'],
        'TIM' => ['Mozes Kilangin', 'Timika', 'Papua Tengah'],
        'NBX' => ['Douw Aturure', 'Nabire', 'Papua Tengah'],
        'WMX' => ['Wamena', 'Wamena', 'Papua Pegunungan'],
        'MKQ' => ['Mopah', 'Merauke', 'Papua Selatan'],
        'DEO' => ['Domine Eduard Osok', 'Sorong', 'Papua Barat Daya'],
        'MKW' => ['Rendani', 'Manokwari', 'Papua Barat'],
        'FKQ' => ['Torea', 'Fakfak', 'Papua Barat'],
        'KNG' => ['Utarom', 'Kaimana', 'Papua Barat'],
    ];

    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    /** For the searchable picker in the browser. */
    public static function options(): array
    {
        return collect(self::ALL)->map(fn (array $b, string $kode) => ['kode' => $kode, 'nama' => $b[0], 'kota' => $b[1], 'provinsi' => $b[2]])->values()->all();
    }

    /** "BPN · Balikpapan" for lists and exports. */
    public static function label(?string $kode): ?string
    {
        return $kode ? $kode.' · '.(self::ALL[$kode][1] ?? '') : null;
    }
}
