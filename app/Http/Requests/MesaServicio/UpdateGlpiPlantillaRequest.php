<?php

declare(strict_types=1);

namespace App\Http\Requests\MesaServicio;

class UpdateGlpiPlantillaRequest extends StoreGlpiPlantillaRequest
{
    public function rules(): array
    {
        $id = (int) $this->route('id');

        return self::baseRules($id);
    }
}
