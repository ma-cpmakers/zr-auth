<?php

// Il PDF di un preventivo (CRM-06, #2390): App\Crm\PreventivoPdf. Nella lingua della chiamata.

return [
    'preventivo' => [
        'titolo' => 'Preventivo :numero',
        'per' => 'Per',
        'valido_fino_al' => 'Valido fino al :data',
        'descrizione' => 'Descrizione',
        'quantita' => 'Quantità',
        'prezzo' => 'Prezzo',
        'sconto' => 'Sconto',
        'aliquota' => 'IVA',
        'importo' => 'Importo',
        'imponibile' => 'Imponibile',
        'iva' => 'IVA :aliquota%',
        'totale' => 'Totale',
        'piano' => 'Pagamenti',
        'condizioni' => 'Condizioni',
    ],
    'documento' => [
        'versione' => 'versione :versione',
        'firmato_il' => 'Firmato il :data alle :ora (UTC)',
        'firmato_da' => 'Firmato da :nome',
        'legame' => [
            'se_stesso' => 'per sé',
            'genitore' => 'genitore',
            'tutore' => 'tutore',
            'altro' => 'altro',
        ],
        'tratto' => 'Firma',
        'spunta' => 'Accettato con una spunta',
        'revocato_il' => 'Revocato il :data alle :ora (UTC)',
        'impronta' => 'Impronta SHA-256 del testo',
        'per' => 'Per',
    ],
];
