<?php

// Il PDF di un preventivo (CRM-06, #2390): App\Crm\PreventivoPdf. Nella lingua della chiamata.

return [
    'preventivo' => [
        'titolo' => 'Quote :numero',
        'per' => 'For',
        'valido_fino_al' => 'Valid until :data',
        'descrizione' => 'Description',
        'quantita' => 'Quantity',
        'prezzo' => 'Price',
        'sconto' => 'Discount',
        'aliquota' => 'VAT',
        'importo' => 'Amount',
        'imponibile' => 'Taxable amount',
        'iva' => 'VAT :aliquota%',
        'totale' => 'Total',
        'piano' => 'Payments',
        'condizioni' => 'Terms',
    ],
    'documento' => [
        'versione' => 'version :versione',
        'firmato_il' => 'Signed on :data at :ora (UTC)',
        'firmato_da' => 'Signed by :nome',
        'legame' => [
            'se_stesso' => 'for themselves',
            'genitore' => 'parent',
            'tutore' => 'guardian',
            'altro' => 'other',
        ],
        'tratto' => 'Signature',
        'spunta' => 'Accepted with a tick',
        'revocato_il' => 'Revoked on :data at :ora (UTC)',
        'impronta' => 'SHA-256 fingerprint of the text',
        'per' => 'For',
    ],
];
