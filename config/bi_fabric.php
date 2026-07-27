<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vistas de formularios BI (solo esquema, sin filtro de sede)
    |--------------------------------------------------------------------------
    |
    | Formularios dedicados (ej. Certificado SOAT) consultan vistas nacionales
    | aunque el usuario sea de sede: cada sede genera el cruce, pero el módulo
    | usa una vista nacional.
    |
    | Para estas vistas la API solo exige acceso al esquema (GG-BD-*).
    | No se aplica tieneAccesoVistaPorSede ni se restringe el GRANT por sede.
    |
    | Formato: "schema.view_name" en minúsculas.
    |
    */
    'vistas_formulario_solo_esquema' => [
        'fr.vw_billing_facturacion_soat',
        'in.vw_perfilmedicamentos',
    ],

];
