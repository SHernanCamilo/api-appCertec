<?php

namespace App\Models\TalentoHumano;

use Illuminate\Database\Eloquent\Model;

class EventMotRechazo extends Model
{
    protected $table = 'envet_mot_rechazo';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'descriocion',
    ];

    protected $casts = [
        'codigo' => 'integer',
    ];
}
