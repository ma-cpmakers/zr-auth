<?php

// The messages of the validation rules written here (app/Rules), in English: the same keys as lang/it/regole.php.

return [
    'cursore' => 'The :attribute field is not a cursor of this list: use the successivo value of the previous page.',
    'chiave_idempotenza' => 'The Idempotency-Key header needs 1 to 255 visible ASCII characters, without spaces.',
    'workspace_non_tuo' => 'You are not a member of a workspace with this id: the id is given by io.workspace.crea, when the workspace is born.',
    'sequenza' => 'The :attribute field needs the sequence of an event: 1 to 12 digits.',
    'carattere_nullo' => 'The :attribute field cannot contain the null character (U+0000).',
    'almeno_un_campo' => 'The body needs at least one of these fields: :campi.',
];
