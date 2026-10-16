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
    'cliente_non_riconosciuto' => [
        'title' => 'Client non riconosciuto',
        'detail' => "Il client che ha firmato la richiesta non si riconosce: controlla il nome, il segreto e l'ora del server, e firma di nuovo.",
    ],
    'permesso_negato' => [
        'title' => 'Permesso negato',
        'detail' => 'Il gettone non permette questa operazione.',
    ],
    'gettone_senza_workspace' => [
        'title' => 'Gettone senza workspace',
        'detail' => 'Questo metodo lavora sui dati di un workspace, e il gettone non è di un workspace: chiedi il gettone del workspace.',
    ],
    'gettone_con_workspace' => [
        'title' => 'Gettone con workspace',
        'detail' => "Questo metodo vuole il gettone dell'accesso, senza workspace, e il gettone è di un workspace: ripeti la richiesta col gettone dell'accesso.",
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
        'detail' => "Questo metodo è di un'app che non è attiva nel workspace del gettone: se è disponibile, la attiva un proprietario o un amministratore.",
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
        'detail' => "Il codice non verifica la richiesta: per l'email controlla il codice, l'email e la password, o chiedi un codice nuovo; per la password controlla il codice e l'email, o chiedi un codice nuovo; per l'ingresso in un'app riparti dall'accesso; per un invito controlla il codice e usa l'email a cui è arrivato, o chiedine uno nuovo a chi ti ha invitato.",
        'varianti' => [
            'email' => 'Il codice non verifica l\'email: controlla il codice, l\'email e la password, o chiedi un codice nuovo.',
            'password' => 'Il codice non reimposta la password: controlla il codice e l\'email, o chiedi un codice nuovo.',
            'ingresso' => 'L\'ingresso nell\'app non riesce: il codice non vale più, riparti dall\'accesso.',
            'invito' => 'L\'invito non vale: controlla il codice e usa l\'email a cui è arrivato, o chiedine uno nuovo a chi ti ha invitato.',
            'registrazione' => 'L\'invito non vale per questa email: controlla il codice e usa l\'email a cui è arrivato, o chiedine uno nuovo.',
            'link' => 'Il link non vale: controlla di averlo copiato intero, o chiedine uno nuovo a chi ti ha invitato.',
            'provider' => 'L\'accesso con il provider non riesce: riparti dal bottone del provider.',
        ],
    ],
    'email_del_provider_non_verificata' => [
        'title' => 'Email non verificata dal provider',
        'detail' => 'Il provider di accesso non garantisce che questa email sia verificata: verificala presso il provider, o accedi con un altro metodo.',
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
        'detail' => 'Questa app non si può ancora attivare né disattivare: solo alcune delle app sono disponibili.',
    ],
    'cartella_non_vuota' => [
        'title' => 'Cartella non vuota',
        'detail' => 'Questa cartella ha ancora delle board, e si elimina solo vuota: sposta o togli prima le sue board.',
    ],
    'proprietario_intoccabile' => [
        'title' => 'Il proprietario non si tocca',
        'detail' => 'Il proprietario del workspace è uno solo e nessun metodo lo toglie né ne cambia il ruolo: non ripetere la richiesta.',
    ],
    'gia_membro' => [
        'title' => 'Già membro',
        'detail' => 'La persona è già membro di questo workspace: non ripetere la richiesta. La trovi fra i membri del workspace.',
    ],
    'invito_esistente' => [
        'title' => 'Invito già inviato',
        'detail' => 'Questa email ha già un invito vivo in questo workspace: aspetta che scada, o revocalo dagli inviti in attesa e inviane uno nuovo.',
    ],
    'posizione_cambiata' => [
        'title' => 'Posizione cambiata',
        'detail' => "L'elemento dato come ancora (una scheda, una fase, una trattativa) non è più dove la richiesta pensava: rileggi la lista e ripeti lo spostamento.",
    ],
    'scheda_archiviata' => [
        'title' => 'Scheda archiviata',
        'detail' => 'Questa scheda è archiviata: ripristinala prima di cambiarla.',
    ],
    'lista_archiviata' => [
        'title' => 'Lista archiviata',
        'detail' => 'Questa lista è archiviata: ripristinala prima di cambiarla o di cambiare le sue schede.',
    ],
    'board_chiusa' => [
        'title' => 'Board non attiva',
        'detail' => 'Questa board è chiusa o nel cestino: riportala allo stato attivo prima di cambiare le sue liste, le sue schede o le sue etichette.',
    ],
    'limite_raggiunto' => [
        'title' => 'Limite raggiunto',
        'detail' => 'La risorsa ha già il massimo consentito: 100 board attive (300 tra attive e chiuse, 1000 con quelle nel cestino) e 100 cartelle per workspace, 50 liste per board, 500 schede per lista, 3 etichette per scheda, 100 voci per checklist, 50 membri per workspace (contando gli inviti vivi), 10 attese in uscita e 50 in entrata per scheda (contando anche le schede completate o archiviate; `limite` e `direzione` dicono quale). Libera un posto (chiudi una board o, se ne hai 1000 in tutto con il cestino, aspetta che una del cestino passi all\'archivio; archivia una lista o una scheda, togli un\'etichetta, una voce o un\'attesa, revoca un invito) e ripeti.',
    ],
    'lista_al_limite' => [
        'title' => 'Lista al limite',
        'detail' => 'Questa lista blocca le schede nuove: ha già il numero di schede non archiviate che `limite` indica (il valore è nella risposta). Metti la scheda in un\'altra lista, archivia o sposta una scheda di questa, oppure alza il `limite` o spegni `blocca` con board.liste.modifica, e ripeti.',
    ],
    'passaggio_non_consentito' => [
        'title' => 'Passaggio non consentito',
        'detail' => 'La lista di partenza consente di spostare le sue schede solo in alcune liste, e questa non è fra quelle (le liste consentite sono nella risposta, in `liste_consentite`). Scegli una di quelle, oppure cambia i `passaggi` della lista di partenza con board.liste.modifica o spegni `applica_regole` della board con board.board.modifica, e ripeti.',
    ],
    'attese_aperte' => [
        'title' => 'Attese ancora aperte',
        'detail' => 'Questa lista accetta solo schede che non aspettano altre schede ancora aperte, e questa ne aspetta (le prime sono nella risposta, in `schede`). Completa o archivia le schede aspettate, oppure togli le attese con board.schede.collegamenti.elimina o il requisito `senza_attese` dalla lista, e ripeti.',
    ],
    'checklist_incompleta' => [
        'title' => 'Checklist incompleta',
        'detail' => 'Questa lista accetta solo schede con la checklist completa, e questa ha ancora voci da spuntare (quante, nella risposta, in `voci_aperte`). Spunta o elimina le voci, oppure togli il requisito `checklist_completa` dalla lista con board.liste.modifica, e ripeti.',
    ],
    'email_gia_presente' => [
        'title' => 'Email già presente',
        'detail' => 'Questa email appartiene già a un altro contatto del workspace, anche archiviato: il suo id è nella risposta, in `persona_id`. Aprilo con crm.persone.mostra, oppure usa un\'altra email.',
    ],
    'persona_anonimizzata' => [
        'title' => 'Persona anonimizzata',
        'detail' => "Questo contatto è stato anonimizzato (diritto all'oblio): non si modifica e non si collega più a note, attività o trattative. Il suo id resta nelle risorse che già lo citano.",
    ],
    'annullamento_scaduto' => [
        'title' => 'Annullamento scaduto',
        'detail' => 'Questa importazione è di più di 24 ore fa e non si annulla più: niente è cambiato. Le aziende o i contatti che ha creato si archiviano uno per uno con la loro modifica.',
    ],
    'pipeline_non_vuota' => [
        'title' => 'Pipeline non vuota',
        'detail' => 'Questa pipeline ha ancora trattative aperte e si archivia solo senza: chiudi o sposta prima le sue trattative, poi ripeti.',
    ],
    'fase_non_vuota' => [
        'title' => 'Fase non vuota',
        'detail' => "Questa fase ha ancora trattative (aperte, vinte, perse o archiviate) e si elimina solo senza: sposta le sue trattative in un'altra fase (una chiusa o archiviata si riapre o si ripristina prima con crm.trattative.modifica), poi ripeti.",
    ],
    'campo_di_sistema' => [
        'title' => 'Campo di sistema',
        'detail' => 'Questo campo è uno standard o di sistema: si nasconde e si sposta, ma non si elimina. Solo un campo «tuo», creato dal workspace, si elimina. Il metodo non ha scritto niente.',
    ],
    'ultima_opzione' => [
        'title' => 'Ultima opzione',
        'detail' => 'Questa è l\'ultima opzione viva del campo, e un campo a elenco o a scelta multipla ne ha sempre almeno una. Aggiungine un\'altra con crm.campi.opzioni.crea e poi togli questa. Il metodo non ha scritto niente.',
    ],
    'sezione_di_sistema' => [
        'title' => 'Sezione di sistema',
        'detail' => '«Principali» è la sezione fissa dei campi di un oggetto: non si rinomina, non si sposta e non si elimina. Il metodo non ha scritto niente.',
    ],
    'sezione_non_vuota' => [
        'title' => 'Sezione non vuota',
        'detail' => "Questa sezione ha ancora campi: si elimina solo senza. Sposta i suoi campi in un'altra sezione con crm.campi.modifica, poi ripeti.",
    ],
    'ultimo_workspace' => [
        'title' => 'Ultimo workspace',
        'detail' => "Questo è l'ultimo workspace vivo della persona che chiama, e non si elimina: una persona ha sempre almeno un workspace. Creane un altro con io.workspace.crea, o annulla l'eliminazione dell'altro con workspace.eliminazione.elimina. Il metodo non ha scritto niente.",
    ],
    'ultima_fase' => [
        'title' => 'Ultima fase',
        'detail' => 'Questa è l\'ultima fase della pipeline e non si elimina: una pipeline ha sempre almeno una fase. Aggiungine un\'altra con crm.fasi.crea, o archivia la pipeline con crm.pipeline.modifica.',
    ],
    'valuta_in_uso' => [
        'title' => 'Valuta in uso',
        'detail' => 'In questo workspace ci sono trattative con un importo (aperte, vinte, perse o archiviate) nella valuta di adesso. Cambiare la valuta non converte gli importi: restano gli stessi numeri. Per cambiarla comunque manda `senza_conversione: true` insieme a `valuta`.',
    ],
    'accettazione_richiesta' => [
        'title' => 'Accettazione richiesta',
        'detail' => 'Per usare Zeiras devi accettare l\'informativa sulla privacy e i termini d\'uso nella versione in vigore. Il problema porta accanto al codice `mancanti`, i documenti da accettare: accettali con legale.accettazioni.crea (li elenca legale.documenti.elenca) e ripeti la richiesta.',
    ],
    'conferma_richiesta' => [
        'title' => 'Conferma richiesta',
        'detail' => 'Questa azione vuole che tu confermi di essere tu: dai di nuovo la tua password con accessi.conferma.crea (vale dieci minuti su questo accesso) e ripeti la richiesta.',
    ],
    'precondizione_fallita' => [
        'title' => 'Precondizione fallita',
        'detail' => "La risorsa non è più quella che hai letto: qualcun altro l'ha modificata dopo la tua lettura (o l'`ETag` in `If-Match` non è il suo). Non è stato scritto niente. Rileggila (il suo metodo `mostra` dà l'`ETag` aggiornato), controlla cosa è cambiato e, se vuoi ancora la tua modifica, ripeti la richiesta con il nuovo `ETag`.",
    ],
    'collegamento_esistente' => [
        'title' => 'Collegamento già presente',
        'detail' => 'La scheda aspetta già quella scheda: non ripetere la richiesta. Il collegamento lo trovi in board.schede.collegamenti.elenca.',
    ],
    'collegamento_circolare' => [
        'title' => 'Collegamento circolare',
        'detail' => 'Una scheda non può aspettare sé stessa, né una scheda che a sua volta la aspetta, direttamente o attraverso altre: scegli un\'altra scheda, o togli prima l\'attesa che chiude il giro.',
    ],
    'catena_troppo_lunga' => [
        'title' => 'Catena di attese troppo lunga',
        'detail' => 'Questo collegamento allungherebbe oltre 20 collegamenti la catena di schede che si aspettano una dopo l\'altra, o la catena è troppo grande per essere controllata: accorcia la catena togliendo un collegamento, e ripeti.',
    ],
    'transizione_non_valida' => [
        'title' => 'Transizione di stato non valida',
        'detail' => 'Lo stato chiesto non si raggiunge da quello in cui la risorsa sta ora: per una board, da attiva si passa a chiusa, da chiusa ad attiva o a cestino, dal cestino a chiusa. Una trattativa si sposta fra le fasi solo se è aperta e non archiviata.',
    ],
    'cursore_scaduto' => [
        'title' => 'Cursore scaduto',
        'detail' => "Gli eventi dopo questo punto non si leggono più: rileggi tutto e riparti dall'ultimo evento.",
    ],
    'versione_scaduta' => [
        'title' => 'Versione scaduta',
        'detail' => 'La versione è più vecchia di 30 giorni: le schede cambiate da allora non si danno più a pezzi. Rileggi la board da capo e riparti dalla versione che ti dà.',
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
