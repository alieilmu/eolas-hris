"""
Eolas terminology dictionary: the single source of truth of the HRIS-terminology refactor.

python3 database/refactor/dictionary.py  ->  database/refactor/terminology.json

Used by the codemods (PHP via token_get_all, TS via the TypeScript compiler API) and by the
migration 2026_10_26_000001_rename_to_hris_terminology (tables, columns, enum values, JSON keys).
One old word -> one new word everywhere, so code, data and URLs stay consistent.
"""
import json, os

# --- tables ---------------------------------------------------------------------------------
TABLES = {
    'karyawans': 'employees', 'karyawan_profiles': 'employee_profiles', 'karyawan_anak': 'employee_children',
    'pkwt_periods': 'employment_contracts', 'pengajuan_update_datas': 'employee_change_requests',
    'jenis_karyawans': 'employee_categories', 'lokasi_penerimaans': 'points_of_hire',
    'bods': 'directorates', 'divisis': 'divisions', 'departemens': 'departments', 'jabatans': 'positions',
    'sub_groups': 'job_levels', 'master_options': 'lookup_values',
    'mpp_plans': 'workforce_plans', 'mpp_plan_items': 'workforce_plan_items', 'mpp_revisis': 'workforce_plan_revisions',
    'mpp_aturans': 'workforce_plan_settings',
    'application_forms': 'job_postings', 'form_fields': 'job_posting_fields', 'status_histories': 'candidate_stage_histories',
    'roster_patterns': 'shift_patterns', 'rosters': 'shift_schedules', 'absensis': 'attendance_records',
    'holidays': 'public_holidays', 'trips': 'leave_travel_requests', 'absen_logs': 'attendance_logs',
    'mesin_absens': 'attendance_devices', 'mesin_absen_routes': 'attendance_device_routes',
    'lemburs': 'overtime_records', 'lembur_aturans': 'overtime_policies', 'sub_group_lembur': 'overtime_eligibilities',
    'cuti_aturans': 'leave_policies', 'cuti_besars': 'long_service_leaves', 'cuti_pengambilans': 'leave_transactions',
    'cuti_lapangans': 'field_leave_entitlements', 'lumpsum_cutis': 'field_leave_allowances',
    'golongans': 'grades', 'skala_upahs': 'salary_scales', 'skala_upah_kolom': 'salary_scale_periods',
    'skala_upah_nilai': 'salary_scale_amounts', 'karyawan_gajis': 'employee_salaries',
    'tunjangan_jabatans': 'job_level_allowances', 'plafon_pengobatans': 'medical_benefit_limits',
    'plafon_kacamatas': 'optical_benefit_limits', 'deklarasis': 'benefit_claims', 'deklarasi_items': 'benefit_claim_items',
    'lampirans': 'attachments', 'demo_accounts': 'trial_accounts',
}

# --- identifier / value tokens (snake, lower, camel) -------------------------------------------
# Applied to: column names, array keys, properties, props, form fields, exact string values,
# pieces of path-like strings (route names, dotted relations, URLs, validation rules).
T = {}
def add(s):
    for line in s.strip().splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        for pair in line.split(','):
            pair = pair.strip()
            if pair:
                a, b = [x.strip() for x in pair.split('=')]
                T[a] = b

