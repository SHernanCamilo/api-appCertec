<?php

namespace App\Models\Inventory\External;

use Illuminate\Database\Eloquent\Model;

class IndigoOrdenCompra extends Model
{
    // Usar la conexión específica hacia el servidor INDIGO777
    protected $connection = 'sqlsrv_indigo';

    // No sabemos la clave primaria de la vista, o si tiene, así que no usaremos las funciones estándar de update/delete.
    // Solo será para lectura.
    public $timestamps = false;

    // Asignamos la tabla/vista dinámicamente desde env en el constructor
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = env('MSSQL_PURCHASEORDER_VIEW', 'dbo.Inventory_OrdenesDeCompra');
    }
}
