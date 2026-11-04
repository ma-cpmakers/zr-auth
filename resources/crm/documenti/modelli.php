<?php

// I modelli di documento del CRM (P4, CRM-10, #2923, zr-crm): dato del repo, uguale per ogni workspace, non una tabella. Sono i quattro
// del pack «personal trainer» (VT-02): iscrizione, liberatoria, consenso alle immagini e privacy con dati sanitari. Una VERSIONE PUBBLICATA
// NON SI TOCCA MAI (un documento firmato ne tiene l'impronta): una modifica del testo è una versione nuova in coda a `versioni`, e
// `ModelliDeiDocumentiTest` ne tiene le impronte. I segnaposto sono quattro e solo quattro (`{{nome}}`, `{{cognome}}`, `{{data}}`,
// `{{palestra}}`): mai il valore di un campo «tuo» né di uno sensibile. Il testo legale lo valida Luciano (zr-crm D1): finché non lo ha
// fatto, le versioni qui sono quelle di partenza. `id` è un ULID minuscolo come ogni id di /v1, stabile e in ordine, perché è la chiave del cursore.
return [
    'iscrizione' => [
        'id' => '01m6k20r3tzxbqtq6g9enc165q',
        'nome' => ['it' => 'Iscrizione', 'en' => 'Enrolment', 'es' => 'Inscripción'],
        'revocabile' => false,
        'versioni' => [
            1 => [
                'it' => 'Io sottoscritto/a {{nome}} {{cognome}} chiedo di iscrivermi a {{palestra}} e dichiaro di aver letto e accettato il regolamento interno, di conoscere le modalità di frequenza e i costi dei servizi che sceglierò, e di impegnarmi a comunicare ogni variazione dei miei dati. Il presente documento è firmato il {{data}}.',
                'en' => 'I, {{nome}} {{cognome}}, ask to enrol at {{palestra}} and declare that I have read and accepted the house rules, that I know how attendance works and what the services I choose cost, and that I will report any change to my details. This document is signed on {{data}}.',
                'es' => 'Yo, {{nome}} {{cognome}}, solicito inscribirme en {{palestra}} y declaro que he leído y aceptado el reglamento interno, que conozco las condiciones de asistencia y el coste de los servicios que elija, y que comunicaré cualquier cambio en mis datos. Este documento se firma el {{data}}.',
            ],
        ],
    ],
    'liberatoria' => [
        'id' => '01m6k20r3z8hdvwps9ptgzqrap',
        'nome' => ['it' => 'Liberatoria', 'en' => 'Release of liability', 'es' => 'Exoneración de responsabilidad'],
        'revocabile' => false,
        'versioni' => [
            1 => [
                'it' => "Io sottoscritto/a {{nome}} {{cognome}} dichiaro di essere in buono stato di salute e di svolgere l'attività proposta da {{palestra}} sotto la mia responsabilità. Sono consapevole dei rischi dell'attività fisica, seguirò le indicazioni degli istruttori e sollevo {{palestra}} da ogni responsabilità per danni che non dipendano da sua colpa. Il presente documento è firmato il {{data}}.",
                'en' => "I, {{nome}} {{cognome}}, declare that I am in good health and that I take part in the activity offered by {{palestra}} at my own responsibility. I am aware of the risks of physical activity, I will follow the instructors' directions, and I release {{palestra}} from liability for damage that is not its fault. This document is signed on {{data}}.",
                'es' => 'Yo, {{nome}} {{cognome}}, declaro que gozo de buena salud y que practico la actividad propuesta por {{palestra}} bajo mi responsabilidad. Soy consciente de los riesgos de la actividad física, seguiré las indicaciones de los instructores y eximo a {{palestra}} de responsabilidad por los daños que no se deban a su culpa. Este documento se firma el {{data}}.',
            ],
        ],
    ],
    'consenso_immagini' => [
        'id' => '01m6k20r455rjb32z1wh8hhqt7',
        'nome' => ['it' => 'Consenso alle immagini', 'en' => 'Image consent', 'es' => 'Consentimiento de imágenes'],
        'revocabile' => true,
        'versioni' => [
            1 => [
                'it' => 'Io sottoscritto/a {{nome}} {{cognome}} acconsento a che {{palestra}} realizzi e utilizzi fotografie e video che mi ritraggono durante le attività, per la comunicazione dei propri servizi. Il consenso è facoltativo e posso revocarlo in qualsiasi momento, senza conseguenze sulla mia iscrizione. Il presente documento è firmato il {{data}}.',
                'en' => 'I, {{nome}} {{cognome}}, consent to {{palestra}} taking and using photographs and videos of me during its activities to promote its services. Consent is optional and I can withdraw it at any time, with no effect on my membership. This document is signed on {{data}}.',
                'es' => 'Yo, {{nome}} {{cognome}}, consiento que {{palestra}} realice y utilice fotografías y vídeos en los que aparezca durante las actividades, para dar a conocer sus servicios. El consentimiento es voluntario y puedo retirarlo en cualquier momento, sin consecuencias para mi inscripción. Este documento se firma el {{data}}.',
            ],
        ],
    ],
    'privacy_sanitaria' => [
        'id' => '01m6k20r4atazpgeqj5qee0tds',
        'nome' => ['it' => 'Privacy con dati sanitari', 'en' => 'Privacy notice with health data', 'es' => 'Privacidad con datos de salud'],
        'revocabile' => false,
        'versioni' => [
            1 => [
                'it' => "Io sottoscritto/a {{nome}} {{cognome}} dichiaro di aver ricevuto l'informativa sul trattamento dei miei dati personali e acconsento a che {{palestra}} tratti anche i miei dati relativi alla salute (come il certificato medico e le indicazioni sul mio stato fisico), al solo scopo di proporre attività adatte a me e di tutelare la mia sicurezza. Questi dati sono visibili solo a chi ne ha titolo nella struttura. Il presente documento è firmato il {{data}}.",
                'en' => 'I, {{nome}} {{cognome}}, declare that I have received the notice on how my personal data is processed and I consent to {{palestra}} also processing my health data (such as the medical certificate and notes on my physical condition), only to offer activities suited to me and to protect my safety. This data is visible only to those entitled to see it at the facility. This document is signed on {{data}}.',
                'es' => 'Yo, {{nome}} {{cognome}}, declaro que he recibido la información sobre el tratamiento de mis datos personales y consiento que {{palestra}} trate también mis datos de salud (como el certificado médico y las indicaciones sobre mi estado físico), únicamente para proponerme actividades adecuadas y proteger mi seguridad. Estos datos solo los ve quien tiene derecho a ello en el centro. Este documento se firma el {{data}}.',
            ],
        ],
    ],
];
