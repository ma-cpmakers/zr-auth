<?php

// I messaggi delle regole di validazione scritte qui (app/Rules): come quelli di validation.php, sono il `detail` di un
// elemento di `errors` in un errore `dati_non_validi`.

return [
    'cursore' => 'Il campo :attribute non è un cursore di questa lista: usa il valore di successivo della pagina prima.',
    'chiave_idempotenza' => "L'header Idempotency-Key vuole da 1 a 255 caratteri ASCII visibili, senza spazi.",
    'workspace_non_tuo' => "Non sei membro di un workspace con questo id: l'id lo dà io.workspace.crea, alla nascita del workspace.",
    'sequenza' => 'Il campo :attribute vuole il sequence di un evento: da 1 a 12 cifre.',
    'troppi_byte' => 'Il campo :attribute può essere lungo al più :max byte (le lettere accentate ne valgono 2).',
    'carattere_nullo' => 'Il campo :attribute non può contenere il carattere nullo (U+0000).',
    'almeno_un_campo' => 'Il corpo vuole almeno uno di questi campi: :campi.',
    'cartella_del_workspace' => "Nel workspace non c'è una cartella con questo id: gli id delle cartelle li dà board.cartelle.elenca.",
    'board_del_workspace' => "Nel workspace non c'è una board con questo id: gli id delle board li dà board.board.elenca.",
    'lista_del_workspace' => "Nel workspace non c'è una lista con questo id: gli id delle liste li dà board.board.mostra.",
    'blocca_vuole_un_limite' => 'Una lista senza limite non si può bloccare: manda anche `limite` (da 1 a 99).',
    'lista_della_board' => 'Questa lista non è della stessa board della scheda: gli id delle liste li dà board.board.mostra.',
    'scheda_della_board' => 'Questa scheda non è della stessa board: gli id delle schede li dà board.schede.elenca.',
    'una_posizione_o_un_ancora' => 'Il corpo vuole uno solo fra posizione e dopo_scheda_id, non nessuno e non tutti e due.',
    'lista_della_stessa_board' => 'Questa lista non è della stessa board della lista da spostare: gli id delle liste li dà board.board.mostra.',
    'una_posizione_o_una_lista' => 'Il corpo vuole uno solo fra posizione e dopo_lista_id, non nessuno e non tutti e due.',
    'troppe_board' => 'Al più :max board per chiamata.',
    'etichetta_della_board' => 'Questa etichetta non è della board della scheda: gli id delle etichette li dà board.board.mostra.',
    'utente_del_workspace' => "Nel workspace non c'è un utente con questo id: gli id degli utenti li dà workspace.membri.elenca.",
    'scheda_del_workspace' => "Nel workspace non c'è una scheda con questo id: gli id delle schede li dà board.schede.elenca.",
    'inizio_dopo_la_scadenza' => "L'inizio non può venire dopo la scadenza: le due date, quella mandata e quella già salvata, vogliono un inizio uguale o prima della scadenza.",
    'descrizione_non_nulla' => 'La descrizione non è mai null: per svuotarla manda una stringa vuota.',
    'campo_di_un_altro_metodo' => 'Il campo :attribute non si cambia qui: lo cambia :metodo.',
    'campo_di_nessun_metodo' => 'Il campo :attribute non si cambia: lo dà il sistema.',
    'numero_senza_archiviate' => 'Con numero la scheda arriva in qualunque stato, anche archiviata: il parametro archiviate non serve.',
    'password_attuale' => 'La password attuale non è giusta: la nuova password si dà solo con quella di adesso.',
];