add("""
# people & organisation
karyawan=employee, karyawans=employees, karyawan_id=employee_id, karyawan_ids=employee_ids, karyawanId=employeeId, karyawanIds=employeeIds
karyawan_count=employee_count, karyawans_count=employees_count, karyawanCount=employeeCount, karyawan_options=employee_options
karyawan_profile_id=employee_profile_id, karyawan_profiles=employee_profiles, karyawan_anak=employee_children, karyawan_anak_id=employee_child_id
canKaryawan=canEmployee, can_karyawan=can_employee, isKaryawan=isEmployee, dpm_karyawan_id=dpm_employee_id, pm_karyawan_id=pm_employee_id
tanpa_karyawan=without_employee, jumlah_karyawan=company_size, jenis_karyawan=employee_category, jenis_karyawans=employee_categories
nrp=employee_number, nrp_sap=sap_employee_number, nrp_codes=employee_number_codes, nrp_warning=employee_number_warning, nrp_konfirmasi=employee_number_confirmation
pin_absen=attendance_pin
jabatan=position, jabatans=positions, jabatan_id=position_id, jabatanId=positionId, jabatanName=positionName, jabatanNode=positionNode, jabatan_count=position_count, jabatans_count=positions_count
jabatan_ho=position_label_head_office, jabatan_site=position_label_site, jabatan_name=position_name, jabatannya=its_position, fixedJabatan=fixedPosition, optionalJabatan=optionalPosition
seededJabatan=seededPosition, seededJabatanName=seededPositionName, refreshes_jabatan=refreshes_position, parent_jabatan_id=parent_position_id, atasan_jabatan_id=superior_position_id
bidang=discipline, bidang_jabatan=position_discipline
sub_group=job_level, sub_groups=job_levels, sub_group_id=job_level_id, sub_group_ids=job_level_ids, subGroup=jobLevel, subGroups=jobLevels, subGroupIds=jobLevelIds
dpm_sub_group=dpm_job_level, pm_sub_group=pm_job_level, unit_sub_groups=unit_job_levels, sub_group_lembur=overtime_eligibilities
bod=directorate, bods=directorates, bod_id=directorate_id, bod_name=directorate_name
divisi=division, divisis=divisions, divisi_id=division_id, divisi_name=division_name, divisi_count=division_count, divisis_count=divisions_count, divisiOf=divisionOf
departemen=department, departemens=departments, departemen_id=department_id, departemen_name=department_name, departemen_count=department_count, departemens_count=departments_count
departemen_order=department_order, departemenOf=departmentOf, placementDepartemen=placementDepartment
lokasi=location, lokasi_kerja=work_location, lokasi_count=location_count, lokasi_order=location_order, per_lokasi=per_location
lokasi_penerimaan_id=point_of_hire_id, lokasi_penerimaans=points_of_hire, lokasi_penerimaans_count=points_of_hire_count, lokasiPenerimaans=pointsOfHire
tempat_penerimaan=point_of_hire, sub_lokasi=sub_location, tipe_lokasi=location_type
organisasi=organization, penempatan=placement, penempatan_options=placement_options, atasan=superior, atasan_options=superior_options, atasanLayerError=superiorLayerError
posisi=position_title, posisi_options=position_title_options, posisiOptions=positionTitleOptions, per_posisi=per_position_title
gelar=title_prefix, gelar_ho=title_prefix_head_office, gelarAt=titlePrefixAt
grup=grade_group, grups=grade_groups
golongan=grade, golongans=grades, golongan_id=grade_id, golonganId=gradeId, golongan_ids=grade_ids, golonganIds=gradeIds, golonganData=gradeData
golongan_default=grade_default, golongan_manual=grade_manual, golongan_sub_group=grade_by_job_level
klasifikasi=employee_class, klasifikasi_teknis=technical_class
kode=code, nama=name, nilai=value, nilai_banyak=values_bulk, saveNilai=saveValue, urutan=sort_order, urut=sequence, max_urut=max_sequence, no_urut=sequence_no
kategori=category, sistem=is_system, layer=layer, poh=point_of_hire

# employee profile
tempat_lahir=birth_place, tanggal_lahir=birth_date, has_tanggal_lahir=has_birth_date, tanggal_masuk=hire_date, tanggalMasuk=hireDate
status_pegawai=employment_status, status_pegawai_tetap=is_permanent, status_perkawinan=marital_status, tanggal_perkawinan=marriage_date
nomor_sk=appointment_letter_number, tanggal_sk=appointment_letter_date, sk=appointment_letter
ukuran=size, ukuran_baju=shirt_size, ukuran_celana=pants_size, ukuran_sepatu=shoe_size, ukuran_apd=ppe_size, seragam=uniform
nik=national_id_number, nik_provinsi=national_id_province, nik_kabupaten_kota=national_id_city
npwp=tax_id_number, npwp_tanggal_daftar=tax_id_registered_date
bpjs_tk=bpjs_employment_number, bpjs_tk_tanggal_daftar=bpjs_employment_registered_date, bpjs_kesehatan=bpjs_health_number
no_rekening=bank_account_number, nama_bank=bank_name, nama_pemilik_rekening=bank_account_holder, rekening=bank_account
agama=religion, jenis_kelamin=gender, suku=ethnicity, suku_bangsa=ethnicity, pendidikan=education_level, pendidikan_terakhir=education_level
pendidikan_anak=child_education_level, jurusan=education_major, asal_sekolah=education_institution, tahun_lulus=graduation_year
alamat_tinggal=residential_address, alamat_tinggal_rt=residential_rt, alamat_tinggal_rw=residential_rw, alamat_tinggal_kab_kota=residential_city
alamat_tinggal_provinsi=residential_province, alamat_tinggal_kode_pos=residential_postal_code
alamat_surat=mailing_address, alamat_surat_rt=mailing_rt, alamat_surat_rw=mailing_rw, alamat_surat_kab_kota=mailing_city
alamat_surat_kode_pos=mailing_postal_code, alamat_surat_telepon=mailing_phone, alamat_surat_hp=mailing_mobile
emergency_telepon=emergency_contact_phone, emergency_nama=emergency_contact_name, emergency_hubungan=emergency_contact_relationship
no_hp=phone, no_whatsapp=whatsapp_number, email_pribadi=personal_email
nama_pasangan=spouse_name, tanggal_lahir_pasangan=spouse_birth_date, pekerjaan_pasangan=spouse_occupation, pasangan=spouse
nama_ayah=father_name, tanggal_lahir_ayah=father_birth_date, nama_ibu=mother_name, tanggal_lahir_ibu=mother_birth_date
ortu_alamat_rt=parents_rt, ortu_alamat_rw=parents_rw, ortu_alamat_kab_kota=parents_city, ortu_alamat_kode_pos=parents_postal_code, ortu_telepon=parents_phone, ortu_hp=parents_mobile
nama_ayah_mertua=father_in_law_name, tanggal_lahir_ayah_mertua=father_in_law_birth_date, nama_ibu_mertua=mother_in_law_name, tanggal_lahir_ibu_mertua=mother_in_law_birth_date
mertua=in_laws, mertua_alamat_rt=in_laws_rt, mertua_alamat_rw=in_laws_rw, mertua_alamat_kab_kota=in_laws_city, mertua_alamat_kode_pos=in_laws_postal_code, mertua_hp=in_laws_mobile
sakit_mulai=long_sick_start_date, sakit_selesai=long_sick_end_date, sakit_keterangan=long_sick_notes
identitas=identity, pribadi=personal, kontak=contact, kelahiran=birth, lahir=birth, nikah=married, menikah=married, belum_menikah=unmarried
hubungan=relationship, profil=profile, usia=age, usia_baru=new_age, masa_kerja=years_of_service, masaKerjaPada=yearsOfServiceOn
berikutnya_masa_kerja=next_years_of_service, keluarga=family, lajang=single
anak=children, anak_id=child_id, anakId=childId, anak_count=children_count, jumlah_anak=children_count
anak_1_nama=child_1_name, anak_1_pendidikan=child_1_education_level, anak_1_tanggal_lahir=child_1_birth_date, anak_=child_
status_bekerja=employment_status, status_bekerja_anak=child_employment_status, status_menikah=marital_status, status_menikah_anak=child_marital_status, status_nikah=marital_status
status_asal=origin_status, lokal=local, kiriman=transferred
tanggal_keluar=termination_date, alasan_keluar=termination_reason, keterangan_keluar=termination_notes, keluar=termination, keluar_tahun_ini=terminated_this_year
pkwt=contract, pkwt_=contract_, pkwtPeriods=contracts, pkwt_periods=employment_contracts, latestPkwt=latestContract, readPkwt=readContract, max_pkwt=max_contract
pkwt_1_berakhir=contract_1_end_date, pkwt_1_kontrak=contract_1_start_date, pkwt_30_hari=contracts_30_days, pkwt_count=contract_count
pkwt_akan_berakhir=contracts_ending_soon, pkwt_berakhir=contracts_ended, pkwt_expiring_soon=contract_expiring_soon
pkwt_terakhir_berakhir=last_contract_end_date, pkwt_terakhir_hampir_habis=last_contract_ending_soon, pkwt_terakhir_kontrak=last_contract_start_date
pkwt_terakhir_periode=last_contract_sequence, pkwt_terakhir_sisa_hari=last_contract_days_left, status_akhir_pkwt=contract_end_status
periode_ke=sequence_no, tanggal_kontrak=start_date, tanggal_berakhir=end_date, kontrak=contract, pengangkatan=appointment
pensiun=retirements, pensiun_age=retirement_age, pensiun_auto=retirements_auto, pensiun_list=retirements_list, pensiun_manual=retirements_manual, tanggal_pensiun=retirement_date

# workforce planning (MPP)
mpp=workforce_planning, mpp_plan_id=workforce_plan_id, revisi=revision, revisis=revisions, revisi_menunggu=revisions_pending
promosi=promotions, rotasi=rotations, demosi=demotions, sakit=sick, sakit_auto=sick_auto, sakit_list=sick_list, sakit_manual=sick_manual
bulan_pindah=movement_month, bulan_penuhi=fulfilment_month, penuhi=fulfil, pindah=move, eksternal=external
kuartal=quarterly, tahunan=annual, semester=semiannual, periode=period, periodes=periods, periode_label=period_label, periode_options=period_options
dari_plan_id=source_plan_id, langsung=is_direct, perubahan=changes, bulan_periode=period_months, lowongan_buka=open_postings
mutasi=transfer, onMutasi=onTransfer, copot=remove, onCopot=onRemove

# recruitment
pelamar=applicants, pelamar_30_hari=applicants_30_days, pelamar_baru=new_applicants, kandidat=candidate, lamaran=application
posisi=position_title, batas_lamaran=closing_date, kunci=field_key, kunci_types=field_key_types, jawaban=answers, judul=title, isi=body
tahap=stage, tahapan=stages, tahap_terakhir=last_stage, hari_di_tahap=days_in_stage, alasan_ditolak=rejection_reason, ditolak_at=rejected_at
cv_nama=cv_filename, lowongan=careers, lowongan_url=careers_url, rekrutmen=recruitment, laporan=report
# module prefixes (URLs, route names, folders)
manpower=core_hr, attendance=time, comben=compensation, portal=self_service, akun=account, master_data=settings

# time & attendance
absen=attendance, absen_logs=attendance_logs, absensi=attendance, absensis=attendance_records, absensis_count=attendance_records_count, absensiCode=attendanceCode, absensi_sumber=attendance_source, isAbsensi=isAttendance
roster=schedule, rosterAndAbsensi=scheduleAndAttendance, roster_jenis=schedule_type, tanpa_roster=without_schedule
kehadiran=time_clock, kepatuhan=compliance, hadir=work, tidak_hadir=no_show, mangkir=absent, izin=permission, libur=public_holiday, nasional=national
lengkap=complete, tanpa_ci=missing_clock_in, tanpa_co=missing_clock_out, tidak_sesuai=mismatch, tidak_cocok=mismatch, terlambat=late, pulang_cepat=early_leave
jadwal=schedule, terjadwal=scheduled, hari_jadwal=scheduled_days, jam=hours, jam_masuk=start_time, jam_keluar=end_time, jam_kerja=work_hours, jam_normal=normal_hours
jam_kerja_harian=daily_normal_hours, skema=scheme, skema_kerja=work_schedule_scheme, skema_options=scheme_options, waktu_kerja=work_schedule
istirahat=rest_day, istirahat_mulai=break_start, istirahat_selesai=break_end, istirahatMulai=breakStart, istirahatSelesai=breakEnd, istirahatDalam=breakWithin
mesin=device, mesins=devices, mesin_absen_id=attendance_device_id, mesin_absen_routes=attendance_device_routes, mesin_absens=attendance_devices
mesin_baru=new_devices, mesin_options=device_options, mesinData=deviceData, merek=brand, waktu=punched_at, jumlah_log=log_count, log_hari_ini=logs_today, log_terakhir=last_log
lembur=overtime, lemburs=overtime_records, lembur_aturan=overtime_policy, lembur_status=overtime_status, lembur_summary=overtime_summary, hak_lembur=overtime_eligibility, berhak_lembur=is_overtime_eligible
jam_lembur=overtime_hours, jam_dibayar=paid_hours, jam_terhitung=weighted_hours, menit_kerja=worked_minutes, menitKerja=workedMinutes, menit_lembur=overtime_minutes, jenis_hari=day_type
pembulatan_menit=rounding_minutes, minimal_menit=minimum_minutes, hitung_sebelum_jadwal=count_before_shift, batas_harian_jam=daily_limit_hours, batas_mingguan_jam=weekly_limit_hours
berhak=is_eligible, hak=entitlement, terhitung=weighted, koreksi=correction
dinas=business_trip, dinas_hari_ini=business_trips_today, cuti_hari_ini=leaves_today, perjalanan=travel, statusPerjalanan=travelStatus, akan_datang=upcoming
trip=leave_travel, kota=city, kota_asal=origin_city, kota_tujuan=destination_city, tujuan=destination, asal=origin
tiket=ticket, adaTiket=hasTicket, tiket_pesawat=needs_flight, tiket_kereta=needs_train, tiket_belum=tickets_pending, tiket_dibeli=tickets_issued
bandara=airport, bandara_asal=departure_airport, bandara_tujuan=arrival_airport, pesawat_pp=is_round_trip
pulang=return, pulang_tanggal=return_date, pulang_bandara_asal=return_departure_airport, pulang_bandara_tujuan=return_arrival_airport, pulang_dibeli=return_issued
pergi=outbound, pergi_dibeli=outbound_issued, berangkat=outbound, kembali=return
tiket_pergi_dibeli_at=outbound_ticket_issued_at, tiket_pergi_dibeli_by=outbound_ticket_issued_by, tiket_pulang_dibeli_at=return_ticket_issued_at, tiket_pulang_dibeli_by=return_ticket_issued_by
pembeliTiketPergi=outboundTicketIssuer, pembeliTiketPulang=returnTicketIssuer, pembeli=issuer
uang=cash, uang_muka=needs_cash_advance, uang_muka_nominal=cash_advance_amount, status_uang=cash_advance_status, cair=disbursed
onsite=onsite, kerja=workday

# leave
cuti=leave, cutiBesar=longServiceLeave, cuti_besar=long_service_leave, cuti_besars=long_service_leaves, cutiPengambilans=leaveTransactions, cuti_pengambilans=leave_transactions
cuti_aturan=leave_policy, cuti_lapangan=field_leave, cuti_besar_periode=long_service_period, kategori_cuti=leave_category, hari_cuti=leave_days
besar=long_service, lapangan=field, lapanganData=fieldData, lainnya=other, potong=deduct, potong_tahunan=annual_leave_deducted, potong_besar=long_service_leave_deducted, potong_dari=deducted_from
lumpsum=field_leave_allowance, jatah=entitlement, jatah_tahunan=annual_entitlement_days, jatah_berikutnya=next_entitlement, jatah_tahun_ini=entitlement_this_year
masa_aktif_tahun=annual_validity_years, besar_interval_tahun=long_service_interval_years, besar_hari=long_service_days
besar_gapok_diambil=long_service_taken_salary_multiplier, besar_gapok_diuangkan=long_service_cashout_salary_multiplier, besar_batas_bulan=long_service_decision_months
hari_berangkat=outbound_travel_days, hari_kembali=return_travel_days, keputusan=decision, keputusan_status=decision_status
diambil=taken, diambil_tahun_ini=taken_this_year, diambil_total=taken_total, diuangkan=cashed_out, otomatis_diuangkan=auto_cashed_out
pengambilan=transaction, jumlah_pengambilan=transaction_count, keterangan_tanggal=date_notes, saldo=balance, sisa=remaining, sisa_hari=days_left
hutang=debt, alokasi=allocation, hangus_akhir_tahun=expires_end_of_year, tanggal_hak=entitlement_date, hak_berikutnya=next_entitlement_date
belum_dipilih=not_chosen, menunggu_besar=long_service_pending, rata_hari=average_days, lamaHari=durationDays

# compensation & benefits
gaji=salary, gajis=salaries, gaji_pokok=base_salary, gapok=base_salary, pokok=base, total_pokok=total_base_salary
upah=wage, upah_tetap=fixed_pay, total_upah_tetap=total_fixed_pay, tunjangan=allowance, tunjangan_jabatan=position_allowance, total_tunjangan=total_allowance
skala=scale, skala_upah_id=salary_scale_id, skalaUpah=salaryScale, skalaUpahs=salaryScales, skala_upahs=salary_scales, skala_upahs_count=salary_scales_count
skala_upah_kolom=salary_scale_periods, skala_upah_nilai=salary_scale_amounts, skalaData=scaleData, skala_count=scale_count, kolom=period_column, kolom_id=salary_scale_period_id, kolomForm=periodColumnForm
berlaku_mulai=effective_date, berlaku_sampai=effective_until, kenaikan=step_increase, penyesuaian=adjustment, awal=initial
plafon=benefit_limit, plafonData=benefitLimitData, plafon_count=benefit_limit_count, plafon_bingkai=frame_limit, plafon_lensa=lens_limit
plafon_kacamata=optical_benefit_limit, plafon_kacamatas=optical_benefit_limits, plafon_pengobatan=medical_benefit_limit, plafon_pengobatans=medical_benefit_limits, plafonPengobatan=medicalBenefitLimit
pengobatan=medical, pengobatanRule=medicalRule, kacamata=optical, kacamataRule=opticalRule, perawatan=treatment, bingkai=frame, lensa=lens
bingkai_menunggu=frame_pending, bingkai_terakhir=last_frame, lensa_menunggu=lens_pending, sisa_bingkai=frame_remaining, sisa_lensa=lens_remaining
deklarasi=claim, deklarasis=claims, deklarasi_id=benefit_claim_id, deklarasi_items=benefit_claim_items
penerima=beneficiary, nomor=claim_number, tempat=provider_name, tempat_options=provider_options, tempatOptions=providerOptions, jumlah_kwitansi=receipt_count, kwitansi=receipt, nota=receipt
bulan_buku=accounting_month, tahun_plafon=benefit_year, terpakai=used, tersedia=available, persen=percent, biaya=cost
nominal=amount, ptkp=ptkp, status_ptkp=ptkp_status

# workflow, common
status=status, sumber=source, sumber_data=data_source, sumber_label=source_label, sumber_options=source_options, sumberOptions=sourceOptions
catatan=notes, catatan_verifikasi=verification_notes, keterangan=description, alasan=reason, alasan_options=reason_options, alasanOptions=reasonOptions
diajukan=pending, disetujui=approved, ditolak=rejected, menunggu=pending, diproses=in_process, dibatalkan=cancelled, diputuskan=decided, diterima=accepted
baru=new, baru_count=new_count, baru_90_hari=new_90_days, aktif=active, nonaktif=inactive, gagal=failed, menyiapkan=provisioning, kosong=blank
setuju=agree, setujui=approve, tolak=reject, penyetuju=approver, pembuat=creator, dibaca=read, verifikasi=verification
hapus=delete, hapus_lampiran=delete_attachments, simpan=save, tambah=add, ubah=edit, lihat=view, perbarui=update, perbaikan=fix, pratinjau=preview, aksi=action, akses=access
pengajuan=submission, pengajuan_data=data_change_request, pengajuan_update=data_change, pengajuan_update_datas=employee_change_requests, notifikasi=notifications
lampiran=attachment, lampirans=attachments, lampirable=attachable, lampiran_limits=attachment_limits, lampiranTitle=attachmentTitle, halaman=page_count
perusahaan=company_name, kebutuhan=requirements
tanggal=date, tanggal_=date_, tanggal_awal=start_date, tanggal_akhir=end_date, mulai=start_date, selesai=end_date, sampai=until, dari=from
tahun=year, tahuns=years, bulan=month, bulanan=monthly, bulanCount=monthCount, bulan_label=month_label, current_bulan=current_month, per_bulan=per_month, hari=days, harian=daily, mingguan=weekly
hari_ini=today, hari_ke=day_number, ke=period_number, thn=yr, sekarang=now, terakhir=last, terbaru=latest, lama=old, belum=not_yet
belum_diisi=not_filled, belum_ditempatkan=unplaced, jenis=type, jenisOf=typeOf, initialJenis=initialType, per_jenis=per_type, jenis_options=type_options
masalah=issues, ringkasan=summary, rincian=breakdown, riwayat=history, hasil=result, ulang_tahun=birthdays, ulangTahun=birthdays, ulang_tahun_bulan=birthday_month
total_aktif=total_active, orang=people, semua=all, lebih=over, kurang=short, naik=up, turun=down, kiri=left, kanan=right, arah=direction, bentuk=shape
ada=present, akan=upcoming, aju=submit, ini=this, sini=here, dulu=first, ya=yes, sudah=done, kecuali=except, mencapai=reached, cocok=match, sesuai=matching, salah=wrong
otomatis=automatic, pesan=message, angka=number, baris=rows, dilewati=skipped, dipilih=selected, membatalkan=cancel, memilih=choose, batal=cancel, dibuka=opened, dibayar=paid
dibeli=issued, duplikat=duplicate, cara=method, realistis=realistic, singkat=short, merah=red, biru=blue, lunas=paid_off, terkirim=sent, konfirmasi=confirmation
hampir=almost, habis=expired, kali=times, batas=limit, aturan=policy, proses=process, jab=pos, kar=emp, anggota=members
# split many-to-one words that can meet in one object
jadwal=shift_window, kembali=back, berangkat=departure, kontrak=contract_start, hak=eligibility, kelahiran=birth_info, nikah=is_married
singkat=brief, nota=receipt_doc, membatalkan=cancelling, akan=soon, jumlah_anak=number_of_children, gapok=base_pay, suku=ethnic_group
tidak_cocok=not_matching, tempat_penerimaan=hire_location
lampirable_type=attachable_type, lampirable_id=attachable_id, langkah=steps, dws=daily_work_schedule
application_form_id=job_posting_id, trip_id=leave_travel_request_id
anakRow=childRow, bodOf=directorateOf, bulanPeriode=periodMonths, chooseBesar=chooseLongService, currentTahun=currentYear, cutiInfo=leaveInfo, dataLengkap=fullData, decideBesar=decideLongService, destroyGolongan=destroyGrade, destroyKaryawan=destroyEmployee, destroyKolom=destroyPeriodColumn, destroyLapangan=destroyFieldLeave, destroyMesin=destroyDevice, destroyPengambilan=destroyTransaction, destroyPlafon=destroyBenefitLimit, destroySkala=destroyScale, emptyKacamata=emptyOptical, forJenis=forType, golonganDefaults=gradeDefaults, hitung=calculate, jabatanData=positionData, jenisMap=categoryMap, jumlahAnak=childrenCount, kategoriTer=terCategory, lokasiMap=pointOfHireMap, masaKerja=yearsOfService, maxAnak=maxChildren, maxUrutPerPrefix=maxSequencePerPrefix, mppHasil=workforcePlanResult, mppRevisi=workforcePlanRevision, newKode=newCode, nextNomor=nextClaimNumber, nrpCodes=employeeNumberCodes, onlyInDepartemen=onlyInDepartment, parseKode=parseCode, penempatanOptions=placementOptions, periodeOf=periodOf, periodeOptions=periodOptions, perubahanView=changesView, plafonRow=benefitLimitRow, plafonText=benefitLimitText, potongDari=deductedFrom, resetKaryawan=resetEmployee, resetKaryawanMany=resetEmployeesBulk, revisiView=revisionView, statusAkhirPkwt=contractEndStatus, statusAsal=originStatus, statusUang=cashAdvanceStatus, statusUangAwal=initialCashAdvanceStatus, storeDeklarasi=storeClaim, storeGolongan=storeGrade, storeKaryawan=storeEmployee, storeKolom=storePeriodColumn, storeLapangan=storeFieldLeave, storeMesin=storeDevice, storePengambilan=storeTransaction, storePlafon=storeBenefitLimit, storeSkala=storeScale, subLokasi=subLocation, tiketBelum=ticketsPending, toggleKaryawan=toggleEmployee, tunjanganJabatan=positionAllowance, upahTetap=fixedPay, updateAturan=updatePolicy, updateGolongan=updateGrade, updateHakLembur=updateOvertimeEligibility, updateLapangan=updateFieldLeave, updateLemburAturan=updateOvertimePolicy, updateLumpsum=updateFieldLeaveAllowance, updateMesin=updateDevice, updatePlafon=updateBenefitLimit, updateSkala=updateScale, updateTunjangan=updateAllowance, updateWaktuKerja=updateWorkSchedule, verifyBesar=verifyLongService, verifyLembur=verifyOvertime
portofolio=portfolio
order=sort_order
opsi=options, geser=move, banyak=bulk, salin=duplicate, perpanjang=extend, baca=read
berakhir=ends, berikutnya=next, berjalan=ongoing, jumlah=quantity, kesehatan=health, masuk=hired, pilih=pick, provinsi=province, tetap=permanent
""")

