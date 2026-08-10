<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;

class AntiSolicitudDocumento extends Model
{
    use HasFactory;
    protected $table = 'anti_solicitud_documentos';

    protected $fillable = [
        'id_solicitud',
        'tipo_documento',
        'nombre_archivo',
        'ruta_archivo',
        'disco',
        'mime_type',
        'tamano',
        'subido_por',
    ];

    protected $casts = [
        'tamano' => 'integer',
    ];

    // Tipos de documento permitidos
    const TIPO_SOPORTE_VIAJE = 'soporte_viaje';
    const TIPO_FACTURA = 'factura';
    const TIPO_RECIBO = 'recibo';
    const TIPO_COMPROBANTE_DEVOLUCION = 'comprobante_devolucion';
    const TIPO_OTRO = 'otro';

    public static function tiposPermitidos(): array
    {
        return [
            self::TIPO_SOPORTE_VIAJE,
            self::TIPO_FACTURA,
            self::TIPO_RECIBO,
            self::TIPO_COMPROBANTE_DEVOLUCION,
            self::TIPO_OTRO,
        ];
    }

    public function solicitud()
    {
        return $this->belongsTo(AntiSolicitud::class, 'id_solicitud');
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
