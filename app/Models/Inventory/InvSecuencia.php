<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvSecuencia extends Model
{
    use HasFactory;

    protected $table = 'inv_secuencias';

    protected $fillable = [
        'tipo_documento', 'prefijo', 'ultimo_numero', 'longitud'
    ];
}
