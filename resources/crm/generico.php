<?php

// Il pack «generico» del CRM come lo pubblica il backoffice (`resources/crm/pack/generico.php`, copia): il backoffice finto lo legge per
// crm.personalizzazione.*, crm.funzioni.*, crm.vocabolario.* e crm.primi-passi.*. I testi sono in generico-vocabolario.json (copia).
return [
    'chiave' => 'generico',
    'versione' => 1,
    'variante' => null,
    // Come il pack si presenta in `crm.personalizzazioni.*` (P2, #2674): nome e descrizione nelle tre lingue, le varianti che si possono
    // scegliere (`chiave` e `nome` nelle tre lingue) e cosa cambia installandolo rispetto al «generico» (il generico non cambia niente).
    'nome' => ['it' => 'Generico', 'en' => 'General', 'es' => 'General'],
    'descrizione' => [
        'it' => 'Il CRM com\'è: contatti, aziende, trattative, attività, catalogo, preventivi e scadenze, senza un settore.',
        'en' => 'The CRM as it is: contacts, companies, deals, activities, catalog, quotes and deadlines, with no industry.',
        'es' => 'El CRM tal cual: contactos, empresas, oportunidades, actividades, catálogo, presupuestos y vencimientos, sin sector.',
    ],
    'varianti' => [],
    'cambia' => ['vocabolario_chiavi' => [], 'pipeline' => [], 'campi' => [], 'catalogo_bozza' => [], 'tipi_scadenza' => []],
    // Il cliente è una persona o un'azienda: la voce «Contatti» o «Aziende» del CRM parte da qui.
    'tipo_cliente_predefinito' => 'persona',

    // Le funzioni, nell'ordine in cui si elencano. `sempre`: non si spegne mai; `richiede`: le funzioni che devono essere accese
    // perché questa lo sia; `predefinita`: lo stato di un workspace che non ha mai scelto.
    'funzioni' => [
        'clienti' => ['sempre' => true, 'richiede' => [], 'predefinita' => true],
        'pipeline' => ['sempre' => true, 'richiede' => [], 'predefinita' => true],
        'attivita' => ['sempre' => true, 'richiede' => [], 'predefinita' => true],
        'lead' => ['sempre' => false, 'richiede' => [], 'predefinita' => true],
        'catalogo' => ['sempre' => false, 'richiede' => [], 'predefinita' => true],
        'preventivi' => ['sempre' => false, 'richiede' => ['catalogo'], 'predefinita' => true],
        'acquisti' => ['sempre' => false, 'richiede' => ['catalogo'], 'predefinita' => true],
        'incassi' => ['sempre' => false, 'richiede' => ['acquisti'], 'predefinita' => true],
        'scadenze' => ['sempre' => false, 'richiede' => [], 'predefinita' => true],
        'documenti' => ['sempre' => false, 'richiede' => [], 'predefinita' => false],
        'beni' => ['sempre' => false, 'richiede' => [], 'predefinita' => false],
    ],

    // La barra, nell'ordine: la chiave della voce e la funzione che la accende (null: c'è sempre). Le chiavi sono quelle di
    // `barra.<chiave>` del vocabolario.
    'barra' => [
        'dashboard' => null,
        'potenziali_clienti' => 'pipeline',
        'clienti' => null,
        'aziende' => null,
        'da_fare' => null,
        'catalogo' => 'catalogo',
        'preventivi' => 'preventivi',
        'incassi' => 'incassi',
        'scadenze' => 'scadenze',
        'impostazioni' => null,
    ],

    // I primi passi, nell'ordine fisso del pack. `azione` è una chiave stabile che il CRM traduce in rotta; `funzione` è quella
    // che il passo prepara: se è spenta il passo non c'è, e con i suoi dati il passo è fatto.
    'passi' => [
        'primo_prodotto' => ['azione' => 'catalogo.prima_voce', 'funzione' => 'catalogo'],
        'primi_clienti' => ['azione' => 'contatti.nuovo', 'funzione' => 'clienti'],
        'primo_potenziale_cliente' => ['azione' => 'opportunita.nuova', 'funzione' => 'pipeline'],
        'prima_attivita' => ['azione' => 'da_fare.nuova', 'funzione' => 'attivita'],
    ],
];