# Hyphenated whole strings (menu keys, notification ref kinds, ...): matched before splitting.
T.update({
    'trip-leave': 'leave-travel', 'pengajuan-dinas': 'travel-requests', 'master-personalia': 'core-hr-settings',
    'org-structure': 'org-chart', 'mpp-approve': 'workforce-planning-approval', 'update-data': 'data-change-requests',
    'lembur-approve': 'overtime-approval', 'monitoring-plafon': 'benefit-balances', 'master-comben': 'compensation-settings',
    'master-attendance': 'time-settings', 'data-gaji': 'employee-compensation', 'cuti-besar': 'long-service-leave',
    'tanpa-perubahan': 'unchanged', 'cuti-info': 'leave-info', 'data-lengkap': 'full-data', 'akun-demo': 'trial-accounts',
    'akun-karyawan': 'employee-accounts', 'reset-masal': 'bulk-reset', 'white-label': 'branding',
    'pola-roster': 'shift-patterns', 'kode-shift': 'shift-codes', 'hari-libur': 'public-holidays', 'waktu-kerja': 'work-schedule',
    'lembur-aturan': 'overtime-policy', 'hak-lembur': 'overtime-eligibility', 'skala-upah': 'salary-scale', 'skala-kolom': 'salary-scale-periods',
    'cuti-aturan': 'leave-policy', 'cuti-lapangan': 'field-leave', 'lumpsum-cuti': 'field-leave-allowances', 'tunjangan-jabatan': 'position-allowances',
    'jenis-karyawan': 'employee-categories', 'lokasi-penerimaan': 'points-of-hire', 'work-location': 'work-locations', 'sub-lokasi': 'sub-locations',
    'sub-group': 'job-levels', 'buka-tutup': 'toggle', 'pengajuan-data': 'change-requests', 'status-uang': 'cash-status', 'cuti-dinas': 'leave-travel',
    'template-kelompok': 'group-template', 'baca-semua': 'read-all', 'mesin-absen': 'attendance-devices', 'master-data': 'settings',
})

