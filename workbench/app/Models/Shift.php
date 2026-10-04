<?php

namespace Workbench\App\Models;

use BoysFromTheFactory\Groundhog\Concerns\HasRecurrence;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasRecurrence;

    public const RECURRENCE_ENDS_AT = null;

    protected $fillable = ['label', 'starts_at', 'recurrence_rule'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }
}
