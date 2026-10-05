<?php

return [

    // Le API del backoffice di Zeiras. Solo https: sulla porta 80 il server risponde 301, e un 301 trasforma un POST in
    // un GET. Un indirizzo in http:// non parte (IndirizzoNonSicuro).
    'api' => env('ZR_API_URL', 'https://api.zeiras.com'),

    // Quanto si aspetta il backoffice, in secondi: il pool PHP-FPM del server è uno per tutti i siti, e una pagina che
    // aspetta tiene fermo un processo, più quello del backoffice.
    'timeout' => 5,
    'connessione' => 2,

    // La pagina d'accesso. Senza sessione, col gettone scaduto o con un gettone che il backoffice non accetta più, la
    // guardia rimanda qui (ConGettone).
    'ingresso' => env('ZR_AUTH_INGRESSO', 'https://app.zeiras.com/accedi'),

];
