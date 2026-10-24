# Kamus Pemetaan Terminologi HRIS — Eolas

> Status: **DRAF untuk review** (25 Okt 2026). Belum ada kode atau database yang diubah.
> Cakupan: 59 tabel aplikasi (8 tabel bawaan framework tidak diubah), ±690 kolom, modul/submodul/menu, URL, kunci hak akses, nilai status, serta nama kelas & folder kode.
> Frontend: **React + TypeScript (Inertia)**, bukan Vue.

---

## A. Prinsip penamaan

| Aspek | Aturan | Contoh |
|---|---|---|
| Tabel & kolom database | Bahasa Inggris, `snake_case`, tabel jamak | `karyawans` → `employees` |
| Foreign key | `<entitas_tunggal>_id` | `jabatan_id` → `position_id` |
| Boolean | awalan `is_` / `has_` / `needs_` | `berhak` → `is_eligible` |
| Tanggal / waktu | `_date` untuk tanggal, `_at` untuk timestamp | `tanggal_masuk` → `hire_date` |
| Nominal uang | `_amount` | `nominal` → `amount` |
| Urutan tampil | `sort_order` (menggantikan `order` dan `urutan`, `order` adalah kata kunci SQL) | `urutan` → `sort_order` |
| Nilai status / jenis (enum sistem) | kode Inggris huruf kecil; **label di layar tetap Bahasa Indonesia** | `disetujui` → `approved` (tampil "Disetujui") |
| Istilah hukum Indonesia | dipertahankan sebagai nama diri bila tidak ada padanan universal | BPJS, PTKP, PKWT (disebut *fixed-term contract*) |
| Isi master data buatan pengguna | **tidak diterjemahkan** (agama, pendidikan, status nikah, ukuran baju, dll.) | "Menikah" tetap "Menikah" |
| Kelas & folder kode | Bahasa Inggris, mengikuti nama modul baru | `Karyawan` → `Employee` |

---

## B. Modul & submodul

### B.1 Modul

| Modul lama | Modul baru (kode) | Label menu baru (ID) | Prefix URL baru |
|---|---|---|---|
| Ringkasan | Dashboard | Dashboard | `/dashboard` |
| Modul Recruitment | **Recruitment** | Rekrutmen | `/recruitment` |
| Modul Manpower | **Core HR** | Core HR (Organisasi & Karyawan) | `/core-hr` |
| Modul Attendance | **Time & Attendance** | Waktu & Kehadiran | `/time` |
| *(Cuti di dalam Comben)* | **Leave** *(dipindah ke Time & Attendance)* | Cuti | `/time/leave` |
| Modul Comben | **Compensation & Benefits** | Kompensasi & Benefit | `/compensation` |
| Portal Karyawan | **Employee Self-Service (ESS)** | Layanan Mandiri Karyawan | `/self-service` |
| Settings | **Administration** | Administrasi | `/settings` |
| Lowongan (publik) | **Careers** | Karier / Lowongan | `/careers` |

### B.2 Submodul, URL, dan kunci hak akses

Kunci hak akses tersimpan di `users.permissions`, sehingga **data pengguna ikut dimigrasi**.

