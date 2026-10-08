<?php

// The texts of the /v1 errors in English, by code: the same keys as lang/it/errori.php (tests/Unit/TraduzioniTest.php).
// Never the message of an exception: a 500 does not show it.

return [
    'richiesta_non_valida' => [
        'title' => 'Bad request',
        'detail' => 'The request is malformed: check the method, path, headers and body.',
    ],
    'gettone_assente' => [
        'title' => 'Missing token',
        'detail' => 'The request carries no token: send it in the Authorization header, as Bearer.',
    ],
    'gettone_non_valido' => [
        'title' => 'Invalid token',
        'detail' => 'The token of the request is not valid.',
    ],
    'permesso_negato' => [
        'title' => 'Permission denied',
        'detail' => 'The token does not allow this operation.',
    ],
    'gettone_senza_workspace' => [
        'title' => 'Token without workspace',
        'detail' => 'This method works on the data of a workspace, and the token is not of a workspace: ask for the workspace token with gettoni.crea.',
    ],
    'gettone_con_workspace' => [
        'title' => 'Token with workspace',
        'detail' => 'This method wants the token of the access, without workspace, and the token is of a workspace: repeat the request with the token that accessi.crea gave you.',
    ],
    'registrazione_non_aperta' => [
        'title' => 'Registration not open',
        'detail' => 'Zeiras registration is not open to everyone yet, and this email is not one of those that can register.',
    ],
    'email_non_verificata' => [
        'title' => 'Email not verified',
        'detail' => 'The email must be verified first: verify it with the code sent by email, then repeat the request.',
    ],
    'app_non_attiva' => [
        'title' => 'App not active',
        'detail' => "This method belongs to an app that is not active in the token's workspace: if it is available, an owner or an administrator can activate it with app.modifica.",
    ],
    'percorso_inesistente' => [
        'title' => 'Path not found',
        'detail' => 'The path is not the path of any /v1 method.',
    ],
    'non_trovato' => [
        'title' => 'Resource not found',
        'detail' => 'The resource in the path does not exist or cannot be seen with the token.',
    ],
    'metodo_non_ammesso' => [
        'title' => 'Method not allowed',
        'detail' => 'The path does not accept this HTTP method: the allowed ones are in the Allow header.',
    ],
    'verifica_non_riuscita' => [
        'title' => 'Verification failed',
        'detail' => 'The code does not verify the email: check the code, the email and the password, or ask for a new code.',
    ],
    'turnstile_non_valido' => [
        'title' => 'Turnstile check failed',
        'detail' => 'The Turnstile check did not pass: have the person do it again and retry with the new response.',
    ],
    'richiesta_in_corso' => [
        'title' => 'Request in progress',
        'detail' => 'A request with the same Idempotency-Key is still in progress: wait a few seconds and repeat it unchanged.',
    ],
    'app_in_arrivo' => [
        'title' => 'App coming soon',
        'detail' => 'This app cannot be activated or deactivated yet: app.elenca tells which apps are available.',
    ],
    'cartella_non_vuota' => [
        'title' => 'Folder not empty',
        'detail' => 'This folder still has boards, and it can be deleted only when empty: board.board.elenca tells which they are.',
    ],
    'posizione_cambiata' => [
        'title' => 'Position changed',
        'detail' => 'The card given as the anchor is no longer where the request expected: read the list again and repeat the move.',
    ],
    'scheda_archiviata' => [
        'title' => 'Card archived',
        'detail' => 'This card is archived: restore it with board.schede.archiviazione.elimina before changing it.',
    ],
    'lista_archiviata' => [
        'title' => 'List archived',
        'detail' => 'This list is archived: restore it with board.liste.archiviazione.elimina before changing it or its cards.',
    ],
    'board_chiusa' => [
        'title' => 'Board not active',
        'detail' => 'This board is closed or in the trash: make it active again with board.board.modifica before changing its lists, cards or labels.',
    ],
    'limite_raggiunto' => [
        'title' => 'Limit reached',
        'detail' => 'The resource already has the maximum allowed: 100 active boards and 100 folders per workspace, 50 lists per board, 500 cards per list, 3 labels per card, 100 checklist items per card. Free a slot (close a board, archive a list or a card, remove a label or an item) and try again.',
    ],
    'transizione_non_valida' => [
        'title' => 'Invalid state transition',
        'detail' => 'The requested state cannot be reached from the state the resource is in now: for a board, active goes to closed, closed goes to active or to trash, and trash goes to closed.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursor expired',
        'detail' => 'The events after this point can no longer be read: read everything again and start over from the last event, with eventi.ultimo.mostra.',
    ],
    'corpo_troppo_grande' => [
        'title' => 'Request body too large',
        'detail' => 'The request body exceeds the maximum accepted size.',
    ],
    'dati_non_validi' => [
        'title' => 'Invalid data',
        'detail' => 'Some values are not valid: you find them in errors, with what is wrong and where they are.',
    ],
    'credenziali_non_valide' => [
        'title' => 'Invalid credentials',
        'detail' => 'The email or the password is not correct.',
    ],
    'chiave_idempotenza_riusata' => [
        'title' => 'Idempotency key reused',
        'detail' => 'This Idempotency-Key was already used with a different body: use a new key for a new request.',
    ],
    'troppe_richieste' => [
        'title' => 'Too many requests',
        // Una scelta plurale sui secondi di Retry-After, che RendeProblemi riempie (T7.1, D19).
        'detail' => '{0} Too many requests in a short time: try again now.|{1} Too many requests in a short time: try again in :secondi second.|[2,*] Too many requests in a short time: try again in :secondi seconds.',
    ],
    'errore_interno' => [
        'title' => 'Internal error',
        'detail' => 'An unexpected error occurred and the request was not completed: try again later.',
    ],
    'servizio_non_disponibile' => [
        'title' => 'Service unavailable',
        'detail' => 'The service is temporarily unavailable: try again later.',
    ],
    'turnstile_non_disponibile' => [
        'title' => 'Turnstile check unavailable',
        'detail' => 'The Turnstile check cannot be done right now: do it again and retry shortly.',
    ],
];
