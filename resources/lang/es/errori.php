<?php

// Los textos de los errores de /v1 en español, por código: las mismas claves que lang/it/errori.php
// (tests/Unit/TraduzioniTest.php). Los demás textos que el español no tiene salen en inglés (config/lingue.php).

return [
    'richiesta_non_valida' => [
        'title' => 'Solicitud no válida',
        'detail' => 'La solicitud está mal formada: revisa el método, la ruta, las cabeceras y el cuerpo.',
    ],
    'gettone_assente' => [
        'title' => 'Falta el token',
        'detail' => 'La solicitud no lleva un token: envíalo en la cabecera Authorization, como Bearer.',
    ],
    'gettone_non_valido' => [
        'title' => 'Token no válido',
        'detail' => 'El token de la solicitud no es válido.',
    ],
    'permesso_negato' => [
        'title' => 'Permiso denegado',
        'detail' => 'El token no permite esta operación.',
    ],
    'gettone_senza_workspace' => [
        'title' => 'Token sin workspace',
        'detail' => 'Este método trabaja con los datos de un workspace, y el token no es de un workspace: pide el token del workspace con gettoni.crea.',
    ],
    'gettone_con_workspace' => [
        'title' => 'Token con workspace',
        'detail' => 'Este método quiere el token del acceso, sin workspace, y el token es de un workspace: repite la solicitud con el token que te dio accessi.crea.',
    ],
    'registrazione_non_aperta' => [
        'title' => 'Registro no abierto',
        'detail' => 'El registro de Zeiras aún no está abierto a todos, y este correo no está entre los que pueden registrarse.',
    ],
    'email_non_verificata' => [
        'title' => 'Correo no verificado',
        'detail' => 'Primero hay que verificar el correo: verifícalo con el código que llegó por correo y repite la solicitud.',
    ],
    'app_non_attiva' => [
        'title' => 'Aplicación no activa',
        'detail' => 'Este método es de una aplicación que no está activa en el espacio de trabajo del token: si está disponible, la activa un propietario o un administrador, con app.modifica.',
    ],
    'percorso_inesistente' => [
        'title' => 'Ruta inexistente',
        'detail' => 'La ruta no es la de ningún método de /v1.',
    ],
    'non_trovato' => [
        'title' => 'Recurso no encontrado',
        'detail' => 'El recurso indicado en la ruta no existe o no se puede ver con el token.',
    ],
    'metodo_non_ammesso' => [
        'title' => 'Método no permitido',
        'detail' => 'La ruta no acepta este método HTTP: los permitidos están en la cabecera Allow.',
    ],
    'verifica_non_riuscita' => [
        'title' => 'Verificación fallida',
        'detail' => 'El código no verifica el correo: revisa el código, el correo y la contraseña, o pide un código nuevo.',
    ],
    'turnstile_non_valido' => [
        'title' => 'Comprobación de Turnstile no superada',
        'detail' => 'La comprobación de Turnstile no se ha superado: pide a la persona que la repita y vuelve a intentarlo con la respuesta nueva.',
    ],
    'richiesta_in_corso' => [
        'title' => 'Solicitud en curso',
        'detail' => 'Una solicitud con la misma Idempotency-Key sigue en curso: espera unos segundos y repítela igual.',
    ],
    'app_in_arrivo' => [
        'title' => 'Aplicación próximamente',
        'detail' => 'Esta aplicación todavía no se puede activar ni desactivar: app.elenca indica cuáles están disponibles.',
    ],
    'cartella_non_vuota' => [
        'title' => 'Carpeta no vacía',
        'detail' => 'Esta carpeta todavía tiene tableros, y solo se puede eliminar vacía: board.board.elenca indica cuáles son.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursor caducado',
        'detail' => 'Los eventos después de este punto ya no se pueden leer: vuelve a leerlo todo y empieza de nuevo desde el último evento, con eventi.ultimo.mostra.',
    ],
    'corpo_troppo_grande' => [
        'title' => 'Cuerpo demasiado grande',
        'detail' => 'El cuerpo de la solicitud supera el tamaño máximo aceptado.',
    ],
    'dati_non_validi' => [
        'title' => 'Datos no válidos',
        'detail' => 'Algunos valores no son válidos: los encuentras en errors, con lo que falla y dónde están.',
    ],
    'credenziali_non_valide' => [
        'title' => 'Credenciales no válidas',
        'detail' => 'El correo electrónico o la contraseña no son correctos.',
    ],
    'chiave_idempotenza_riusata' => [
        'title' => 'Clave de idempotencia reutilizada',
        'detail' => 'Esta Idempotency-Key ya se usó con un cuerpo distinto: para una solicitud nueva usa una clave nueva.',
    ],
    'troppe_richieste' => [
        'title' => 'Demasiadas solicitudes',
        // Una scelta plurale sui secondi di Retry-After, che RendeProblemi riempie (T7.1, D19).
        'detail' => '{0} Demasiadas solicitudes en poco tiempo: vuelve a intentarlo ahora.|{1} Demasiadas solicitudes en poco tiempo: vuelve a intentarlo en :secondi segundo.|[2,*] Demasiadas solicitudes en poco tiempo: vuelve a intentarlo en :secondi segundos.',
    ],
    'errore_interno' => [
        'title' => 'Error interno',
        'detail' => 'Se ha producido un error inesperado y la solicitud no se ha completado: vuelve a intentarlo más tarde.',
    ],
    'servizio_non_disponibile' => [
        'title' => 'Servicio no disponible',
        'detail' => 'El servicio no está disponible temporalmente: vuelve a intentarlo más tarde.',
    ],
    'turnstile_non_disponibile' => [
        'title' => 'Comprobación de Turnstile no disponible',
        'detail' => 'La comprobación de Turnstile no se puede hacer ahora: repítela y vuelve a intentarlo dentro de poco.',
    ],
];
