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
    'cliente_non_riconosciuto' => [
        'title' => 'Client not recognised',
        'detail' => 'The client that signed the request is not recognised: check its name, its secret and the server clock, and sign again.',
    ],
    'permesso_negato' => [
        'title' => 'Permission denied',
        'detail' => 'The token does not allow this operation.',
    ],
    'gettone_senza_workspace' => [
        'title' => 'Token without workspace',
        'detail' => 'This method works on the data of a workspace, and the token is not of a workspace: ask for the workspace token.',
    ],
    'gettone_con_workspace' => [
        'title' => 'Token with workspace',
        'detail' => 'This method wants the token of the access, without workspace, and the token is of a workspace: repeat the request with the token of the access.',
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
        'detail' => "This method belongs to an app that is not active in the token's workspace: if it is available, an owner or an administrator can activate it.",
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
        'detail' => 'The code does not verify the request: for the email, check the code, the email and the password, or ask for a new code; for the password, check the code and the email, or ask for a new code; for entering an app, start again from the sign-in; for an invitation, check the code and that you are using the email it was sent to, or ask whoever invited you for a new one.',
        'varianti' => [
            'email' => 'The code does not verify the email: check the code, the email and the password, or ask for a new code.',
            'password' => 'The code does not reset the password: check the code and the email, or ask for a new code.',
            'ingresso' => 'Entering the app failed: the code is no longer valid, start again from the sign-in.',
            'invito' => 'The invitation is not valid: check the code and use the email it was sent to, or ask whoever invited you for a new one.',
            'registrazione' => 'The invitation is not valid for this email: check the code and use the email it was sent to, or ask for a new one.',
            'link' => 'The link is not valid: check that you copied all of it, or ask whoever invited you for a new one.',
            'provider' => 'Signing in with the provider failed: start again from the provider\'s button.',
        ],
    ],
    'email_del_provider_non_verificata' => [
        'title' => 'Email not verified by the provider',
        'detail' => 'The sign-in provider does not vouch that this email is verified: verify it with the provider, or sign in another way.',
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
        'detail' => 'This app cannot be activated or deactivated yet: only some of the apps are available.',
    ],
    'cartella_non_vuota' => [
        'title' => 'Folder not empty',
        'detail' => 'This folder still has boards, and it can be deleted only when empty: move or remove its boards first.',
    ],
    'proprietario_intoccabile' => [
        'title' => 'The owner cannot be touched',
        'detail' => 'A workspace has one owner and no method removes them or changes their role: do not repeat the request.',
    ],
    'gia_membro' => [
        'title' => 'Already a member',
        'detail' => 'The person is already a member of this workspace: do not repeat the request. You will find them among the members of the workspace.',
    ],
    'invito_esistente' => [
        'title' => 'Invitation already sent',
        'detail' => 'This email already has a live invitation in this workspace: wait for it to expire, or revoke it from the pending invitations and send a new one.',
    ],
    'posizione_cambiata' => [
        'title' => 'Position changed',
        'detail' => 'The item given as the anchor (a card, a stage, a deal) is no longer where the request expected: read the list again and repeat the move.',
    ],
    'scheda_archiviata' => [
        'title' => 'Card archived',
        'detail' => 'This card is archived: restore it before changing it.',
    ],
    'lista_archiviata' => [
        'title' => 'List archived',
        'detail' => 'This list is archived: restore it before changing it or its cards.',
    ],
    'board_chiusa' => [
        'title' => 'Board not active',
        'detail' => 'This board is closed or in the trash: make it active again before changing its lists, cards or labels.',
    ],
    'limite_raggiunto' => [
        'title' => 'Limit reached',
        'detail' => 'The resource already has the maximum allowed: 100 active boards (300 active and closed, 1000 counting those in the bin) and 100 folders per workspace, 50 lists per board, 500 cards per list, 3 labels per card, 100 checklist items per card, 50 members per workspace (counting live invitations), 10 outgoing and 50 incoming links per card (counting completed or archived cards too; `limite` and `direzione` say which). Free a slot (close a board or, with 1000 in all counting the bin, wait for one in the bin to be archived; archive a list or a card, remove a label, an item or a link, revoke an invitation) and try again.',
    ],
    'lista_al_limite' => [
        'title' => 'List at its limit',
        'detail' => 'This list blocks new cards: it already has as many unarchived cards as `limite` says (the value is in the response). Put the card in another list, archive or move a card of this one, or raise the `limite` or turn `blocca` off with board.liste.modifica, and try again.',
    ],
    'passaggio_non_consentito' => [
        'title' => 'Move not allowed',
        'detail' => 'The list the card is in only lets its cards move to some lists, and this is not one of them (the allowed lists are in the response, in `liste_consentite`). Pick one of those, or change the source list\'s `passaggi` with board.liste.modifica, or turn off the board\'s `applica_regole` with board.board.modifica, and retry.',
    ],
    'attese_aperte' => [
        'title' => 'Waits still open',
        'detail' => 'This list only takes cards that are not waiting for other cards that are still open, and this card is (the first ones are in the response, in `schede`). Complete or archive the awaited cards, or remove the waits with board.schede.collegamenti.elimina or the list\'s `senza_attese` requirement, and retry.',
    ],
    'checklist_incompleta' => [
        'title' => 'Checklist incomplete',
        'detail' => 'This list only takes cards whose checklist is complete, and this one still has items to check (how many, in the response, in `voci_aperte`). Check or delete the items, or remove the list\'s `checklist_completa` requirement with board.liste.modifica, and retry.',
    ],
    'email_gia_presente' => [
        'title' => 'Email already in use',
        'detail' => 'This email already belongs to another contact in the workspace, even an archived one: its id is in the response, in `persona_id`. Open it with crm.persone.mostra, or use another email.',
    ],
    'persona_anonimizzata' => [
        'title' => 'Contact anonymised',
        'detail' => 'This contact has been anonymised (right to erasure): it can no longer be changed or linked to notes, activities or deals. Its id stays in the resources that already refer to it.',
    ],
    'annullamento_scaduto' => [
        'title' => 'Undo expired',
        'detail' => 'This import is more than 24 hours old and can no longer be undone: nothing has changed. The companies or contacts it created can be archived one by one with their update method.',
    ],
    'pipeline_non_vuota' => [
        'title' => 'Pipeline not empty',
        'detail' => 'This pipeline still has open deals and can only be archived without them: close or move its deals first, then try again.',
    ],
    'fase_non_vuota' => [
        'title' => 'Stage not empty',
        'detail' => 'This stage still has deals (open, won, lost or archived) and can only be deleted without them: move its deals to another stage (a closed or archived deal is first reopened or restored with crm.trattative.modifica), then try again.',
    ],
    'campo_di_sistema' => [
        'title' => 'System field',
        'detail' => 'This field is a standard or system field: it can be hidden and moved, but not deleted. Only a “yours” field, created by the workspace, can be deleted. Nothing was written.',
    ],
    'ultima_opzione' => [
        'title' => 'Last option',
        'detail' => 'This is the field\'s last live option, and a list or multiple-choice field always has at least one. Add another with crm.campi.opzioni.crea and then remove this one. Nothing was written.',
    ],
    'sezione_di_sistema' => [
        'title' => 'System section',
        'detail' => "“Principali” (Main) is the fixed section of an object's fields: it cannot be renamed, moved or deleted. Nothing was written.",
    ],
    'sezione_non_vuota' => [
        'title' => 'Section not empty',
        'detail' => 'This section still has fields and can only be deleted without them. Move its fields to another section with crm.campi.modifica, then try again.',
    ],
    'ultimo_workspace' => [
        'title' => 'Last workspace',
        'detail' => 'This is the last live workspace of the person calling, and it cannot be deleted: a person always has at least one workspace. Create another with io.workspace.crea, or cancel the deletion of the other one with workspace.eliminazione.elimina. The method wrote nothing.',
    ],
    'ultima_fase' => [
        'title' => 'Last stage',
        'detail' => 'This is the last stage of the pipeline and cannot be deleted: a pipeline always has at least one stage. Add another with crm.fasi.crea, or archive the pipeline with crm.pipeline.modifica.',
    ],
    'valuta_in_uso' => [
        'title' => 'Currency in use',
        'detail' => 'This workspace has deals with an amount (open, won, lost or archived) in the current currency. Changing the currency does not convert the amounts: the numbers stay the same. To change it anyway, send `senza_conversione: true` together with `valuta`.',
    ],
    'accettazione_richiesta' => [
        'title' => 'Acceptance required',
        'detail' => 'To use Zeiras you must accept the privacy notice and the terms of use in their current version. The problem carries `mancanti` next to the code, the documents still to accept: accept them with legale.accettazioni.crea (legale.documenti.elenca lists them) and repeat the request.',
    ],
    'precondizione_fallita' => [
        'title' => 'Precondition failed',
        'detail' => 'The resource is no longer the one you read: someone else changed it after you read it (or the `ETag` in `If-Match` is not its own). Nothing was written. Read it again (its `mostra` method gives the current `ETag`), check what changed and, if you still want your change, repeat the request with the new `ETag`.',
    ],
    'collegamento_esistente' => [
        'title' => 'Link already exists',
        'detail' => 'The card already waits for that card: do not repeat the request. You can find the link in board.schede.collegamenti.elenca.',
    ],
    'collegamento_circolare' => [
        'title' => 'Circular link',
        'detail' => 'A card cannot wait for itself, nor for a card that waits for it in turn, directly or through other cards: choose another card, or first remove the link that closes the loop.',
    ],
    'catena_troppo_lunga' => [
        'title' => 'Chain of links too long',
        'detail' => 'This link would stretch the chain of cards that wait for one another beyond 20 links, or the chain is too large to be checked: shorten the chain by removing a link, and try again.',
    ],
    'transizione_non_valida' => [
        'title' => 'Invalid state transition',
        'detail' => 'The requested state cannot be reached from the state the resource is in now: for a board, active goes to closed, closed goes to active or to trash, and trash goes to closed. A deal moves between stages only while it is open and not archived.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursor expired',
        'detail' => 'The events after this point can no longer be read: read everything again and start over from the last event.',
    ],
    'versione_scaduta' => [
        'title' => 'Version expired',
        'detail' => 'The version is older than 30 days: the cards changed since then are no longer given in pieces. Read the board again from the start and continue from the version it gives you.',
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
