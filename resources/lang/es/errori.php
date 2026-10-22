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
            'link' => 'El enlace no vale: comprueba que lo has copiado entero, o pide uno nuevo a quien te invitó.',
            'provider' => 'El acceso con el proveedor falla: vuelve a empezar desde el botón del proveedor.',
        ],
    ],
    'email_del_provider_non_verificata' => [
        'title' => 'Correo no verificado por el proveedor',
        'detail' => 'El proveedor de acceso no garantiza que este correo esté verificado: verifícalo con el proveedor o accede de otra forma.',
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
        'detail' => 'El elemento dado como ancla (una tarjeta, una fase, una negociación) ya no está donde la solicitud esperaba: vuelve a leer la lista y repite el movimiento.',
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
    'esportazione_in_corso' => [
        'title' => 'Exportación en curso',
        'detail' => 'El workspace ya tiene una exportación en preparación: espera a que esté lista (se sigue con workspace.esportazioni.mostra) y descárgala, luego pide otra.',
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
    'email_gia_presente' => [
        'title' => 'Correo ya presente',
        'detail' => 'Este correo ya pertenece a otro contacto del workspace, incluso archivado: su id está en la respuesta, en `persona_id`. Ábrelo con crm.persone.mostra, o usa otro correo.',
    ],
    'persona_anonimizzata' => [
        'title' => 'Contacto anonimizado',
        'detail' => 'Este contacto ha sido anonimizado (derecho al olvido): ya no se puede modificar ni vincular a notas, actividades o negociaciones. Su id permanece en los recursos que ya lo citan.',
    ],
    'annullamento_scaduto' => [
        'title' => 'Anulación caducada',
        'detail' => 'Esta importación es de hace más de 24 horas y ya no se puede anular: nada ha cambiado. Las empresas o los contactos que creó se archivan uno a uno con su modificación.',
    ],
    'pipeline_non_vuota' => [
        'title' => 'Pipeline no vacío',
        'detail' => 'Este pipeline aún tiene negociaciones abiertas y solo se archiva sin ellas: cierra o mueve antes sus negociaciones y vuelve a intentarlo.',
    ],
    'fase_non_vuota' => [
        'title' => 'Fase no vacía',
        'detail' => 'Esta fase aún tiene negociaciones (abiertas, ganadas, perdidas o archivadas) y solo se elimina sin ellas: mueve sus negociaciones a otra fase (una cerrada o archivada se reabre o se restaura antes con crm.trattative.modifica) y vuelve a intentarlo.',
    ],
    'campo_di_sistema' => [
        'title' => 'Campo del sistema',
        'detail' => 'Este campo es estándar o del sistema: se oculta y se mueve, pero no se elimina. Solo se elimina un campo «tuyo», creado por el espacio de trabajo. No se escribió nada.',
    ],
    'crediti_esauriti' => [
        'title' => 'Créditos agotados',
        'detail' => 'Los créditos disponibles de esta compra no bastan para la cantidad pedida, y un crédito nunca baja de cero. No se escribió nada: vuelve a leer la compra con crm.acquisti.mostra y pide como máximo los créditos disponibles.',
    ],
    'acquisto_scaduto' => [
        'title' => 'Compra caducada',
        'detail' => 'Esta compra ha caducado, y no se registra un consumo sobre una compra caducada. No se escribió nada.',
    ],
    'rata_pagata' => [
        'title' => 'Cuota ya pagada',
        'detail' => 'Esta cuota ya está pagada por completo y no tiene nada que cobrar. No se escribió nada: léela de nuevo con crm.rate.mostra.',
    ],
    'rate_con_pagamenti' => [
        'title' => 'Cuotas con pagos',
        'detail' => 'Esta compra ya tiene un pago no anulado y su plan no se puede sustituir. Anula antes los pagos con crm.pagamenti.annullamento.crea y repite. No se escribió nada.',
    ],
    'pagamento_annullato' => [
        'title' => 'Pago ya anulado',
        'detail' => 'Este pago ya está anulado y no se anula dos veces. No se escribió nada.',
    ],
    'ultima_opzione' => [
        'title' => 'Última opción',
        'detail' => 'Esta es la última opción viva del campo, y un campo de lista o de selección múltiple siempre tiene al menos una. Añade otra con crm.campi.opzioni.crea y luego quita esta. No se escribió nada.',
    ],
    'sezione_di_sistema' => [
        'title' => 'Sección del sistema',
        'detail' => '«Principali» (Principales) es la sección fija de los campos de un objeto: no se renombra, no se mueve y no se elimina. No se escribió nada.',
    ],
    'sezione_non_vuota' => [
        'title' => 'Sección no vacía',
        'detail' => 'Esta sección aún tiene campos y solo se elimina sin ellos. Mueve sus campos a otra sección con crm.campi.modifica y vuelve a intentarlo.',
    ],
    'ultimo_workspace' => [
        'title' => 'Último workspace',
        'detail' => 'Este es el último workspace vivo de la persona que llama y no se elimina: una persona siempre tiene al menos un workspace. Crea otro con io.workspace.crea, o cancela la eliminación del otro con workspace.eliminazione.elimina. El método no escribió nada.',
    ],
    'workspace_in_eliminazione' => [
        'title' => 'Workspace en eliminación',
        'detail' => 'Este workspace tiene la eliminación programada y ya no admite invitaciones: ni nuevas, ni reenviadas, ni por el enlace, ni las ya enviadas. Pide al propietario (proprietario) del workspace que la cancele con workspace.eliminazione.elimina y vuelve a intentarlo: las invitaciones ya enviadas vuelven a valer hasta su caducidad. El método no escribió nada.',
    ],
    'ultima_fase' => [
        'title' => 'Última fase',
        'detail' => 'Esta es la última fase del pipeline y no se elimina: un pipeline siempre tiene al menos una fase. Añade otra con crm.fasi.crea, o archiva el pipeline con crm.pipeline.modifica.',
    ],
    'valuta_in_uso' => [
        'title' => 'Moneda en uso',
        'detail' => 'Este workspace tiene negociaciones con un importe (abiertas, ganadas, perdidas o archivadas) en la moneda actual. Cambiar la moneda no convierte los importes: los números siguen siendo los mismos. Para cambiarla igualmente, envía `senza_conversione: true` junto con `valuta`.',
    ],
    'accettazione_richiesta' => [
        'title' => 'Aceptación requerida',
        'detail' => 'Para usar Zeiras debes aceptar el aviso de privacidad y las condiciones de uso en su versión vigente. El problema lleva junto al código `mancanti`, los documentos por aceptar: acéptalos con legale.accettazioni.crea (los lista legale.documenti.elenca) y repite la solicitud.',
    ],
    'conferma_richiesta' => [
        'title' => 'Confirmación requerida',
        'detail' => 'Esta acción requiere que confirmes que eres tú: vuelve a dar tu contraseña con accessi.conferma.crea (vale diez minutos en este acceso) y repite la solicitud.',
    ],
    'precondizione_fallita' => [
        'title' => 'Precondición fallida',
        'detail' => 'El recurso ya no es el que leíste: alguien más lo modificó después de tu lectura (o el `ETag` de `If-Match` no es el suyo). No se escribió nada. Vuelve a leerlo (su método `mostra` da el `ETag` actual), revisa qué cambió y, si aún quieres tu cambio, repite la solicitud con el nuevo `ETag`.',
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
        'detail' => 'El estado pedido no se alcanza desde el estado en que está ahora el recurso: en un tablero, de activo se pasa a cerrado, de cerrado a activo o a papelera, y de la papelera a cerrado. Una negociación se mueve entre fases solo si está abierta y no archivada.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursor caducado',
        'detail' => 'Los eventos después de este punto ya no se pueden leer: vuelve a leerlo todo y empieza de nuevo desde el último evento.',
    ],
    'versione_scaduta' => [
        'title' => 'Versión caducada',
        'detail' => 'La versión es más antigua de 30 días: las tarjetas cambiadas desde entonces ya no se dan por partes. Vuelve a leer el tablero desde el principio y continúa desde la versión que te da.',
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
