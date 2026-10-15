<?php

return [

    // Le API del backoffice di Zeiras. Solo https: sulla porta 80 il server risponde 301, e un 301 trasforma un POST in
    // un GET. Un indirizzo in http:// non parte (IndirizzoNonSicuro).
    'api' => env('ZR_API_URL', 'https://api.zeiras.com'),

    // Il client registrato (#1447): il nome di questo frontend (`home`, `board`) e il segreto con cui firma l'IP vero della
    // persona nelle rotte senza gettone (Api::firmata). Il segreto lo genera e lo deposita chi gestisce il server, uno per
    // frontend, e il backoffice ha lo stesso in ZR_CLIENTE_<NOME>_SEGRETO. Senza uno dei due non si firma niente, e il backoffice
    // conta le richieste fra gli anonimi.
    'cliente' => env('ZR_AUTH_CLIENTE'),
    'segreto' => env('ZR_BACKOFFICE_SEGRETO'),

    // Quanto si aspetta il backoffice, in secondi: il pool PHP-FPM del server è uno per tutti i siti, e una pagina che
    // aspetta tiene fermo un processo, più quello del backoffice.
    'timeout' => 5,
    'connessione' => 2,

    // Il tempo massimo di uno scaricamento (`Api::scarica`, un file a flusso come l'esportazione CSV del CRM): il tempo che una pagina
    // aspetta il primo byte, e quello in cui `Scaricamento::blocchi()` legge il resto. Oltre, BackofficeNonRisponde.
    'scarico' => 60,

    // Quanto si aspetta ogni lettura di un file a flusso, in secondi. Con `stream` Guzzle non usa curl: `timeout` vale per ogni lettura e
    // `connessione` non vale, quindi questo limite breve vale anche per la connessione e per ogni pausa del backoffice.
    'letture' => 10,

    // La pagina d'accesso. Senza sessione, col gettone scaduto o con un gettone che il backoffice non accetta più, la
    // guardia rimanda qui (ConGettone).
    'ingresso' => env('ZR_AUTH_INGRESSO', 'https://app.zeiras.com/accedi'),

    // La pagina dell'accettazione dei documenti legali (zr-home, #1699). Un 403 `accettazione_richiesta` che nessuno cattura manda la
    // persona qui, con `?ritorno=<la pagina da cui è partita>`; accettato, zr-home la rimanda indietro.
    'accettazione' => env('ZR_AUTH_ACCETTAZIONE', 'https://app.zeiras.com/legale/accetta'),

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
    // base64, lo stesso che chi gestisce il backoffice ha in configurazione per l'app; `tolleranza` è lo scarto ammesso fra
    // l'istante dell'evento e l'ora del modulo, nei due versi; `doppioni` quanto si ricorda
    // un `webhook-id` già visto (mai meno del doppio di `tolleranza`: una firma vale fino a `tolleranza` secondi dopo un
    // istante che può stare `tolleranza` secondi nel futuro).
    'eventi' => [
        'percorso' => null,
        'segreto' => env('ZR_EVENTI_SEGRETO'),
        'tolleranza' => 300,
        'doppioni' => 300,
    ],

    // La sessione chiusa (SessioneChiusa, #2044): per quanti secondi, dopo un ingresso, un cambio di workspace o un'uscita, la sessione
    // di prima si ricorda come chiusa. Quanto la richiesta più lunga che può essere ancora in volo (il timeout di PHP-FPM, quello
    // di Cloudflare): oltre, una risposta tardiva non è più un rischio. Più lungo non serve: un browser che non ha ricevuto la
    // risposta dell'ingresso resta con l'id chiuso, perde il cookie a ogni risposta e non può entrare finché il segno vale.
    'sessione_chiusa_secondi' => 300,

    // Il ritorno dopo una scrittura a sessione scaduta (Ritorno::dalReferer, #2043): i parametri della query del Referer che non entrano
    // nell'indirizzo di ritorno, perché sono testo cercato e finirebbero nella sessione e nel Location.
    'ritorno' => [
        'senza_query' => ['q', 'scelta'],
    ],

    // «Resta poi Salva» (SoloDallaPersona, #2136): con `controllo` acceso ogni scrittura (non GET, HEAD, OPTIONS) deve portare in
    // `X-Zr-Persona` l'impronta della persona della sessione (Persona::impronta()), o è 409 `persona_diversa`. Spento di default: un
    // modulo che ha già la sua copia la tiene finché non passa a questa.
    'persona' => [
        'controllo' => filter_var(env('ZR_AUTH_PERSONA_CONTROLLO', false), FILTER_VALIDATE_BOOL),
    ],

];
