<?php

// Il pack «personal_trainer» del CRM (P2, #2680, zr-crm #2673): dato scritto da zr-crm, `docs/contratti/zr-crm-pack-personal-trainer.json`,
// blob ce8677e5f5745865812c8c37bbe065581aa919ce del commit ab0bac40 di zr-crm. I testi del vocabolario stanno in personal_trainer-vocabolario.json
// (78 chiavi in it/en/es, le stesse del generico). Le voci del catalogo, i tipi di scadenza, i motivi e le pipeline si scrivono nel workspace
// all'installazione; `calendario_bookings` e `nota_provvisorio` del file sono solo indicazioni e qui non entrano.
$nome = 'personal_trainer';
$pipeline0 = 'potenziali_clienti';
$pipeline1 = 'personal_training';

return [
    'chiave' => $nome,
    'versione' => 1,
    'variante' => null,
    'nome' => [
        'it' => 'Personal trainer',
        'en' => 'Personal trainer',
        'es' => 'Entrenador personal',
    ],
    'descrizione' => [
        'it' => 'Il CRM di un personal trainer o di uno studio: dalla prova gratuita al primo abbonamento, con lezioni, certificati e rinnovi sotto controllo.',
        'en' => 'The CRM of a personal trainer or studio: from the free trial to the first membership, with sessions, certificates and renewals under control.',
        'es' => 'El CRM de un entrenador personal o de un estudio: de la prueba gratuita al primer abono, con clases, certificados y renovaciones bajo control.',
    ],
    'varianti' => [],
    'tipo_cliente_predefinito' => 'persona',
    'funzioni' => [
        'clienti' => [
            'sempre' => true,
            'richiede' => [],
            'predefinita' => true,
        ],
        'pipeline' => [
            'sempre' => true,
            'richiede' => [],
            'predefinita' => true,
        ],
        'attivita' => [
            'sempre' => true,
            'richiede' => [],
            'predefinita' => true,
        ],
        'lead' => [
            'sempre' => false,
            'richiede' => [],
            'predefinita' => true,
        ],
        'catalogo' => [
            'sempre' => false,
            'richiede' => [],
            'predefinita' => true,
        ],
        'preventivi' => [
            'sempre' => false,
            'richiede' => ['catalogo'],
            'predefinita' => false,
        ],
        'acquisti' => [
            'sempre' => false,
            'richiede' => ['catalogo'],
            'predefinita' => true,
        ],
        'incassi' => [
            'sempre' => false,
            'richiede' => ['acquisti'],
            'predefinita' => true,
        ],
        'scadenze' => [
            'sempre' => false,
            'richiede' => [],
            'predefinita' => true,
        ],
        'documenti' => [
            'sempre' => false,
            'richiede' => [],
            'predefinita' => true,
        ],
        'beni' => [
            'sempre' => false,
            'richiede' => [],
            'predefinita' => false,
        ],
    ],
    'barra' => [
        'dashboard' => null,
        'potenziali_clienti' => 'pipeline',
        'clienti' => null,
        'da_fare' => null,
        'catalogo' => 'catalogo',
    ],
    'passi' => [
        'primo_prodotto' => [
            'azione' => 'catalogo.prima_voce',
            'funzione' => 'catalogo',
        ],
        'primi_clienti' => [
            'azione' => 'contatti.nuovo',
            'funzione' => 'clienti',
        ],
        'primo_potenziale_cliente' => [
            'azione' => 'opportunita.nuova',
            'funzione' => 'pipeline',
        ],
        'prima_attivita' => [
            'azione' => 'da_fare.nuova',
            'funzione' => 'attivita',
        ],
    ],
    'pipeline' => [
        [
            'chiave' => $pipeline0,
            'nome' => [
                'it' => 'Potenziali clienti',
                'en' => 'Prospects',
                'es' => 'Clientes potenciales',
            ],
            'predefinita' => true,
            'fasi' => [
                [
                    'nome' => [
                        'it' => 'Lead',
                        'en' => 'Lead',
                        'es' => 'Lead',
                    ],
                    'probabilita' => 10,
                ],
                [
                    'nome' => [
                        'it' => 'Prova prenotata',
                        'en' => 'Trial booked',
                        'es' => 'Prueba reservada',
                    ],
                    'probabilita' => 30,
                ],
                [
                    'nome' => [
                        'it' => 'Prova fatta',
                        'en' => 'Trial done',
                        'es' => 'Prueba realizada',
                    ],
                    'probabilita' => 60,
                ],
                [
                    'nome' => [
                        'it' => 'Proposta',
                        'en' => 'Proposal',
                        'es' => 'Propuesta',
                    ],
                    'probabilita' => 75,
                ],
            ],
            'esiti' => [
                'vinta' => [
                    'it' => 'Cliente',
                    'en' => 'Client',
                    'es' => 'Cliente',
                ],
                'persa' => [
                    'it' => 'Perso',
                    'en' => 'Lost',
                    'es' => 'Perdido',
                ],
            ],
        ],
        [
            'chiave' => $pipeline1,
            'nome' => [
                'it' => 'Personal training',
                'en' => 'Personal training',
                'es' => 'Personal training',
            ],
            'predefinita' => false,
            'fasi' => [
                [
                    'nome' => [
                        'it' => 'Richiesta',
                        'en' => 'Enquiry',
                        'es' => 'Solicitud',
                    ],
                    'probabilita' => 20,
                ],
                [
                    'nome' => [
                        'it' => 'Valutazione iniziale',
                        'en' => 'Initial assessment',
                        'es' => 'Valoración inicial',
                    ],
                    'probabilita' => 50,
                ],
                [
                    'nome' => [
                        'it' => 'Pacchetto proposto',
                        'en' => 'Package offered',
                        'es' => 'Paquete propuesto',
                    ],
                    'probabilita' => 70,
                ],
            ],
            'esiti' => [
                'vinta' => [
                    'it' => 'Acquistato',
                    'en' => 'Purchased',
                    'es' => 'Comprado',
                ],
                'persa' => [
                    'it' => 'Perso',
                    'en' => 'Lost',
                    'es' => 'Perdido',
                ],
            ],
        ],
    ],
    'motivi_perdita' => [
        [
            'it' => 'Prezzo',
            'en' => 'Price',
            'es' => 'Precio',
        ],
        [
            'it' => 'Distanza',
            'en' => 'Distance',
            'es' => 'Distancia',
        ],
        [
            'it' => 'Orari',
            'en' => 'Schedule',
            'es' => 'Horarios',
        ],
        [
            'it' => 'Non si è presentato alla prova',
            'en' => 'No-show at the trial',
            'es' => 'No se presentó a la prueba',
        ],
        [
            'it' => 'Ha scelto un altro personal trainer',
            'en' => 'Chose another personal trainer',
            'es' => 'Eligió a otro entrenador personal',
        ],
    ],
    'tipi_scadenza' => [
        [
            'nome' => [
                'it' => 'Certificato medico',
                'en' => 'Medical certificate',
                'es' => 'Certificado médico',
            ],
            'preavvisi' => [30, 7],
            'ricorrenza_mesi' => 12,
            'sensibile' => true,
        ],
        [
            'nome' => [
                'it' => 'Fine abbonamento',
                'en' => 'Membership end',
                'es' => 'Fin del abono',
            ],
            'preavvisi' => [30, 7],
            'ricorrenza_mesi' => null,
            'sensibile' => false,
        ],
        [
            'nome' => [
                'it' => 'Tesseramento',
                'en' => 'Club registration',
                'es' => 'Afiliación',
            ],
            'preavvisi' => [30, 7],
            'ricorrenza_mesi' => 12,
            'sensibile' => false,
        ],
        [
            'nome' => [
                'it' => 'Pacchetto in scadenza',
                'en' => 'Package expiring',
                'es' => 'Paquete a punto de vencer',
            ],
            'preavvisi' => [14, 7],
            'ricorrenza_mesi' => null,
            'sensibile' => false,
        ],
    ],
    'catalogo' => [
        [
            'nome' => [
                'it' => 'Abbonamento mensile',
                'en' => 'Monthly membership',
                'es' => 'Abono mensual',
            ],
            'tipo' => 'abbonamento',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
            'rinnovo' => 'mensile',
        ],
        [
            'nome' => [
                'it' => 'Abbonamento trimestrale',
                'en' => 'Quarterly membership',
                'es' => 'Abono trimestral',
            ],
            'tipo' => 'abbonamento',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
            'rinnovo' => 'trimestrale',
        ],
        [
            'nome' => [
                'it' => 'Abbonamento annuale',
                'en' => 'Annual membership',
                'es' => 'Abono anual',
            ],
            'tipo' => 'abbonamento',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
            'rinnovo' => 'annuale',
        ],
        [
            'nome' => [
                'it' => 'Lezione singola',
                'en' => 'Single session',
                'es' => 'Clase suelta',
            ],
            'tipo' => 'servizio',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
            'durata_minuti' => 60,
        ],
        [
            'nome' => [
                'it' => 'Pacchetto Yoga 5 lezioni',
                'en' => 'Yoga package of 5 sessions',
                'es' => 'Paquete de yoga de 5 clases',
            ],
            'tipo' => 'pacchetto',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
            'durata_minuti' => 75,
            'unita_incluse' => 5,
            'etichetta_unita' => [
                'it' => 'lezioni',
                'en' => 'sessions',
                'es' => 'clases',
            ],
            'validita_mesi' => 2,
        ],
        [
            'nome' => [
                'it' => '10 sessioni di personal training',
                'en' => '10 personal training sessions',
                'es' => '10 sesiones de entrenamiento personal',
            ],
            'tipo' => 'pacchetto',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
            'durata_minuti' => 60,
            'unita_incluse' => 10,
            'etichetta_unita' => [
                'it' => 'sessioni',
                'en' => 'sessions',
                'es' => 'sesiones',
            ],
        ],
        [
            'nome' => [
                'it' => 'Corso di gruppo',
                'en' => 'Group course',
                'es' => 'Curso en grupo',
            ],
            'tipo' => 'servizio',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => true,
        ],
        [
            'nome' => [
                'it' => 'Quota di tesseramento annuale',
                'en' => 'Annual registration fee',
                'es' => 'Cuota de afiliación anual',
            ],
            'tipo' => 'prodotto',
            'stato' => 'bozza',
            'prezzo' => null,
            'iva_inclusa' => false,
            'categoria' => [
                'it' => 'Quote associative',
                'en' => 'Membership fees',
                'es' => 'Cuotas de socio',
            ],
        ],
        [
            'nome' => [
                'it' => 'Lezione di prova',
                'en' => 'Trial session',
                'es' => 'Clase de prueba',
            ],
            'tipo' => 'servizio',
            'stato' => 'bozza',
            'prezzo' => [
                'importo' => 0,
                'valuta' => 'EUR',
            ],
            'iva_inclusa' => true,
            'durata_minuti' => 60,
        ],
    ],
];
