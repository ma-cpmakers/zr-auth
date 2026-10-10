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
    'cliente_non_riconosciuto' => [
        'title' => 'Cliente no reconocido',
        'detail' => 'No se reconoce al cliente que firmó la solicitud: revisa su nombre, su secreto y la hora del servidor, y vuelve a firmar.',
    ],
    'permesso_negato' => [
        'title' => 'Permiso denegado',
        'detail' => 'El token no permite esta operación.',
    ],
    'gettone_senza_workspace' => [
        'title' => 'Token sin workspace',
        'detail' => 'Este método trabaja con los datos de un workspace, y el token no es de un workspace: pide el token del workspace.',
    ],
    'gettone_con_workspace' => [
        'title' => 'Token con workspace',
        'detail' => 'Este método quiere el token del acceso, sin workspace, y el token es de un workspace: repite la solicitud con el token del acceso.',
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
        'detail' => 'Este método es de una aplicación que no está activa en el espacio de trabajo del token: si está disponible, la activa un propietario o un administrador.',
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
        'detail' => 'El código no verifica la solicitud: para el correo, revisa el código, el correo y la contraseña, o pide un código nuevo; para la contraseña, revisa el código y el correo, o pide un código nuevo; para entrar en una app, vuelve a empezar desde el acceso; para una invitación, revisa el código y que uses el correo al que llegó, o pide uno nuevo a quien te invitó.',
        'varianti' => [
            'email' => 'El código no verifica el correo: revisa el código, el correo y la contraseña, o pide un código nuevo.',
            'password' => 'El código no restablece la contraseña: revisa el código y el correo, o pide un código nuevo.',
            'ingresso' => 'La entrada en la app falla: el código ya no vale, vuelve a empezar desde el acceso.',
            'invito' => 'La invitación no vale: revisa el código y usa el correo al que llegó, o pide una nueva a quien te invitó.',
            'registrazione' => 'La invitación no vale para este correo: revisa el código y usa el correo al que llegó, o pide una nueva.',
            'provider' => 'El acceso con el proveedor falla: vuelve a empezar desde el botón del proveedor.',
        ],
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
        'detail' => 'Esta aplicación todavía no se puede activar ni desactivar: solo algunas de las aplicaciones están disponibles.',
    ],
    'cartella_non_vuota' => [
        'title' => 'Carpeta no vacía',
        'detail' => 'Esta carpeta todavía tiene tableros, y solo se puede eliminar vacía: mueve o quita antes sus tableros.',
    ],
    'proprietario_intoccabile' => [
        'title' => 'El propietario no se toca',
        'detail' => 'Un workspace tiene un solo propietario y ningún método lo quita ni le cambia el rol: no repitas la solicitud.',
    ],
    'gia_membro' => [
        'title' => 'Ya es miembro',
        'detail' => 'La persona ya es miembro de este workspace: no repitas la solicitud. La encuentras entre los miembros del workspace.',
    ],
    'invito_esistente' => [
        'title' => 'Invitación ya enviada',
        'detail' => 'Este correo ya tiene una invitación vigente en este workspace: espera a que caduque, o revócala desde las invitaciones pendientes y envía una nueva.',
    ],
    'posizione_cambiata' => [
        'title' => 'Posición cambiada',
        'detail' => 'La tarjeta dada como ancla ya no está donde la solicitud esperaba: vuelve a leer la lista y repite el movimiento.',
    ],
    'scheda_archiviata' => [
        'title' => 'Tarjeta archivada',
        'detail' => 'Esta tarjeta está archivada: restáurala antes de modificarla.',
    ],
    'lista_archiviata' => [
        'title' => 'Lista archivada',
        'detail' => 'Esta lista está archivada: restáurala antes de modificarla o de modificar sus tarjetas.',
    ],
    'board_chiusa' => [
        'title' => 'Tablero no activo',
        'detail' => 'Este tablero está cerrado o en la papelera: vuelve a activarlo antes de modificar sus listas, sus tarjetas o sus etiquetas.',
    ],
    'limite_raggiunto' => [
        'title' => 'Límite alcanzado',
        'detail' => 'El recurso ya tiene el máximo permitido: 100 tableros activos (300 entre activos y cerrados, 1000 con los de la papelera) y 100 carpetas por workspace, 50 listas por tablero, 500 tarjetas por lista, 3 etiquetas por tarjeta, 100 elementos por lista de comprobación, 50 miembros por workspace (contando las invitaciones vigentes), 10 vínculos de salida y 50 de entrada por tarjeta (contando también las tarjetas completadas o archivadas; `limite` y `direzione` dicen cuál). Libera un lugar (cierra un tablero o, si tienes 1000 en total con la papelera, espera a que uno de la papelera pase al archivo; archiva una lista o una tarjeta, quita una etiqueta, un elemento o un vínculo, revoca una invitación) y repite.',
    ],
    'lista_al_limite' => [
        'title' => 'Lista en su límite',
        'detail' => 'Esta lista bloquea las tarjetas nuevas: ya tiene tantas tarjetas no archivadas como indica `limite` (el valor viene en la respuesta). Pon la tarjeta en otra lista, archiva o mueve una tarjeta de esta, o sube el `limite` o desactiva `blocca` con board.liste.modifica, y repite.',
    ],
    'passaggio_non_consentito' => [
        'title' => 'Paso no permitido',
        'detail' => 'La lista de origen solo deja mover sus tarjetas a algunas listas, y esta no es una de ellas (las listas permitidas vienen en la respuesta, en `liste_consentite`). Elige una de esas, o cambia los `passaggi` de la lista de origen con board.liste.modifica o apaga `applica_regole` del tablero con board.board.modifica, y repite.',
    ],
    'attese_aperte' => [
        'title' => 'Esperas aún abiertas',
        'detail' => 'Esta lista solo acepta tarjetas que no esperan a otras tarjetas aún abiertas, y esta espera a alguna (las primeras vienen en la respuesta, en `schede`). Completa o archiva las tarjetas esperadas, o quita las esperas con board.schede.collegamenti.elimina o el requisito `senza_attese` de la lista, y repite.',
    ],
    'checklist_incompleta' => [
        'title' => 'Lista de comprobación incompleta',
        'detail' => 'Esta lista solo acepta tarjetas con la lista de comprobación completa, y esta aún tiene elementos por marcar (cuántos, en la respuesta, en `voci_aperte`). Marca o elimina los elementos, o quita el requisito `checklist_completa` de la lista con board.liste.modifica, y repite.',
    ],
    'collegamento_esistente' => [
        'title' => 'Vínculo ya existente',
        'detail' => 'La tarjeta ya espera a esa tarjeta: no repitas la solicitud. El vínculo lo encuentras en board.schede.collegamenti.elenca.',
    ],
    'collegamento_circolare' => [
        'title' => 'Vínculo circular',
        'detail' => 'Una tarjeta no puede esperarse a sí misma, ni esperar a una tarjeta que a su vez la espera, directamente o a través de otras: elige otra tarjeta, o quita antes el vínculo que cierra el ciclo.',
    ],
    'catena_troppo_lunga' => [
        'title' => 'Cadena de esperas demasiado larga',
        'detail' => 'Este vínculo alargaría más de 20 vínculos la cadena de tarjetas que se esperan una a otra, o la cadena es demasiado grande para comprobarse: acorta la cadena quitando un vínculo y repite.',
    ],
    'transizione_non_valida' => [
        'title' => 'Transición de estado no válida',
        'detail' => 'El estado pedido no se alcanza desde el estado en que está ahora el recurso: en un tablero, de activo se pasa a cerrado, de cerrado a activo o a papelera, y de la papelera a cerrado.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursor caducado',
        'detail' => 'Los eventos después de este punto ya no se pueden leer: vuelve a leerlo todo y empieza de nuevo desde el último evento.',
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
