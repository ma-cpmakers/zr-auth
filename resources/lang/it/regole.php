<?php

// I messaggi delle regole di validazione scritte qui (app/Rules): come quelli di validation.php, sono il `detail` di un
// elemento di `errors` in un errore `dati_non_validi`.

return [
    'cursore' => 'Il campo :attribute non è un cursore di questa lista: usa il valore di successivo della pagina prima.',
    'chiave_idempotenza' => "L'header Idempotency-Key vuole da 1 a 255 caratteri ASCII visibili, senza spazi.",
    'workspace_non_tuo' => "Non sei membro di un workspace con questo id: l'id lo dà io.workspace.crea, alla nascita del workspace.",
    'sequenza' => 'Il campo :attribute vuole il sequence di un evento: da 1 a 12 cifre.',
    'carattere_nullo' => 'Il campo :attribute non può contenere il carattere nullo (U+0000).',
    'almeno_un_campo' => 'Il corpo vuole almeno uno di questi campi: :campi.',
    'cartella_del_workspace' => "Nel workspace non c'è una cartella con questo id: gli id delle cartelle li dà board.cartelle.elenca.",
];
