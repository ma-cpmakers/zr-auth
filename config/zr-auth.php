<?php

return [

    // zr-home, il provider d'identità dei moduli: l'emittente dei token e l'indirizzo dell'ingresso.
    'zr_home' => env('ZR_HOME_URL', 'https://app.zeiras.com'),

    // Il client del modulo su zr-home: `php artisan zeiras:modulo <codice> --ritorno=https://<modulo>/auth/callback`, lì,
    // stampa id e segreto una volta sola. Il segreto sta solo nell'ambiente del modulo, mai in un repo.
    'client_id' => env('ZR_AUTH_CLIENT_ID'),
    'client_secret' => env('ZR_AUTH_CLIENT_SECRET'),

    // Quanto vale al massimo una sessione del modulo: poi l'ingresso si rifà, in silenzio se zr-home ha la sessione.
    'ore' => 12,

];
