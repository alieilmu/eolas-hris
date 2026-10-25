<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One amount of a deklarasi: what it is for, and for whom. */
#[Fillable(['benefit_claim_id', 'type', 'beneficiary', 'employee_child_id', 'amount'])]
class BenefitClaimItem extends Model
{
    public $timestamps = false;

    /** Rows of the form; perawatan and pengobatan share Plafon Pengobatan, bingkai and lensa are Kacamata. */
    public const JENIS = ['treatment' => 'Perawatan', 'medical' => 'Pengobatan', 'frame' => 'Kacamata – Bingkai', 'lens' => 'Kacamata – Lensa'];

    public const PENGOBATAN = ['treatment', 'medical'];

    public const KACAMATA = ['frame', 'lens'];

    public const PENERIMA = ['employee' => 'Karyawan', 'spouse' => 'Istri / Suami', 'children' => 'Anak'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(BenefitClaim::class, 'benefit_claim_id');
    }

    public function children(): BelongsTo
    {
        return $this->belongsTo(EmployeeChild::class, 'employee_child_id');
    }

    /** Which family member: "karyawan", "pasangan" or "anak:{id}". */
    public function memberKey(): string
    {
        return $this->beneficiary === 'children' ? "children:{$this->employee_child_id}" : $this->beneficiary;
    }
}
