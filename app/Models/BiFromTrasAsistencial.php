<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiFromTrasAsistencial extends Model
{
    public const TIPO_PRIMARIO = 'primario';
    public const TIPO_SECUNDARIO = 'secundario';

    public const ESTADO_GUARDADO = 'guardado';
    public const ESTADO_CONFIRMADO = 'confirmado';

    protected $table = 'bi_from_tras_asistencial';

    protected $fillable = [
        'tipo',
        'formato',
        'estado',
        'fecha_guarda',
        'usuario_guarda_id',
        'fecha_confirma',
        'usuario_confirma_id',
        'fecha_atencion',
        'nombres_apellidos',
        'tipo_identificacion',
        'numero_identificacion',
        'estado_paciente',
        'datos',
    ];

    protected $casts = [
        'datos' => 'array',
        'fecha_guarda' => 'datetime',
        'fecha_confirma' => 'datetime',
        'fecha_atencion' => 'date',
    ];

    public function usuarioGuarda(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_guarda_id');
    }

    public function usuarioConfirma(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_confirma_id');
    }

    public function estaConfirmado(): bool
    {
        return $this->estado === self::ESTADO_CONFIRMADO;
    }
}
