<?php

namespace App\Support\Employees;

use App\Models\EmployeeProfile;

/**
 * Which fields count towards "kelengkapan data" for each of the 19 groups, and
 * when a group (or a single field) does not need to be filled at all. Everything
 * that decides the percentages lives here so the rules can be adjusted in one place.
 *
 * Fields that can never be empty (NRP, Nama, Jabatan) and values the system
 * derives itself (usia, masa kerja, departemen, ...) are not scored.
 */
class Completeness
{
    /** Retirement age used for the "akan pensiun" list. */
    public const PENSIUN_AGE = 55;

    /** Fields of one child row that must be filled once a child is recorded. */
    public const ANAK_FIELDS = ['name', 'birth_date', 'education_level', 'employment_status', 'marital_status'];

    /**
     * group id => rule. `applies` skips the whole group for an employee; a field
     * given as [key, when] is only scored when `when` holds.
     *
     * @return array<string, array{applies?: callable(array): bool, fields?: list<string|array{0: string, 1: callable(array): bool}>, special?: string}>
     */
    public static function rules(): array
    {
        return [
            'identity' => ['fields' => ['sequence_no', 'sap_employee_number', 'employee_class', 'technical_class']],
            'birth_info' => ['fields' => ['birth_place', 'birth_date', 'hire_date']],
            'employment' => ['fields' => [
                'employment_status',
                'marital_status',
                // A marriage date makes no sense for someone who has never married.
                ['marriage_date', fn (array $ctx) => ! $ctx['unmarried']],
            ]],
            // Tempat penerimaan and status asal come from the NRP, so there is nothing to fill.
            // Plafon Pengobatan follows from SubGroup and family (Modul Comben).
            'grade' => ['fields' => ['grade']],
            // Only staff still on contract need a PKWT record.
            'contract' => ['applies' => fn (array $ctx) => ! $ctx['permanent'], 'special' => 'contract'],
            // Only appointed staff have an SK.
            'appointment' => ['applies' => fn (array $ctx) => $ctx['permanent'], 'fields' => ['appointment_letter_number', 'appointment_letter_date']],
            'uniform' => ['fields' => ['shirt_size', 'pants_size', 'shoe_size', 'ppe_size']],
            'identification' => ['fields' => [
                'national_id_number', 'national_id_province', 'national_id_city', 'tax_id_number', 'tax_id_registered_date',
                'bpjs_employment_number', 'bpjs_employment_registered_date', 'bpjs_health_number',
            ]],
            'bank_account' => ['fields' => ['bank_account_number', 'bank_name', 'bank_account_holder']],
            'personal' => ['fields' => ['religion', 'gender', 'ethnicity']],
            'education_level' => ['fields' => ['education_level', 'education_major', 'education_institution', 'graduation_year']],
            'residential_address' => ['fields' => [
                'residential_address', 'residential_rt', 'residential_rw',
                'residential_city', 'residential_province', 'residential_postal_code',
            ]],
            // alamat_surat_telepon (landline) is optional and not scored.
            'mailing_address' => ['fields' => [
                'mailing_address', 'mailing_rt', 'mailing_rw',
                'mailing_city', 'mailing_postal_code', 'mailing_mobile',
            ]],
            'contact' => ['fields' => ['emergency_contact_phone', 'emergency_contact_name', 'emergency_contact_relationship', 'phone', 'whatsapp_number']],
            // The office email lives on the employee record and is optional.
            'email' => ['fields' => ['personal_email']],
            'spouse' => [
                'applies' => fn (array $ctx) => $ctx['married'],
                'fields' => ['spouse_name', 'spouse_birth_date', 'spouse_occupation'],
            ],
            // Scored only for children that are actually recorded.
            'children' => ['applies' => fn (array $ctx) => $ctx['number_of_children'] > 0, 'special' => 'children'],
            // ortu_telepon (landline) is optional and not scored.
            'parents' => ['fields' => [
                'father_name', 'father_birth_date', 'mother_name', 'mother_birth_date',
                'parents_rt', 'parents_rw', 'parents_city', 'parents_postal_code', 'parents_mobile',
            ]],
            'in_laws' => [
                'applies' => fn (array $ctx) => $ctx['married'],
                'fields' => [
                    'father_in_law_name', 'father_in_law_birth_date', 'mother_in_law_name', 'mother_in_law_birth_date',
                    'in_laws_rt', 'in_laws_rw', 'in_laws_city', 'in_laws_postal_code', 'in_laws_mobile',
                ],
            ],
        ];
    }

    /**
     * Scores one employee's profile group by group. A missing profile is scored
     * as an entirely empty one. Expects pkwtPeriods and anak to be loaded.
     *
     * @return array<string, array{filled: int, total: int, applicable: bool}>
     */
    public static function score(?EmployeeProfile $profile): array
    {
        $children = $profile?->children ?? collect();
        $ctx = [
            'married' => $profile?->marital_status === 'Menikah',
            'unmarried' => $profile?->marital_status === 'Belum Menikah',
            'permanent' => $profile?->is_permanent === true,
            'number_of_children' => $children->count(),
        ];

        $scores = [];

        foreach (self::rules() as $id => $rule) {
            if (isset($rule['applies']) && ! $rule['applies']($ctx)) {
                $scores[$id] = ['filled' => 0, 'total' => 0, 'applicable' => false];

                continue;
            }

            $filled = 0;
            $total = 0;

            if (($rule['special'] ?? null) === 'contract') {
                $total = 1;
                $filled = ($profile?->contracts->isNotEmpty() ?? false) ? 1 : 0;
            } elseif (($rule['special'] ?? null) === 'children') {
                foreach ($children as $child) {
                    foreach (self::ANAK_FIELDS as $field) {
                        $total++;
                        $filled += self::isFilled($child->{$field}) ? 1 : 0;
                    }
                }
            } else {
                foreach ($rule['fields'] as $field) {
                    if (is_array($field)) {
                        [$field, $when] = $field;
                        if (! $when($ctx)) {
                            continue;
                        }
                    }
                    $total++;
                    $filled += self::isFilled($profile?->{$field}) ? 1 : 0;
                }
            }

            $scores[$id] = ['filled' => $filled, 'total' => $total, 'applicable' => true];
        }

        return $scores;
    }

    /** @param  array<string, array{filled: int, total: int}>  $scores */
    public static function overallPercent(array $scores): ?int
    {
        $filled = array_sum(array_column($scores, 'filled'));
        $total = array_sum(array_column($scores, 'total'));

        return $total > 0 ? (int) round($filled / $total * 100) : null;
    }

    private static function isFilled(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }
}
