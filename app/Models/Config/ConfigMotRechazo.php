<?php

namespace App\Models\Config;

use App\Models\Modulo;
use Illuminate\Database\Eloquent\Model;

class ConfigMotRechazo extends Model
{
    protected $table = 'config_mot_rechazo';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'descriocion',
        'id_modulo',
    ];

    protected $casts = [
        'codigo'    => 'integer',
        'id_modulo' => 'integer',
    ];

    public function modulo()
    {
        return $this->belongsTo(Modulo::class, 'id_modulo');
    }
}