| Submodul lama | Submodul baru | URL lama → URL baru | Kunci akses lama → baru |
|---|---|---|---|
| Recruitment Tracking | Candidate Pipeline | `/recruitment/tracking` → `/recruitment/pipeline` | `recruitment` → `recruitment` |
| Pelamar | Applicants | `/recruitment/pelamar` → `/recruitment/applicants` | 〃 |
| Pengaturan Form | Job Postings | `/recruitment/form` → `/recruitment/job-postings` | 〃 |
| Laporan | Recruitment Reports | `/recruitment/laporan` → `/recruitment/reports` | 〃 |
| Struktur Organisasi | Organization Chart | `/manpower/org-structure` → `/core-hr/org-chart` | `org-structure` → `org-chart` |
| Man Power Planning | Workforce Planning | `/manpower/mpp` → `/core-hr/workforce-planning` | `mpp` → `workforce-planning` |
| Persetujuan Revisi MPP | Workforce Plan Approval | (dalam halaman di atas) | `mpp-approve` → `workforce-planning-approval` |
| Man Power (data karyawan) | Employees | `/manpower/karyawan` → `/core-hr/employees` | `karyawan` → `employees` |
| Pengajuan Update Data | Data Change Requests | `/manpower/pengajuan-data` → `/core-hr/change-requests` | `update-data` → `data-change-requests` |
| Master Data Personalia | Core HR Settings | `/manpower/master-data` → `/core-hr/settings` | `master-personalia` → `core-hr-settings` |
| Roster | Shift Schedule | `/attendance/roster` → `/time/shift-schedule` | `roster` → `shift-schedule` |
| Absensi | Attendance Records | `/attendance/absensi` → `/time/attendance` | `absensi` → `attendance-records` |
| Kehadiran (CI/CO) | Time Clock | `/attendance/kehadiran` → `/time/clock` | `kehadiran` → `time-clock` |
| Persetujuan Lembur | Overtime Approval | `…/kehadiran?tab=lembur` → `/time/overtime` | `lembur-approve` → `overtime-approval` |
| Trip & Leave | Leave & Travel | `/attendance/trip-leave` → `/time/leave-travel` | `trip-leave` → `leave-travel` |
| Pengajuan Dinas | Travel Requests | `/attendance/pengajuan-dinas` → `/time/travel-requests` | `pengajuan-dinas` → `travel-requests` |
| Cuti Tahunan & Besar *(Comben)* | Leave Balances *(pindah ke Time & Attendance)* | `/comben/cuti` → `/time/leave-balances` | `cuti` → `leave-balances` |
| Master Data Attendance | Time & Attendance Settings | `/attendance/master-data` → `/time/settings` | `master-attendance` → `time-settings` |
| Deklarasi | Benefit Claims | `/comben/deklarasi` → `/compensation/claims` | `deklarasi` → `benefit-claims` |
| Monitoring Plafon | Benefit Balances | `/comben/monitoring` → `/compensation/benefit-balances` | `monitoring-plafon` → `benefit-balances` |
| Data Gaji | Employee Compensation | `/comben/data-gaji` → `/compensation/salaries` | `data-gaji` → `employee-compensation` |
| Master Data Comben | Compensation Settings | `/comben/master-data` → `/compensation/settings` | `master-comben` → `compensation-settings` |
| Portal (Beranda, Data Saya) | ESS Home, My Profile | `/portal`, `/portal/data` → `/self-service`, `/self-service/profile` | — |
| Akun & Akses | Users & Roles | `/settings/akun` → `/settings/users` | super admin |
| White Label | Branding | `/settings/white-label` → `/settings/branding` | super admin |
| Akun Demo | Trial Accounts | `/settings/akun-demo` → `/settings/trial-accounts` | super admin |
| Ganti Password | Change Password | `/akun/password` → `/account/password` | — |
| Lowongan publik | Careers | `/lowongan` → `/careers` | publik |

URL lama dibuat **redirect 301** ke URL baru, supaya bookmark dan link di notifikasi lama tetap jalan.

### B.3 Tab & bagian master data

| Lama | Baru |
|---|---|
| Master Data Personalia: Jenis Karyawan, Lokasi Penerimaan, Lokasi Kerja, Sub Lokasi, BOD, Divisi, Departemen, Section, SubGroup, Jabatan, Pilihan (opsi) | Core HR Settings: Employee Categories, Points of Hire, Work Locations, Sub-locations, Directorates, Divisions, Departments, Sections, Job Levels, Positions, Lookup Values |
| Master Data Attendance: Kode Shift, Pola Roster, Status Pengajuan Uang, Waktu Kerja & Lembur, Hari Libur | Time Settings: Shift Codes, Shift Patterns, Cash Advance Statuses, Work Schedule & Overtime, Public Holidays |
| Master Data Comben: Golongan, Struktur Skala Upah, Tunjangan Jabatan, Plafon Pengobatan, Plafon Kacamata, Aturan Cuti, Cuti Lapangan, Lumpsum Cuti Lapangan | Compensation Settings: Grades, Salary Scale, Job Level Allowances, Medical Benefit Limits, Optical Benefit Limits; **Leave Settings**: Leave Policy, Field Leave (R&R) Entitlements, Field Leave Allowances |

### B.4 Peran pengguna (`users.role`)

| Lama | Baru | Label |
|---|---|---|
| `Admin` | `super_admin` | Super Admin |
| `HR` | `admin` | Admin |
| `Karyawan` | `employee` | Karyawan |

---

## C. Tabel

