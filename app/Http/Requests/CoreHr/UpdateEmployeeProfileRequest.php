<?php

namespace App\Http\Requests\CoreHr;

use App\Support\Employees\ProfileRules;
use App\Support\Employees\ProfileSections;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Rules follow the field types and choice lists of ProfileSections. */
class UpdateEmployeeProfileRequest extends FormRequest
{
    /** Official numbers with a fixed shape. */
    private const FORMATS = [
        'national_id_number' => ['/^\d{16}$/', 'NIK harus 16 digit angka.'],
        'tax_id_number' => ['/^\d{15,16}$/', 'NPWP harus 15 atau 16 digit angka.'],
        'bpjs_employment_number' => ['/^\d+$/', 'Nomor BPJS Ketenagakerjaan harus berupa angka.'],
        'bpjs_health_number' => ['/^\d+$/', 'Nomor BPJS Kesehatan harus berupa angka.'],
    ];

    public function rules(): array
    {
        $rules = [];

        foreach (ProfileSections::editableFields() as $key => $field) {
            $rules[$key] = match ($field['type']) {
                'date' => ['nullable', 'date_format:Y-m-d'],
                'checkbox' => ['boolean'],
                'select' => ['nullable', Rule::in($field['options'])],
                'textarea' => ['nullable', 'string', 'max:1000'],
                'number' => match ($key) {
                    'medical_benefit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
                    'graduation_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
                    default => ['nullable', 'integer', 'min:0', 'max:999999'],
                },
                default => ['nullable', 'string', 'max:255'],
            };
        }

        foreach (self::FORMATS as $key => [$pattern]) {
            $rules[$key][] = "regex:{$pattern}";
        }
        $rules['personal_email'][] = 'email';

        return $rules;
    }

    public function messages(): array
    {
        return array_combine(
            array_map(fn (string $key) => "{$key}.regex", array_keys(self::FORMATS)),
            array_column(self::FORMATS, 1),
        );
    }

    /** Field labels, so messages read "Tanggal SK bukan tanggal yang valid." */
    public function attributes(): array
    {
        return array_map(fn (array $field) => $field['label'], ProfileSections::editableFields());
    }

    /** Official numbers must be unique across employees. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $employeeId = $this->route('employee')->id;

                foreach (array_keys(ProfileRules::NUMBERED_FIELDS) as $key) {
                    if ($validator->errors()->has($key) || blank($this->input($key))) {
                        continue;
                    }
                    if ($error = ProfileRules::duplicateNumberError([$key => $this->input($key)], $employeeId)) {
                        $validator->errors()->add($key, $error);
                    }
                }
            },
        ];
    }
}
