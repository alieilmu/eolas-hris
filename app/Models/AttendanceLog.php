<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clock in / clock out from a machine, an imported file, a correction by HR, or the employee's phone (with its location and selfie). */
#[Fillable([
    'attendance_device_id', 'pin', 'employee_id', 'punched_at', 'status_code', 'verify', 'source', 'notes', 'created_by',
    'latitude', 'longitude', 'accuracy_m', 'geofence_id', 'distance_m', 'outside_geofence', 'photo_path', 'photo_deleted_at', 'client',
    'client_uuid', 'device_time', 'received_at', 'clock_offset_s', 'offline', 'time_suspect',
])]
class AttendanceLog extends Model
{
    public const SUMBER = ['device' => 'Mesin', 'import' => 'Import', 'manual' => 'Koreksi HR', 'mobile' => 'HP'];

    protected function casts(): array
    {
        return [
            'punched_at' => 'immutable_datetime', 'photo_deleted_at' => 'datetime', 'outside_geofence' => 'boolean',
            'latitude' => 'float', 'longitude' => 'float',
            'device_time' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'offline' => 'boolean', 'time_suspect' => 'boolean',
        ];
    }

    /** The place of a punch from the phone (null for machines). */
    public function geofence(): BelongsTo
    {
        return $this->belongsTo(WorkLocationGeofence::class, 'geofence_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}
