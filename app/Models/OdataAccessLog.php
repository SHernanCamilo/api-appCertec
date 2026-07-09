<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdataAccessLog extends Model
{
    public $timestamps = false;

    protected $table = 'odata_access_logs';

    protected $fillable = [
        'odata_link_id', 'user_email', 'user_name', 'department',
        'schema_name', 'view_name', 'visibility', 'filter_applied',
        'top', 'skip', 'rows_returned', 'elapsed_ms',
        'ip_address', 'user_agent', 'auth_method',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(OdataLink::class, 'odata_link_id');
    }
}
