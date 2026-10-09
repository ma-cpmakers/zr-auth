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

    // L'ingresso nei moduli (Ingresso::verso e il ricevitore). `home` è zr-home, a cui il modulo manda la persona
    // (`/ingresso`, solo https); `app` è il codice dell'app del modulo nel catalogo (pm, …); `ricevitore` è il percorso
    // dove zr-home rimanda col codice: lo stesso che chi gestisce il backoffice mette in ZR_RITORNO_<CODICE>; `dopo` dove si
    // va se la guardia non ricordava una pagina; `errore` la pagina per ogni ritorno che non vale (senza, la pagina d'accesso).
    'home' => env('ZR_HOME_URL', 'https://app.zeiras.com'),
    'app' => env('ZR_APP'),
    'ricevitore' => '/ingresso/ritorno',
    'dopo' => '/',
    'errore' => env('ZR_AUTH_ERRORE'),

    // Gli eventi del backoffice (Eventi\EventoDelBackoffice). Il modulo che li riceve sceglie il `percorso` (POST, es.
    // '/webhook/backoffice'): senza, zr-auth non registra nessuna rotta. Il `segreto` è `whsec_` più da 24 a 64 byte in
    // base64, lo stesso che ma-devops mette nell'.env del backoffice per l'app; `tolleranza` è lo scarto ammesso fra
    // l'istante dell'evento e l'ora del modulo, nei due versi; `doppioni` quanto si ricorda un `webhook-id` già visto.
    'eventi' => [
        'percorso' => null,
        'segreto' => env('ZR_EVENTI_SEGRETO'),
        'tolleranza' => 300,
        'doppioni' => 300,
    ],

];
