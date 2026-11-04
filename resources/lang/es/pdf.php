<?php

// Il PDF di un preventivo (CRM-06, #2390): App\Crm\PreventivoPdf. Nella lingua della chiamata.

return [
    'preventivo' => [
        'titolo' => 'Presupuesto :numero',
        'per' => 'Para',
        'valido_fino_al' => 'Válido hasta el :data',
        'descrizione' => 'Descripción',
        'quantita' => 'Cantidad',
        'prezzo' => 'Precio',
        'sconto' => 'Descuento',
        'aliquota' => 'IVA',
        'importo' => 'Importe',
        'imponibile' => 'Base imponible',
        'iva' => 'IVA :aliquota%',
        'totale' => 'Total',
        'piano' => 'Pagos',
        'condizioni' => 'Condiciones',
    ],
    'documento' => [
        'versione' => 'versión :versione',
        'firmato_il' => 'Firmado el :data a las :ora (UTC)',
        'firmato_da' => 'Firmado por :nome',
        'legame' => [
            'se_stesso' => 'por sí mismo',
            'genitore' => 'padre o madre',
            'tutore' => 'tutor',
            'altro' => 'otro',
        ],
        'tratto' => 'Firma',
        'spunta' => 'Aceptado con una marca',
        'revocato_il' => 'Revocado el :data a las :ora (UTC)',
        'impronta' => 'Huella SHA-256 del texto',
        'per' => 'Para',
    ],
];