| # | Tabel lama | Tabel baru | Modul |
|---|---|---|---|
| 1 | `karyawans` | `employees` | Core HR |
| 2 | `karyawan_profiles` | `employee_profiles` | Core HR |
| 3 | `karyawan_anak` | `employee_children` | Core HR |
| 4 | `pkwt_periods` | `employment_contracts` | Core HR |
| 5 | `pengajuan_update_datas` | `employee_change_requests` | Core HR |
| 6 | `jenis_karyawans` | `employee_categories` | Core HR |
| 7 | `lokasi_penerimaans` | `points_of_hire` | Core HR |
| 8 | `work_locations` | `work_locations` *(tetap)* | Core HR |
| 9 | `sub_locations` | `sub_locations` *(tetap)* | Core HR |
| 10 | `bods` | `directorates` | Core HR |
| 11 | `divisis` | `divisions` | Core HR |
| 12 | `departemens` | `departments` | Core HR |
| 13 | `sections` | `sections` *(tetap)* | Core HR |
| 14 | `jabatans` | `positions` | Core HR |
| 15 | `sub_groups` | `job_levels` | Core HR |
| 16 | `master_options` | `lookup_values` | Core HR |
| 17 | `mpp_plans` | `workforce_plans` | Core HR |
| 18 | `mpp_plan_items` | `workforce_plan_items` | Core HR |
| 19 | `mpp_revisis` | `workforce_plan_revisions` | Core HR |
| 20 | `mpp_aturans` | `workforce_plan_settings` | Core HR |
| 21 | `application_forms` | `job_postings` | Recruitment |
| 22 | `form_fields` | `job_posting_fields` | Recruitment |
| 23 | `applicants` | `applicants` *(tetap)* | Recruitment |
| 24 | `candidates` | `candidates` *(tetap)* | Recruitment |
| 25 | `candidate_notes` | `candidate_notes` *(tetap)* | Recruitment |
| 26 | `recruitment_stages` | `recruitment_stages` *(tetap)* | Recruitment |
| 27 | `status_histories` | `candidate_stage_histories` | Recruitment |
| 28 | `shift_codes` | `shift_codes` *(tetap)* | Time & Attendance |
| 29 | `roster_patterns` | `shift_patterns` | Time & Attendance |
| 30 | `rosters` | `shift_schedules` | Time & Attendance |
| 31 | `absensis` | `attendance_records` | Time & Attendance |
| 32 | `holidays` | `public_holidays` | Time & Attendance |
| 33 | `trips` | `leave_travel_requests` | Time & Attendance |
| 34 | `absen_logs` | `attendance_logs` | Time & Attendance |
| 35 | `mesin_absens` | `attendance_devices` | Time & Attendance |
| 36 | `mesin_absen_routes` | `attendance_device_routes` | Time & Attendance (DB utama) |
| 37 | `lemburs` | `overtime_records` | Time & Attendance |
| 38 | `lembur_aturans` | `overtime_policies` | Time & Attendance |
| 39 | `sub_group_lembur` | `overtime_eligibilities` | Time & Attendance |
| 40 | `cuti_aturans` | `leave_policies` | Leave |
| 41 | `cuti_besars` | `long_service_leaves` | Leave |
| 42 | `cuti_pengambilans` | `leave_transactions` | Leave |
| 43 | `cuti_lapangans` | `field_leave_entitlements` | Leave |
| 44 | `lumpsum_cutis` | `field_leave_allowances` | Leave |
| 45 | `golongans` | `grades` | Compensation & Benefits |
| 46 | `skala_upahs` | `salary_scales` | Compensation & Benefits |
| 47 | `skala_upah_kolom` | `salary_scale_periods` | Compensation & Benefits |
| 48 | `skala_upah_nilai` | `salary_scale_amounts` | Compensation & Benefits |
| 49 | `karyawan_gajis` | `employee_salaries` | Compensation & Benefits |
| 50 | `tunjangan_jabatans` | `job_level_allowances` | Compensation & Benefits |
| 51 | `plafon_pengobatans` | `medical_benefit_limits` | Compensation & Benefits |
| 52 | `plafon_kacamatas` | `optical_benefit_limits` | Compensation & Benefits |
| 53 | `deklarasis` | `benefit_claims` | Compensation & Benefits |
| 54 | `deklarasi_items` | `benefit_claim_items` | Compensation & Benefits |
| 55 | `lampirans` | `attachments` | Umum |
| 56 | `demo_accounts` | `trial_accounts` | Administration (DB utama) |
| 57 | `brandings` | `brandings` *(tetap)* | Administration |
| 58 | `users` | `users` *(tetap, kolom berubah)* | Administration |
| 59 | `notifications` | `notifications` *(tetap, isi `data` dimigrasi)* | Umum |