# URL / folder segments of the modules (path-like strings, page names, filesystem).
SEGMENTS_UNUSED = {
    'manpower': 'core-hr', 'attendance': 'time', 'comben': 'compensation', 'portal': 'self-service', 'lowongan': 'careers', 'akun': 'account',
    'karyawan': 'employees', 'deklarasi': 'claims', 'monitoring': 'benefit-balances', 'kehadiran': 'clock', 'pelamar': 'applicants',
    'form': 'job-postings', 'laporan': 'reports', 'tracking': 'pipeline', 'master-data': 'settings', 'demo': 'demo',
}

# --- classes, namespaces, page and component names (PascalCase) -----------------------------
C = {}
def addc(s):
    for line in s.strip().splitlines():
        for pair in line.split(','):
            pair = pair.strip()
            if pair and not pair.startswith('#'):
                a, b = [x.strip() for x in pair.split('=')]
                C[a] = b
addc("""
Karyawan=Employee, KaryawanProfile=EmployeeProfile, KaryawanAnak=EmployeeChild, PkwtPeriod=EmploymentContract, KaryawanGaji=EmployeeSalary
PengajuanUpdateData=EmployeeChangeRequest, JenisKaryawan=EmployeeCategory, LokasiPenerimaan=PointOfHire, Bod=Directorate, Divisi=Division
Departemen=Department, Jabatan=Position, SubGroup=JobLevel, MasterOption=LookupValue, MasterOptions=LookupValues
MppPlan=WorkforcePlan, MppPlanItem=WorkforcePlanItem, MppRevisi=WorkforcePlanRevision, MppAturan=WorkforcePlanSetting, Mpp=WorkforcePlanning, MppExcel=WorkforcePlanExcel
ApplicationForm=JobPosting, FormField=JobPostingField, StatusHistory=CandidateStageHistory
Roster=ShiftSchedule, RosterPattern=ShiftPattern, Absensi=AttendanceRecord, Holiday=PublicHoliday, Trip=LeaveTravelRequest, AbsenLog=AttendanceLog
MesinAbsen=AttendanceDevice, Lembur=OvertimeRecord, LemburAturan=OvertimePolicy, LemburHitung=OvertimeCalculator, MesinRoute=DeviceRoute
CutiAturan=LeavePolicy, CutiBesar=LongServiceLeave, CutiPengambilan=LeaveTransaction, CutiLapangan=FieldLeaveEntitlement, LumpsumCuti=FieldLeaveAllowance
Golongan=Grade, SkalaUpah=SalaryScale, SkalaUpahKolom=SalaryScalePeriod, SkalaUpahNilai=SalaryScaleAmount, TunjanganJabatan=JobLevelAllowance
PlafonPengobatan=MedicalBenefitLimit, PlafonKacamata=OpticalBenefitLimit, Deklarasi=BenefitClaim, DeklarasiItem=BenefitClaimItem, Lampiran=Attachment, DemoAccount=TrialAccount
# controllers
KaryawanController=EmployeeController, KaryawanAnakController=EmployeeChildController, KaryawanExcelController=EmployeeExcelController, KaryawanKeluarController=EmployeeTerminationController
KaryawanPenempatanController=EmployeePlacementController, KaryawanPkwtController=EmployeeContractController, KaryawanProfileController=EmployeeProfileController
BodController=DirectorateController, DepartemenController=DepartmentController, DivisiController=DivisionController, JabatanController=PositionController
JabatanExcelController=PositionExcelController, JenisKaryawanController=EmployeeCategoryController, LokasiPenerimaanController=PointOfHireController
MasterDataController=SettingsController, MasterOptionController=LookupValueController, SubGroupController=JobLevelController
MppController=WorkforcePlanController, OrgPlanController=OrgPlanController, OrgStructureController=OrgChartController, PengajuanUpdateDataController=ChangeRequestController
AttendanceMasterController=TimeSettingsController, SheetController=SheetController, TripController=LeaveTravelController, KehadiranController=TimeClockController
CombenMasterController=CompensationSettingsController, CutiController=LeaveBalanceController, CutiMasterController=LeaveSettingsController
DeklarasiController=BenefitClaimController, MonitoringController=BenefitBalanceController, GajiController=SalaryController
NotifikasiController=NotificationController, LampiranController=AttachmentController, LowonganController=CareersController
PortalController=SelfServiceController, DataController=ProfileController, ApplicantController=ApplicantController, FormController=JobPostingController
LaporanController=ReportController, TrackingController=PipelineController, DemoAccountController=TrialAccountController, DemoRequestController=TrialRequestController
# support
KaryawanImporter=EmployeeImporter, KaryawanRow=EmployeeRow, KaryawanFormOptions=EmployeeFormOptions, Nrp=EmployeeNumber, Entitlement=BenefitEntitlement
DeklarasiCheck=BenefitClaimCheck, BukuBulan=AccountingMonth, CombenDefaults=CompensationDefaults, Cuti=LeaveLedger, CutiExcel=LeaveExcel, Lumpsum=FieldLeaveAllowanceRate
JabatanComposer=PositionComposer, Penempatan=Placement, PenempatanRules=PlacementRules, Lamaran=Application, Pengajuan=RequestNotifier
PengajuanMasuk=RequestReceived, PengajuanChannel=RequestChannel, Attachments=Attachments, KehadiranDefaults=TimeClockDefaults, Jadwal=ShiftWindow
AbsenFile=AttendanceFile, Proses=AttendanceProcessor, Resume=AttendanceSummary, Ingest=AttendanceIngest, Adms=Adms, Gaji=Salary, Ptkp=Ptkp
TripImport=LeaveTravelImport, TripWriter=LeaveTravelWriter
# seeders
KaryawanSeeder=EmployeeSeeder, DemoKaryawanSeeder=DemoEmployeeSeeder, CombenSeeder=CompensationSeeder, EolasGajiDemoSeeder=EolasSalaryDemoSeeder
EolasKehadiranDemoSeeder=EolasTimeClockDemoSeeder, EolasKosongSeeder=EolasBlankSeeder
# tests
KaryawanActionsTest=EmployeeActionsTest, KaryawanExcelTest=EmployeeExcelTest, KaryawanViewsTest=EmployeeViewsTest, CutiTest=LeaveTest, MppTest=WorkforcePlanTest
LampiranTest=AttachmentTest, NotifikasiTest=NotificationTest, PengajuanUpdateDataTest=ChangeRequestTest, CombenTest=CompensationTest, PenempatanTest=PlacementTest
TripLumpsumTest=FieldLeaveAllowanceTest
# react components
NotifikasiBell=NotificationBell, BandaraPicker=AirportPicker, TripForm=LeaveTravelForm, TripImportDialog=LeaveTravelImportDialog, JenisBadge=TypeBadge
DeklarasiForm=BenefitClaimForm, LampiranButton=AttachmentButton, LampiranPicker=AttachmentPicker, LampiranViewer=AttachmentViewer, CutiMaster=LeaveSettings
KaryawanImportDialog=EmployeeImportDialog, KaryawanHeader=EmployeeHeader, KaryawanTab=EmployeeTab, PenempatanDialog=PlacementDialog, PenempatanFields=PlacementFields
MutasiDialog=TransferDialog, PkwtEditor=ContractEditor, AnakEditor=ChildrenEditor, PlafonEditor=BenefitLimitEditor, SkalaEditor=ScaleEditor, NrpGuide=EmployeeNumberGuide
DataGaji=Salaries, DataLengkap=FullData, PengajuanData=ChangeRequests, TripLeave=LeaveTravel, Kehadiran=TimeClock, Pelamar=Applicants, Laporan=Reports, Tracking=Pipeline
Forms=JobPostings, FormEditor=JobPostingEditor,
Bandara=Airport, UpdateKaryawanProfileRequest=UpdateEmployeeProfileRequest, DemoLumpsumSeeder=DemoFieldLeaveAllowanceSeeder, DemoMppSeeder=DemoWorkforcePlanSeeder
AddKaryawanDialog=AddEmployeeDialog, ExitKaryawanDialog=TerminateEmployeeDialog, ExtendPkwtDialog=ExtendContractDialog, JabatanImport=PositionImport
JabatanImportPreview=PositionImportPreview, Jawaban=Answers, LamaranField=ApplicationField, MppMode=WorkforcePlanningMode, MppParts=WorkforcePlanningParts
MppSettings=WorkforcePlanningSettings, MppTable=WorkforcePlanningTable, OrganisasiMode=OrganizationMode, PerubahanTable=ChangesTable, DemoAccounts=TrialAccounts, Keluar=Terminated, Monitoring=BenefitBalances, Mpp=WorkforcePlanning
""")

