<?php

namespace Database\Seeders;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * 25 more fictional employees for the demo (DemoSeeder): operators at Site Murung Raya,
 * mechanics at Site Rantau and staff at Head Office, so Man Onsite, Absensi and the
 * Comben pages have a crew to show. Built like KaryawanSeeder's; NRPs continue its numbering.
 */
class DemoEmployeeSeeder extends EmployeeSeeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Continue J LL YY NNN after the employees already there.
        foreach (Employee::pluck('employee_number') as $employee_number) {
            $prefix = substr($employee_number, 0, 5);
            $this->sequence[$prefix] = max($this->sequence[$prefix] ?? 0, (int) substr($employee_number, 5));
        }
        parent::run();
    }

    protected function firstIndex(): int
    {
        return Employee::count();
    }

    protected function employees(CarbonImmutable $t): array
    {
        $out = [];
        // [nama, gender, jabatan, lahir, tempat lahir, kota, lokasi, masuk (tetap) or PKWT months left, agama, suku, pendidikan, pasangan, anak]
        $people = [
            ['Ahmad Fauzi', 'L', 'OPR-PRD', '1987-02-11', 'Banjarmasin', 'tapin', '15', '2012-05-02', 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 2 Banjarmasin', 2005], 'Nurul Hidayah', ['Alif Fauzi', 'Zahra Fauzi']],
            ['Cahyo Purnomo', 'L', 'OPR-PRD', '1990-09-23', 'Klaten', 'surabaya', '15', '2014-03-10', 'Islam', 'Jawa', ['SMA/SMK', 'Teknik Kendaraan Ringan', 'SMK Negeri 1 Klaten', 2008], 'Rina Wulandari', ['Dimas Purnomo']],
            ['Deni Kurniawan', 'L', 'OPR-PRD', '1992-12-04', 'Palangka Raya', 'murung_raya', '15', '2015-08-17', 'Islam', 'Dayak', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Palangka Raya', 2010], 'Lusi Anggraini', []],
            ['Eko Prasetyo', 'L', 'OPR-PRD', '1985-06-30', 'Surabaya', 'surabaya', '15', '2011-01-03', 'Islam', 'Jawa', ['SMA/SMK', 'Teknik Mesin', 'STM Pembangunan Surabaya', 2003], 'Desi Ratnasari', ['Rafa Prasetyo', 'Naura Prasetyo']],
            ['Ferdi Saputra', 'L', 'OPR-PRD', '1996-04-14', 'Muara Teweh', 'murung_raya', '15', 10, 'Islam', 'Dayak', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Muara Teweh', 2014], null, []],
            ['Gilang Ramadhan', 'L', 'OPR-PRD', '1998-01-19', 'Puruk Cahu', 'murung_raya', '15', 7, 'Islam', 'Dayak', ['SMA/SMK', 'Teknik Kendaraan Ringan', 'SMK Negeri 1 Puruk Cahu', 2016], null, []],
            ['Hari Susanto', 'L', 'OPR-PRD', '1988-10-08', 'Malang', 'surabaya', '15', '2016-02-01', 'Kristen', 'Jawa', ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 6 Malang', 2006], 'Yuliana Susanti', ['Kevin Susanto']],
            ['Irfan Maulana', 'L', 'OPR-PRD', '1995-07-27', 'Martapura', 'tapin', '15', 3, 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Martapura', 2013], 'Siti Nurjanah', []],
            ['Jefri Simatupang', 'L', 'OPR-PRD', '1993-03-15', 'Medan', 'medan', '15', '2017-06-12', 'Kristen', 'Batak', ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 5 Medan', 2011], 'Ruth Siregar', ['Joel Simatupang']],
            ['Kevin Sitorus', 'L', 'OPR-PRD', '1999-11-02', 'Pematangsiantar', 'medan', '15', 11, 'Kristen', 'Batak', ['SMA/SMK', 'Teknik Kendaraan Ringan', 'SMK Negeri 2 Pematangsiantar', 2017], null, []],
            ['Lukman Hakim', 'L', 'OPR-PRD', '1986-05-21', 'Kandangan', 'tapin', '15', '2013-09-09', 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 1 Kandangan', 2004], 'Halimah', ['Hafiz Hakim', 'Aisyah Hakim']],
            ['Nur Aini', 'P', 'OPR-PRD', '1997-08-25', 'Puruk Cahu', 'murung_raya', '15', 5, 'Islam', 'Dayak', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Puruk Cahu', 2015], null, []],
            ['Mario Tambunan', 'L', 'MKN-MTC', '1984-01-29', 'Medan', 'medan', '03', '2010-04-05', 'Kristen', 'Batak', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 3 Medan', 2002], 'Lenny Hutapea', ['Daniel Tambunan', 'Grace Tambunan']],
            ['Nanda Saputra', 'L', 'MKN-MTC', '1994-09-12', 'Rantau', 'tapin', '03', '2018-01-08', 'Islam', 'Banjar', ['D3', 'Teknik Mesin', 'Politeknik Negeri Banjarmasin', 2015], 'Fitria', []],
            ['Oki Firmansyah', 'L', 'MKN-MTC', '1991-02-17', 'Banjarbaru', 'tapin', '03', '2015-11-02', 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Banjarbaru', 2009], 'Mega Lestari', ['Raka Firmansyah']],
            ['Putra Wibowo', 'L', 'MKN-MTC', '1997-06-06', 'Semarang', 'surabaya', '03', 9, 'Islam', 'Jawa', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 7 Semarang', 2015], null, []],
            ['Rendi Gunawan', 'L', 'MKN-MTC', '1989-12-24', 'Rantau', 'tapin', '03', '2012-07-16', 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 1 Rantau', 2007], 'Ayu Pratiwi', ['Nabil Gunawan', 'Nayla Gunawan']],
            ['Samsul Arifin', 'L', 'MKN-MTC', '1983-04-03', 'Barabai', 'tapin', '03', '2009-03-02', 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Barabai', 2001], 'Rahmah', ['Ilham Arifin', 'Intan Arifin', 'Ikhsan Arifin']],
            ['Taufik Hidayat', 'L', 'MKN-MTC', '1998-10-10', 'Kotabaru', 'tapin', '03', 2, 'Islam', 'Banjar', ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Kotabaru', 2016], null, []],
            ['Udin Syahputra', 'L', 'MKN-MTC', '1995-05-05', 'Tanjung', 'tapin', '03', '2019-05-13', 'Islam', 'Banjar', ['D3', 'Teknik Alat Berat', 'Politeknik Negeri Banjarmasin', 2016], 'Wulan Dari', ['Arsyad Syahputra']],
            ['Vina Oktaviani', 'P', 'STF-HRG', '1996-10-18', 'Bogor', 'jakarta', '01', 6, 'Islam', 'Sunda', ['S1', 'Psikologi', 'Universitas Pakuan', 2018], null, []],
            ['Winda Kartika', 'P', 'STF-FIN', '1993-01-07', 'Jakarta', 'jakarta', '11', '2018-09-03', 'Islam', 'Betawi', ['S1', 'Akuntansi', 'Universitas Negeri Jakarta', 2015], 'Rizal Fahmi', ['Kirana Fahmi']],
            ['Yudi Hermawan', 'L', 'STF-HRG', '1991-07-22', 'Bekasi', 'jakarta', '01', '2016-10-03', 'Islam', 'Sunda', ['S1', 'Hukum', 'Universitas Islam Bandung', 2013], 'Nadia Putri Utami', ['Fathan Hermawan']],
            ['Zulkifli Rahman', 'L', 'KDV-UMK', '1972-03-12', 'Makassar', 'makassar', '01', '2003-06-02', 'Islam', 'Bugis', ['S2', 'Manajemen', 'Universitas Hasanuddin', 1999], 'Andi Nurhaeda', ['Andi Fadil Rahman', 'Andi Nisa Rahman']],
            ['Tri Wahyuningsih', 'P', 'STF-FIN', '1999-03-28', 'Solo', 'surabaya', '01', 8, 'Katolik', 'Jawa', ['S1', 'Akuntansi', 'Universitas Sebelas Maret', 2021], null, []],
        ];
        foreach ($people as [$name, $gender, $position, $birth, $provider_name, $city, $location, $hired, $religion, $ethnic_group, $education_level, $spouse, $children]) {
            $permanent = is_string($hired);
            $out[] = [
                'name' => $name, 'gender' => $gender, 'position' => $position,
                'birth' => $birth, 'birth_place' => $provider_name, 'city' => $city, 'location' => $location,
                ...($permanent
                    ? ['hired' => $hired, 'appointment_letter' => CarbonImmutable::parse($hired)->addYear()->toDateString()]
                    // PKWT: a 12-month contract ending in $masuk months.
                    : ['contract' => $this->periodsEndingOn($t->addMonths($hired), [12])]),
                'religion' => $religion, 'ethnic_group' => $ethnic_group, 'education_level' => $education_level,
                ...($spouse ? ['is_married' => [$spouse, CarbonImmutable::parse($birth)->addYears(25)->toDateString(), $gender === 'P' ? 'Karyawan Swasta' : 'Ibu Rumah Tangga'], 'children' => $children] : []),
            ];
        }

        return $out;
    }
}
