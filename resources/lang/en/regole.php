<?php

// The messages of the validation rules written here (app/Rules), in English: the same keys as lang/it/regole.php.

return [
    'cursore' => 'The :attribute field is not a cursor of this list: use the successivo value of the previous page.',
    'chiave_idempotenza' => 'The Idempotency-Key header needs 1 to 255 visible ASCII characters, without spaces.',
    'workspace_non_tuo' => 'You are not a member of a workspace with this id: the id is given by io.workspace.crea, when the workspace is born.',
    'sequenza' => 'The :attribute field needs the sequence of an event: 1 to 12 digits.',
    'carattere_nullo' => 'The :attribute field cannot contain the null character (U+0000).',
    'almeno_un_campo' => 'The body needs at least one of these fields: :campi.',
    'cartella_del_workspace' => 'There is no folder with this id in the workspace: the ids of the folders are given by board.cartelle.elenca.',
    'board_del_workspace' => 'There is no board with this id in the workspace: the ids of the boards are given by board.board.elenca.',
    'lista_del_workspace' => 'There is no list with this id in the workspace: the ids of the lists are given by board.board.mostra.',
    'lista_della_board' => 'This list is not of the same board as the card: the ids of the lists are given by board.board.mostra.',
    'scheda_della_board' => 'This card is not of the same board: the ids of the cards are given by board.schede.elenca.',
    'una_posizione_o_un_ancora' => 'The body wants exactly one of posizione and dopo_scheda_id, not none and not both.',
    'lista_della_stessa_board' => 'This list is not of the same board as the list to move: the ids of the lists are given by board.board.mostra.',
    'una_posizione_o_una_lista' => 'The body wants exactly one of posizione and dopo_lista_id, not none and not both.',
    'troppe_board' => 'At most :max boards per call.',
];
