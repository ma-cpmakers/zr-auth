<?php

// I testi degli errori di /v1, per codice: `title` è quello del catalogo (openapi/v1.yaml, x-catalogo-errori) e un
// test lo confronta; `detail` è il testo dell'occorrenza. Mai il messaggio di un'eccezione: un 500 non lo mostra.

return [
    'richiesta_non_valida' => [
        'title' => 'Richiesta non valida',
        'detail' => 'La richiesta è malformata: controlla metodo, percorso, header e corpo.',
    ],
    'gettone_assente' => [
        'title' => 'Gettone assente',
        'detail' => "La richiesta non porta un gettone: mandalo nell'header Authorization, come Bearer.",
    ],
    'gettone_non_valido' => [
        'title' => 'Gettone non valido',
        'detail' => 'Il gettone della richiesta non vale.',
    ],
    'permesso_negato' => [
        'title' => 'Permesso negato',
        'detail' => 'Il gettone non permette questa operazione.',
    ],
    'gettone_senza_workspace' => [
        'title' => 'Gettone senza workspace',
        'detail' => 'Questo metodo lavora sui dati di un workspace, e il gettone non è di un workspace: chiedi il gettone del workspace con gettoni.crea.',
    ],
    'gettone_con_workspace' => [
        'title' => 'Gettone con workspace',
        'detail' => "Questo metodo vuole il gettone dell'accesso, senza workspace, e il gettone è di un workspace: ripeti la richiesta col gettone che ti ha dato accessi.crea.",
    ],
    'registrazione_non_aperta' => [
        'title' => 'Registrazione non aperta',
        'detail' => 'La registrazione di Zeiras non è ancora aperta a tutti, e questa email non è fra quelle che si possono registrare.',
    ],
    'email_non_verificata' => [
        'title' => 'Email non verificata',
        'detail' => "Prima serve l'email verificata: verificala col codice arrivato per posta, poi ripeti la richiesta.",
    ],
    'app_non_attiva' => [
        'title' => 'App non attiva',
        'detail' => "Questo metodo è di un'app che non è attiva nel workspace del gettone: la attiva un proprietario o un amministratore, con app.modifica.",
    ],
    'percorso_inesistente' => [
        'title' => 'Percorso inesistente',
        'detail' => 'Il percorso non è quello di nessun metodo di /v1.',
    ],
    'non_trovato' => [
        'title' => 'Risorsa non trovata',
        'detail' => 'La risorsa indicata nel percorso non esiste o non si può vedere col gettone.',
    ],
    'metodo_non_ammesso' => [
        'title' => 'Metodo non ammesso',
        'detail' => "Il percorso non accetta questo metodo HTTP: quelli ammessi sono nell'header Allow.",
    ],
    'verifica_non_riuscita' => [
        'title' => 'Verifica non riuscita',
        'detail' => "Il codice non verifica l'email: controlla il codice, l'email e la password, o chiedi un codice nuovo.",
    ],
    'turnstile_non_valido' => [
        'title' => 'Controllo Turnstile non superato',
        'detail' => 'Il controllo Turnstile non è superato: fallo rifare alla persona e riprova con la risposta nuova.',
    ],
    'richiesta_in_corso' => [
        'title' => 'Richiesta in corso',
        'detail' => 'Una richiesta con la stessa Idempotency-Key è ancora in corso: aspetta qualche secondo e ripetila uguale.',
    ],
    'app_in_arrivo' => [
        'title' => 'App in arrivo',
        'detail' => 'Questa app non si può ancora attivare né disattivare: app.elenca dice quali sono disponibili.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursore scaduto',
        'detail' => "Gli eventi dopo questo punto non si leggono più: rileggi tutto e riparti dall'ultimo evento, con eventi.ultimo.mostra.",
    ],
    'corpo_troppo_grande' => [
        'title' => 'Corpo troppo grande',
        'detail' => 'Il corpo della richiesta supera la dimensione massima accettata.',
    ],
    'dati_non_validi' => [
        'title' => 'Dati non validi',
        'detail' => 'Alcuni valori non vanno bene: li trovi in errors, con cosa non va e dove stanno.',
    ],
    'credenziali_non_valide' => [
        'title' => 'Credenziali non valide',
        'detail' => "L'email o la password non sono giuste.",
    ],
    'chiave_idempotenza_riusata' => [
        'title' => "Chiave d'idempotenza riusata",
        'detail' => 'Questa Idempotency-Key è già stata usata con un corpo diverso: per una richiesta nuova usa una chiave nuova.',
    ],
    'troppe_richieste' => [
        'title' => 'Troppe richieste',
        // Una scelta plurale sui secondi di Retry-After, che RendeProblemi riempie (T7.1, D19).
        'detail' => '{0} Troppe richieste in poco tempo: riprova adesso.|{1} Troppe richieste in poco tempo: riprova fra :secondi secondo.|[2,*] Troppe richieste in poco tempo: riprova fra :secondi secondi.',
    ],
    'errore_interno' => [
        'title' => 'Errore interno',
        'detail' => 'Si è verificato un errore inatteso e la richiesta non è stata completata: riprova più tardi.',
    ],
    'servizio_non_disponibile' => [
        'title' => 'Servizio non disponibile',
        'detail' => 'Il servizio è temporaneamente non disponibile: riprova più tardi.',
    ],
    'turnstile_non_disponibile' => [
        'title' => 'Controllo Turnstile non disponibile',
        'detail' => 'Il controllo Turnstile non si può fare adesso: rifallo e riprova fra poco.',
    ],
];