# Namespace / folder segments (PHP namespaces, page folders, component folders).
NS = {
    'Manpower': 'CoreHr', 'Attendance': 'Time', 'Comben': 'Compensation', 'Portal': 'SelfService', 'Lowongan': 'Careers',
    'Karyawan': 'Employees', 'Org': 'Organization', 'Cuti': 'Leave', 'Kehadiran': 'TimeClock', 'Notifikasi': 'Notifications',
    'Lampiran': 'Attachments', 'Demo': 'Trial', 'MasterData': 'Settings', 'Mpp': 'WorkforcePlanning',
}
# Lowercase component / lib folders and files.
# Lowercase folders / files under resources/js (import paths): same words as the tokens.
FOLDERS = {'attendance': 'time', 'comben': 'compensation', 'manpower': 'core_hr', 'masterdata': 'settings', 'mpp': 'workforce_planning',
           'penempatan': 'placement', 'lampiran': 'attachment', 'cuti': 'leave', 'nrp': 'employee_number',
           'pkwtDates': 'contractDates', 'dataUpdate': 'changeRequest', 'lowongan': 'careers'}

# Inertia page names: old -> new (files under resources/js/pages are moved accordingly).
PAGES = {
    'Attendance/Kehadiran': 'Time/TimeClock', 'Attendance/MasterData': 'Time/Settings', 'Attendance/Pengajuan': 'Time/TravelRequests',
    'Attendance/Sheet': 'Time/Sheet', 'Attendance/TripLeave': 'Time/LeaveTravel', 'Comben/Cuti': 'Time/LeaveBalances',
    'Comben/DataGaji': 'Compensation/Salaries', 'Comben/Deklarasi': 'Compensation/BenefitClaims', 'Comben/MasterData': 'Compensation/Settings',
    'Comben/Monitoring': 'Compensation/BenefitBalances', 'Demo/Request': 'Trial/Request', 'Demo/Status': 'Trial/Status',
    'Lowongan/Form': 'Careers/Form', 'Lowongan/Index': 'Careers/Index', 'Manpower/Karyawan/Dashboard': 'CoreHr/Employees/Dashboard',
    'Manpower/Karyawan/DataLengkap': 'CoreHr/Employees/FullData', 'Manpower/Karyawan/Keluar': 'CoreHr/Employees/Terminated',
    'Manpower/Karyawan/Profile': 'CoreHr/Employees/Profile', 'Manpower/MasterData/Index': 'CoreHr/Settings/Index',
    'Manpower/Mpp': 'CoreHr/WorkforcePlanning', 'Manpower/OrgStructure/Index': 'CoreHr/OrgChart/Index',
    'Manpower/PengajuanData': 'CoreHr/ChangeRequests', 'Portal/Data': 'SelfService/Profile', 'Portal/Home': 'SelfService/Home',
    'Recruitment/FormEditor': 'Recruitment/JobPostingEditor', 'Recruitment/Forms': 'Recruitment/JobPostings',
    'Recruitment/Laporan': 'Recruitment/Reports', 'Recruitment/Pelamar': 'Recruitment/Applicants', 'Recruitment/Tracking': 'Recruitment/Pipeline',
    'Settings/DemoAccounts': 'Settings/TrialAccounts',
}