Tidak diubah (bawaan Laravel): `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `password_reset_tokens`, `sessions`.

---

## D. Kolom per tabel

Kolom yang tidak disebut **tetap** (mis. `id`, `name`, `email`, `created_at`, `updated_at`, `created_by`, `verified_by`, `verified_at`, `work_location_id`, `sub_location_id`, `section_id`, `status`).

### D.1 Core HR

**`karyawans` → `employees`**
| Lama | Baru |
|---|---|
| nrp | employee_number |
| pin_absen | attendance_pin |
| jabatan_id | position_id |
| sub_group_id | job_level_id |
| bod_id | directorate_id |
| divisi_id | division_id |
| departemen_id | department_id |
| bidang | discipline |
| tanggal_keluar | termination_date |
| alasan_keluar | termination_reason |
| keterangan_keluar | termination_notes |

**`karyawan_profiles` → `employee_profiles`**
| Lama | Baru | Lama | Baru |
|---|---|---|---|
| karyawan_id | employee_id | no_urut | sequence_no |
| nrp_sap | sap_employee_number | klasifikasi | employee_class |
| klasifikasi_teknis | technical_class | tempat_lahir | birth_place |
| tanggal_lahir | birth_date | tanggal_masuk | hire_date |
| status_pegawai | employment_status | status_perkawinan | marital_status |
| tanggal_perkawinan | marriage_date | golongan | grade_code |
| status_pegawai_tetap | is_permanent | nomor_sk | appointment_letter_number |
| tanggal_sk | appointment_letter_date | ukuran_baju | shirt_size |
| ukuran_celana | pants_size | ukuran_sepatu | shoe_size |
| ukuran_apd | ppe_size | nik | national_id_number |
| nik_provinsi | national_id_province | nik_kabupaten_kota | national_id_city |
| npwp | tax_id_number | npwp_tanggal_daftar | tax_id_registered_date |
| bpjs_tk | bpjs_employment_number | bpjs_tk_tanggal_daftar | bpjs_employment_registered_date |
| bpjs_kesehatan | bpjs_health_number | no_rekening | bank_account_number |
| nama_bank | bank_name | nama_pemilik_rekening | bank_account_holder |
| agama | religion | jenis_kelamin | gender |
| suku_bangsa | ethnicity | pendidikan_terakhir | education_level |
| jurusan | education_major | asal_sekolah | education_institution |
| tahun_lulus | graduation_year | alamat_tinggal | residential_address |
| alamat_tinggal_rt | residential_rt | alamat_tinggal_rw | residential_rw |
| alamat_tinggal_kab_kota | residential_city | alamat_tinggal_provinsi | residential_province |
| alamat_tinggal_kode_pos | residential_postal_code | alamat_surat | mailing_address |
| alamat_surat_rt | mailing_rt | alamat_surat_rw | mailing_rw |
| alamat_surat_kab_kota | mailing_city | alamat_surat_kode_pos | mailing_postal_code |
| alamat_surat_telepon | mailing_phone | alamat_surat_hp | mailing_mobile |
| emergency_telepon | emergency_contact_phone | emergency_nama | emergency_contact_name |
| emergency_hubungan | emergency_contact_relationship | no_hp | mobile_phone |
| no_whatsapp | whatsapp_number | email_pribadi | personal_email |
| nama_pasangan | spouse_name | tanggal_lahir_pasangan | spouse_birth_date |
| pekerjaan_pasangan | spouse_occupation | nama_ayah | father_name |
| tanggal_lahir_ayah | father_birth_date | nama_ibu | mother_name |
| tanggal_lahir_ibu | mother_birth_date | ortu_alamat_rt | parents_rt |
| ortu_alamat_rw | parents_rw | ortu_alamat_kab_kota | parents_city |
| ortu_alamat_kode_pos | parents_postal_code | ortu_telepon | parents_phone |
| ortu_hp | parents_mobile | nama_ayah_mertua | father_in_law_name |
| tanggal_lahir_ayah_mertua | father_in_law_birth_date | nama_ibu_mertua | mother_in_law_name |
| tanggal_lahir_ibu_mertua | mother_in_law_birth_date | mertua_alamat_rt | in_laws_rt |
| mertua_alamat_rw | in_laws_rw | mertua_alamat_kab_kota | in_laws_city |
| mertua_alamat_kode_pos | in_laws_postal_code | mertua_hp | in_laws_mobile |
| sakit_mulai | long_sick_start_date | sakit_selesai | long_sick_end_date |
| sakit_keterangan | long_sick_notes | | |

**`karyawan_anak` → `employee_children`**: karyawan_profile_id → employee_profile_id · urutan → birth_order · nama → name · tanggal_lahir → birth_date · pendidikan → education_level · status_bekerja → employment_status · status_menikah → marital_status

**`pkwt_periods` → `employment_contracts`**: karyawan_profile_id → employee_profile_id · periode_ke → contract_sequence · tanggal_kontrak → start_date · tanggal_berakhir → end_date

**`pengajuan_update_datas` → `employee_change_requests`**: karyawan_id → employee_id · perubahan → changes · anak → children_changes · catatan → notes · catatan_verifikasi → verification_notes

**`jenis_karyawans` → `employee_categories`** dan **`lokasi_penerimaans` → `points_of_hire`**: kode → code · nama → name

**`work_locations`**: kode → code · skema_kerja → work_schedule_scheme · jam_kerja_harian → daily_normal_hours · order → sort_order

**`sub_locations`**: parent_jabatan_id → parent_position_id · order → sort_order

**`bods` → `directorates`**: order → sort_order
**`divisis` → `divisions`**: bod_id → directorate_id · order → sort_order
**`departemens` → `departments`**: divisi_id → division_id · order → sort_order
**`sections`**: departemen_id → department_id · order → sort_order

**`jabatans` → `positions`**: sub_group_id → job_level_id · bod_id → directorate_id · divisi_id → division_id · departemen_id → department_id · bidang → discipline · parent_jabatan_id → parent_position_id · name → title · order → sort_order

**`sub_groups` → `job_levels`**: nama → name · layer → level · sumber → naming_source · gelar → title_prefix · gelar_ho → title_prefix_head_office

**`master_options` → `lookup_values`**: kategori → category · nilai → value · urutan → sort_order · sistem → is_system

**`mpp_plans` → `workforce_plans`**: jenis → period_type · tahun → year · ke → period_number · mulai → start_date · selesai → end_date · dari_plan_id → source_plan_id

**`mpp_plan_items` → `workforce_plan_items`**: mpp_plan_id → workforce_plan_id · jabatan_id → position_id · plan → planned_headcount · promosi → promotions · rotasi → rotations · demosi → demotions · keluar → resignations · pensiun → retirements · sakit → long_sick_leaves · bulan_pindah → movement_month · bulan_penuhi → fulfilment_month · sumber → fulfilment_source · golongan_id → grade_id · keterangan → notes

**`mpp_revisis` → `workforce_plan_revisions`**: mpp_plan_id → workforce_plan_id · perubahan → changes · langsung → is_direct · catatan → notes · catatan_verifikasi → verification_notes

**`mpp_aturans` → `workforce_plan_settings`**: periode → period_type · golongan_sub_group → grade_by_job_level

### D.2 Recruitment

**`application_forms` → `job_postings`**: posisi → position_title · lokasi → location · jabatan_id → position_id · batas_lamaran → closing_date
**`form_fields` → `job_posting_fields`**: application_form_id → job_posting_id · kunci → field_key · order → sort_order
**`applicants`**: application_form_id → job_posting_id · catatan → notes
**`candidates`**: no_hp → phone · cv_nama → cv_filename · order → sort_order · ditolak_at → rejected_at · alasan_ditolak → rejection_reason
**`recruitment_stages`**: order → sort_order
**`status_histories` → `candidate_stage_histories`**: from → from_stage · to → to_stage

### D.3 Time & Attendance

**`shift_codes`**: kode → code · dws → daily_work_schedule · keterangan → description · jenis → day_type · jam_masuk → start_time · jam_keluar → end_time · istirahat_mulai → break_start · istirahat_selesai → break_end · order → sort_order
**`roster_patterns` → `shift_patterns`**: nama → name · langkah → steps
**`rosters` → `shift_schedules`**: karyawan_id → employee_id · tanggal → date
**`absensis` → `attendance_records`**: karyawan_id → employee_id · tanggal → date · sumber → source · trip_id → leave_travel_request_id
**`holidays` → `public_holidays`**: tanggal → date · nama → name

**`trips` → `leave_travel_requests`**
| Lama | Baru | Lama | Baru |
|---|---|---|---|
| karyawan_id | employee_id | jenis | request_type |
| kategori_cuti | leave_category | hari_cuti | leave_days |
| potong_tahunan | annual_leave_deducted | potong_besar | long_service_leave_deducted |
| cuti_besar_periode | long_service_period | lumpsum | field_leave_allowance |
| tanggal_awal | start_date | tanggal_akhir | end_date |
| kota_asal | origin_city | kota_tujuan | destination_city |
| keterangan | purpose | tiket_pesawat | needs_flight |
| bandara_asal | departure_airport | bandara_tujuan | arrival_airport |
| pesawat_pp | is_round_trip | pulang_tanggal | return_date |
| pulang_bandara_asal | return_departure_airport | pulang_bandara_tujuan | return_arrival_airport |
| tiket_kereta | needs_train | uang_muka | needs_cash_advance |
| uang_muka_nominal | cash_advance_amount | status_uang | cash_advance_status |
| tiket_pergi_dibeli_at | outbound_ticket_issued_at | tiket_pergi_dibeli_by | outbound_ticket_issued_by |
| tiket_pulang_dibeli_at | return_ticket_issued_at | tiket_pulang_dibeli_by | return_ticket_issued_by |
| sumber | source | catatan_verifikasi | verification_notes |

**`absen_logs` → `attendance_logs`**: mesin_absen_id → attendance_device_id · pin → device_pin · karyawan_id → employee_id · waktu → punched_at · status_code → punch_type · verify → verify_method · sumber → source · catatan → notes
**`mesin_absens` → `attendance_devices`**: nama → name · merek → brand · catatan → notes
**`mesin_absen_routes` → `attendance_device_routes`**: database → tenant_database
**`lemburs` → `overtime_records`**: karyawan_id → employee_id · tanggal → date · jenis_hari → day_type · mulai → clock_in_at · selesai → clock_out_at · menit_kerja → worked_minutes · menit_lembur → overtime_minutes · jam_dibayar → paid_hours · jam_terhitung → weighted_hours · sumber → source · catatan → notes · catatan_verifikasi → verification_notes
**`lembur_aturans` → `overtime_policies`**: pembulatan_menit → rounding_minutes · minimal_menit → minimum_minutes · hitung_sebelum_jadwal → count_before_shift · batas_harian_jam → daily_limit_hours · batas_mingguan_jam → weekly_limit_hours
**`sub_group_lembur` → `overtime_eligibilities`**: sub_group_id → job_level_id · tipe_lokasi → location_type · berhak → is_eligible

### D.4 Leave

**`cuti_aturans` → `leave_policies`**: jatah_tahunan → annual_entitlement_days · masa_aktif_tahun → annual_validity_years · besar_interval_tahun → long_service_interval_years · besar_hari → long_service_days · besar_gapok_diambil → long_service_taken_salary_multiplier · besar_gapok_diuangkan → long_service_cashout_salary_multiplier · besar_batas_bulan → long_service_decision_months
**`cuti_besars` → `long_service_leaves`**: karyawan_id → employee_id · periode_ke → period_number · keputusan → decision · sumber → source · catatan → notes
**`cuti_pengambilans` → `leave_transactions`**: karyawan_id → employee_id · jenis → leave_type · tahun → year · tanggal_awal → start_date · tanggal_akhir → end_date · keterangan_tanggal → date_notes · hari → days · sumber → source · catatan → notes
**`cuti_lapangans` → `field_leave_entitlements`**: hari_cuti → leave_days · hari_berangkat → outbound_travel_days · hari_kembali → return_travel_days
**`lumpsum_cutis` → `field_leave_allowances`**: lokasi_penerimaan_id → point_of_hire_id · klasifikasi → employee_class · nominal → amount

### D.5 Compensation & Benefits

**`golongans` → `grades`**: grup → grade_group · kode → code · urutan → sort_order
**`skala_upahs` → `salary_scales`**: golongan_id → grade_id · urutan → sort_order
**`skala_upah_kolom` → `salary_scale_periods`**: tahun → year · urutan → sort_order
**`skala_upah_nilai` → `salary_scale_amounts`**: skala_upah_id → salary_scale_id · kolom_id → salary_scale_period_id · nominal → amount
**`karyawan_gajis` → `employee_salaries`**: karyawan_id → employee_id · skala_upah_id → salary_scale_id · berlaku_mulai → effective_date · alasan → reason · catatan → notes
**`tunjangan_jabatans` → `job_level_allowances`**: sub_group_id → job_level_id · nominal → amount
**`plafon_pengobatans` → `medical_benefit_limits`**: layer → level · golongan_ids → grade_ids · sub_group_ids → job_level_ids · jabatan_ho → position_label_head_office · jabatan_site → position_label_site · lajang → single_amount · keluarga → family_amount · urutan → sort_order
**`plafon_kacamatas` → `optical_benefit_limits`**: (sama seperti di atas) · bingkai → frame_amount · lensa → lens_amount
**`deklarasis` → `benefit_claims`**: nomor → claim_number · karyawan_id → employee_id · tanggal → claim_date · tempat → provider_name · jumlah_kwitansi → receipt_count · bulan_buku → accounting_month · tahun_plafon → benefit_year · keterangan → notes · sumber → source · catatan_verifikasi → verification_notes
**`deklarasi_items` → `benefit_claim_items`**: deklarasi_id → benefit_claim_id · jenis → claim_type · penerima → beneficiary · karyawan_anak_id → employee_child_id · nominal → amount

### D.6 Umum & Administration

**`lampirans` → `attachments`**: lampirable_type → attachable_type · lampirable_id → attachable_id · nama → filename · ukuran → size_bytes · halaman → page_count
**`users`**: karyawan_id → employee_id
**`demo_accounts` → `trial_accounts`**: kode → code · nama → name · perusahaan → company_name · jabatan → job_title · no_hp → phone · jumlah_karyawan → company_size · kebutuhan → requirements · sumber_data → data_source · database → tenant_database · catatan → notes

---

## E. Nilai status & jenis (enum sistem)

Hanya nilai yang **ditentukan sistem**. Isi master data buatan pengguna tidak diubah.

| Tabel.kolom (baru) | Lama → Baru |
|---|---|
| employees.status | Aktif → active · Nonaktif → inactive · Keluar → terminated |
| users.role | Admin → super_admin · HR → admin · Karyawan → employee |
| *.status persetujuan (leave_travel_requests, benefit_claims, employee_change_requests, long_service_leaves, workforce_plan_revisions) | diajukan → pending · disetujui → approved · ditolak → rejected |
| overtime_records.status | menunggu → pending · disetujui → approved · ditolak → rejected |
| *.source (persetujuan) | hr → hr · portal → self_service |
| attendance_records.source | manual · roster → schedule · import · trip → leave_travel · mesin → device |
| attendance_logs.source | mesin → device · import · manual → correction |
| shift_codes.day_type | hadir → work · dinas → business_trip · off → off · cuti → leave · sakit → sick · izin → permission · mangkir → absent |
| leave_travel_requests.request_type | dinas → business_trip · cuti → leave |
| leave_travel_requests.leave_category | tahunan → annual · lapangan → field · besar → long_service · lainnya → other |
| leave_transactions.leave_type | tahunan → annual · besar → long_service |
| long_service_leaves.decision | diambil → taken · diuangkan → cashed_out |
| overtime_records.day_type | kerja → workday · istirahat → rest_day · libur → public_holiday |
| overtime_eligibilities.location_type, work_locations.type | Head Office → head_office · Site → site |
| work_locations.work_schedule_scheme | 5_hari → five_day · 6_hari → six_day · esdm → mining_roster |
| benefit_claim_items.claim_type | pengobatan → medical · perawatan → treatment · bingkai → optical_frame · lensa → optical_lens |
| benefit_claim_items.beneficiary | karyawan → employee · pasangan → spouse · anak → child |
| employee_salaries.reason | awal → initial · kenaikan → step_increase · promosi → promotion · penyesuaian → adjustment |
| attendance_devices.status | aktif → active · nonaktif → inactive · baru → pending |
| applicants.status | baru → new · diproses → in_process · ditolak → rejected |
| workforce_plans.period_type | kuartal → quarterly · semester → semiannual · tahunan → annual |
| trial_accounts.status | menunggu → pending · menyiapkan → provisioning · aktif → active · nonaktif → inactive · gagal → failed |
| trial_accounts.data_source | demo → sample · kosong → blank |
| attachments.attachable_type | App\Models\Deklarasi → benefit_claim · App\Models\Trip → leave_travel_request (dengan **morph map**) |
| lookup_values.category | kunci kategori diganti Inggris (agama → religion, status_perkawinan → marital_status, …); **nilai di dalamnya tetap** |

---

## F. Kode (gambaran)

| Lama | Baru |
|---|---|
| Model `Karyawan`, `KaryawanProfile`, `KaryawanAnak`, `PkwtPeriod` | `Employee`, `EmployeeProfile`, `EmployeeChild`, `EmploymentContract` |
| Model `Jabatan`, `SubGroup`, `Bod`, `Divisi`, `Departemen`, `JenisKaryawan`, `LokasiPenerimaan`, `MasterOption` | `Position`, `JobLevel`, `Directorate`, `Division`, `Department`, `EmployeeCategory`, `PointOfHire`, `LookupValue` |
| Model `MppPlan`, `MppPlanItem`, `MppRevisi`, `MppAturan` | `WorkforcePlan`, `WorkforcePlanItem`, `WorkforcePlanRevision`, `WorkforcePlanSetting` |
| Model `ApplicationForm`, `FormField`, `StatusHistory` | `JobPosting`, `JobPostingField`, `CandidateStageHistory` |
| Model `Roster`, `RosterPattern`, `Absensi`, `Holiday`, `Trip`, `AbsenLog`, `MesinAbsen`, `Lembur`, `LemburAturan` | `ShiftSchedule`, `ShiftPattern`, `AttendanceRecord`, `PublicHoliday`, `LeaveTravelRequest`, `AttendanceLog`, `AttendanceDevice`, `OvertimeRecord`, `OvertimePolicy` |
| Model `CutiAturan`, `CutiBesar`, `CutiPengambilan`, `CutiLapangan`, `LumpsumCuti` | `LeavePolicy`, `LongServiceLeave`, `LeaveTransaction`, `FieldLeaveEntitlement`, `FieldLeaveAllowance` |
| Model `Golongan`, `SkalaUpah*`, `KaryawanGaji`, `TunjanganJabatan`, `Plafon*`, `Deklarasi*` | `Grade`, `SalaryScale*`, `EmployeeSalary`, `JobLevelAllowance`, `*BenefitLimit`, `BenefitClaim*` |
| Model `Lampiran`, `DemoAccount`, `PengajuanUpdateData` | `Attachment`, `TrialAccount`, `EmployeeChangeRequest` |
| Folder controller `Manpower/`, `Attendance/`, `Comben/`, `Portal/` | `CoreHr/`, `Time/`, `Compensation/`, `SelfService/` |
| Folder logika `Support/Karyawan`, `Support/Org`, `Support/Mpp`, `Support/Attendance`, `Support/Kehadiran`, `Support/Cuti`, `Support/Comben` | `Support/Employees`, `Support/Organization`, `Support/WorkforcePlanning`, `Support/Time`, `Support/TimeClock`, `Support/Leave`, `Support/Compensation` |
| Halaman React `pages/Manpower`, `pages/Attendance`, `pages/Comben`, `pages/Portal`, `pages/Lowongan` | `pages/CoreHr`, `pages/Time`, `pages/Compensation`, `pages/SelfService`, `pages/Careers` |
| Props & field form di React | mengikuti nama kolom baru (`nrp` → `employee_number`, dst.) |

---

## G. Dampak & risiko

1. **Git dan produksi.** Repo ini sama dengan HC Puspita (produksi, branch `main`). Setelah refactor, branch `eloas` tidak bisa lagi di-merge dua arah dengan `main`. Perbaikan di HC Puspita harus diterapkan ulang secara manual ke Eolas. Praktisnya, Eolas menjadi produk terpisah.
2. **Banyak database.** Migrasi harus dijalankan di DB utama **dan setiap workspace demo** (`eolas:migrate-workspaces` sudah ada).
3. **Data yang ikut dimigrasi, bukan hanya struktur:**
   - kunci hak akses di `users.permissions`;
   - peran (`users.role`);
   - nilai status dan jenis (bagian E);
   - `attachments.attachable_type` dan `notifications.notifiable_type`;
   - URL di `notifications.data`;
   - kategori `lookup_values`.
4. **Foreign key.** Ada 84 foreign key. Urutannya: lepas FK → rename tabel/kolom → pasang ulang FK. Semuanya dalam satu migration per modul, dengan backup sebelum dijalankan.
5. **File Excel.** Template import/export (karyawan, jabatan, roster, trip, MPP) memakai judul kolom Indonesia. **Judul kolom Excel tetap Bahasa Indonesia** (untuk pengguna); hanya pemetaan internalnya yang berubah.
6. **Ukuran pekerjaan.** Sekitar 49 ribu baris di `app/`, `resources/`, dan `database/`. Dikerjakan per modul, tiap modul diuji (halaman, simpan, import/export, workspace) sebelum lanjut ke modul berikutnya.