# Enum values whose stored case differs (constants handled in code): column -> {old: new}.
SPECIAL_VALUES = {
    ('employees', 'status'): {'Aktif': 'active', 'Nonaktif': 'inactive', 'Keluar': 'terminated'},
    ('users', 'role'): {'Admin': 'super_admin', 'HR': 'admin', 'Karyawan': 'employee'},
    ('work_locations', 'type'): {'Head Office': 'head_office', 'Site': 'site'},
    ('overtime_eligibilities', 'location_type'): {'Head Office': 'head_office', 'Site': 'site'},
    ('work_locations', 'work_schedule_scheme'): {'5_hari': 'five_day', '6_hari': 'six_day', 'esdm': 'mining_roster'},
}

# Columns (new names) holding system enum values: their values go through T.
ENUM_COLUMNS = [
    ('employee_change_requests', 'status'), ('leave_travel_requests', 'status'), ('leave_travel_requests', 'type'),
    ('leave_travel_requests', 'leave_category'), ('leave_travel_requests', 'source'), ('benefit_claims', 'status'),
    ('benefit_claims', 'source'), ('benefit_claim_items', 'type'), ('benefit_claim_items', 'beneficiary'),
    ('long_service_leaves', 'status'), ('long_service_leaves', 'source'), ('long_service_leaves', 'decision'),
    ('leave_transactions', 'type'), ('leave_transactions', 'source'), ('workforce_plan_revisions', 'status'),
    ('workforce_plans', 'type'), ('workforce_plan_settings', 'period'), ('workforce_plan_items', 'source'),
    ('job_levels', 'source'), ('shift_codes', 'type'), ('attendance_records', 'source'), ('attendance_logs', 'source'),
    ('attendance_devices', 'status'), ('overtime_records', 'status'), ('overtime_records', 'source'), ('overtime_records', 'day_type'),
    ('applicants', 'status'), ('employee_salaries', 'reason'), ('lookup_values', 'category'),
    ('trial_accounts', 'status'), ('trial_accounts', 'data_source'), ('job_posting_fields', 'field_key'),
]

# JSON columns: (table, column, mode). keys = translate object keys; refs = notification data.
JSON_COLUMNS = [
    ('users', 'permissions', 'keys'), ('workforce_plan_revisions', 'changes', 'keys'),
    ('employee_change_requests', 'changes', 'keys'), ('employee_change_requests', 'children', 'keys'),
    ('shift_patterns', 'steps', 'keys'), ('notifications', 'data', 'notification'),
    # field id => {label, type, field_key, value}: only field_key is a system word.
    ('applicants', 'data', 'field_key'),
]

out = {'tables': TABLES, 'tokens': T, 'classes': C, 'namespaces': NS, 'folders': FOLDERS, 'pages': PAGES,
       'special_values': [[t, c, v] for (t, c), v in SPECIAL_VALUES.items()], 'enum_columns': ENUM_COLUMNS, 'json_columns': JSON_COLUMNS}
here = os.path.dirname(os.path.abspath(__file__))
json.dump(out, open(os.path.join(here, 'terminology.json'), 'w'), indent=1, ensure_ascii=False, sort_keys=True)
print(len(TABLES), 'tables,', len(T), 'tokens,', len(C), 'classes,', len(PAGES), 'pages')
