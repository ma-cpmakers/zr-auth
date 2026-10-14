<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Le sezioni e i campi del CRM del backoffice finto (#2024, G67): `crm.sezioni.elenca`, `crea`, `modifica` ed `elimina`, e
 * `crm.campi.elenca`, `crea` (#2111: i campi «tuoi») e `modifica`, in memoria e per workspace, con le forme, i codici e le regole del backoffice
 * (`SezioniController` e `CampiController`). Il catalogo degli standard è la copia di `App\Crm\Catalogo` (persona 24 campi e 6
 * sezioni, organizzazione 16 e 4, trattativa 11 e 2): il workspace lo prende al primo metodo, e una modifica scrive sulla copia del
 * workspace, mai sul catalogo. Come nel backoffice, un metodo vuole l'app `crm` (prima il ruolo, poi l'app), il corpo si valida
 * prima di cercare la risorsa, e una scrittura che non cambia niente non porta avanti `aggiornato_il`. Il finto non emette gli
 * eventi (`com.zeiras.crm.campo.modificato` e `creato`) né tiene lo storico: lo dice il README.
 */
trait CrmCampi
{
    /** Gli oggetti del CRM che hanno campi (Catalogo::OGGETTI). */
    private const CRM_OGGETTI_DEI_CAMPI = ['persona', 'organizzazione', 'trattativa'];

    /** Le sezioni vive di un oggetto, al più (Catalogo::TETTO_SEZIONI). */
    private const CRM_TETTO_DELLE_SEZIONI = 50;

    /** I caratteri del nome di una sezione o di un campo, per lingua (Sezioni::NOME). */
    private const CRM_NOME_DI_UNA_SEZIONE = 60;

    /** I campi di un oggetto per tetto, standard e «tuoi» (Catalogo::TETTO_CAMPI). */
    private const CRM_TETTO_DEI_CAMPI = 300;

    /** I tipi di un campo «tuo» in V1 (Catalogo::TIPI_V1); `email` e `telefono` no. */
    private const CRM_TIPI_DEI_CAMPI_TUOI = ['testo', 'testo_lungo', 'numero', 'valuta', 'percentuale', 'data', 'data_ora', 'si_no', 'elenco', 'scelta_multipla', 'url', 'membro'];

    /** Le opzioni di un elenco o di una scelta multipla, al più (Catalogo::OPZIONI_MASSIME). */
    private const CRM_OPZIONI_MASSIME = 100;

    /** Il tetto di `limite` degli elenchi di sezioni e di campi, ed è anche il valore se manca (SezioniElencaRequest, CampiElencaRequest). */
    private const CRM_LIMITE_DEI_CAMPI = 300;

    /** La data dei dati del catalogo per ciò che nessuno ha toccato (Sezioni::DAL). */
    private const CRM_DAL_DEL_CATALOGO = '2026-10-13T00:00:00.000Z';

    /**
     * Le sezioni seminate di ogni oggetto, nell'ordine dell'elenco (Catalogo::SEZIONI).
     *
     * @var array<string, list<array{chiave: string, nome: array{it: string, en: string, es: string}, fissa: bool}>>
     */
    private const CRM_SEZIONI_STANDARD = [
        'persona' => [
            ['codice' => 'principali', 'nome' => ['it' => 'Principali', 'en' => 'Main', 'es' => 'Principales'], 'fissa' => true],
            ['codice' => 'lavoro', 'nome' => ['it' => 'Lavoro', 'en' => 'Work', 'es' => 'Trabajo'], 'fissa' => false],
            ['codice' => 'dati_personali', 'nome' => ['it' => 'Dati personali', 'en' => 'Personal details', 'es' => 'Datos personales'], 'fissa' => false],
            ['codice' => 'recapiti', 'nome' => ['it' => 'Recapiti', 'en' => 'Contact details', 'es' => 'Datos de contacto'], 'fissa' => false],
            ['codice' => 'consensi', 'nome' => ['it' => 'Consensi', 'en' => 'Consents', 'es' => 'Consentimientos'], 'fissa' => false],
            ['codice' => 'altro', 'nome' => ['it' => 'Altro', 'en' => 'Other', 'es' => 'Otros'], 'fissa' => false],
        ],
        'organizzazione' => [
            ['codice' => 'principali', 'nome' => ['it' => 'Principali', 'en' => 'Main', 'es' => 'Principales'], 'fissa' => true],
            ['codice' => 'dati_aziendali', 'nome' => ['it' => 'Dati aziendali', 'en' => 'Company details', 'es' => 'Datos de la empresa'], 'fissa' => false],
            ['codice' => 'recapiti', 'nome' => ['it' => 'Recapiti', 'en' => 'Contact details', 'es' => 'Datos de contacto'], 'fissa' => false],
            ['codice' => 'altro', 'nome' => ['it' => 'Altro', 'en' => 'Other', 'es' => 'Otros'], 'fissa' => false],
        ],
        'trattativa' => [
            ['codice' => 'principali', 'nome' => ['it' => 'Principali', 'en' => 'Main', 'es' => 'Principales'], 'fissa' => true],
            ['codice' => 'dettagli', 'nome' => ['it' => 'Dettagli', 'en' => 'Details', 'es' => 'Detalles'], 'fissa' => false],
        ],
    ];

    /**
     * I campi standard di ogni oggetto, nell'ordine del catalogo (Catalogo::CAMPI).
     *
     * @var array<string, list<array{chiave: string, tipo: string, sistema: ?string, nome: array{it: string, en: string, es: string}, sezione: string, visibilita: string, indispensabile: bool, in_campi: bool}>>
     */
    private const CRM_CAMPI_STANDARD = [
        'persona' => [
            ['codice' => 'nome', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Nome', 'en' => 'First name', 'es' => 'Nombre'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => true, 'in_campi' => false],
            ['codice' => 'cognome', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Cognome', 'en' => 'Last name', 'es' => 'Apellido'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => true, 'in_campi' => false],
            ['codice' => 'email', 'tipo' => 'email', 'sistema' => null, 'nome' => ['it' => 'Email', 'en' => 'Email', 'es' => 'Correo'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => true, 'in_campi' => false],
            ['codice' => 'telefoni', 'tipo' => 'telefono', 'sistema' => null, 'nome' => ['it' => 'Telefoni', 'en' => 'Phones', 'es' => 'Teléfonos'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'organizzazione_id', 'tipo' => 'sistema', 'sistema' => 'organizzazione', 'nome' => ['it' => 'Azienda', 'en' => 'Company', 'es' => 'Empresa'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'responsabile_id', 'tipo' => 'membro', 'sistema' => null, 'nome' => ['it' => 'Proprietario', 'en' => 'Owner', 'es' => 'Propietario'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'stato', 'tipo' => 'sistema', 'sistema' => 'stato', 'nome' => ['it' => 'Stato', 'en' => 'Status', 'es' => 'Estado'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'ruolo', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Ruolo', 'en' => 'Role', 'es' => 'Cargo'], 'sezione' => 'lavoro', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'reparto', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Reparto', 'en' => 'Department', 'es' => 'Departamento'], 'sezione' => 'lavoro', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'titolo', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Titolo', 'en' => 'Title', 'es' => 'Tratamiento'], 'sezione' => 'dati_personali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'secondo_nome', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Secondo nome', 'en' => 'Middle name', 'es' => 'Segundo nombre'], 'sezione' => 'dati_personali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'genere', 'tipo' => 'elenco', 'sistema' => null, 'nome' => ['it' => 'Genere', 'en' => 'Gender', 'es' => 'Género'], 'sezione' => 'dati_personali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'data_nascita', 'tipo' => 'data', 'sistema' => null, 'nome' => ['it' => 'Data di nascita', 'en' => 'Date of birth', 'es' => 'Fecha de nacimiento'], 'sezione' => 'dati_personali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'luogo_nascita', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Luogo di nascita', 'en' => 'Place of birth', 'es' => 'Lugar de nacimiento'], 'sezione' => 'dati_personali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'cittadinanza', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Cittadinanza', 'en' => 'Citizenship', 'es' => 'Nacionalidad'], 'sezione' => 'dati_personali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'lingua_preferita', 'tipo' => 'elenco', 'sistema' => null, 'nome' => ['it' => 'Lingua preferita', 'en' => 'Preferred language', 'es' => 'Idioma preferido'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'canale_preferito', 'tipo' => 'elenco', 'sistema' => null, 'nome' => ['it' => 'Canale preferito', 'en' => 'Preferred channel', 'es' => 'Canal preferido'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'sito', 'tipo' => 'url', 'sistema' => null, 'nome' => ['it' => 'Sito', 'en' => 'Website', 'es' => 'Sitio web'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'linkedin', 'tipo' => 'url', 'sistema' => null, 'nome' => ['it' => 'LinkedIn', 'en' => 'LinkedIn', 'es' => 'LinkedIn'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'consenso_marketing', 'tipo' => 'sistema', 'sistema' => 'consenso', 'nome' => ['it' => 'Consenso marketing', 'en' => 'Marketing consent', 'es' => 'Consentimiento de marketing'], 'sezione' => 'consensi', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'base_giuridica', 'tipo' => 'sistema', 'sistema' => 'base_giuridica', 'nome' => ['it' => 'Base giuridica', 'en' => 'Legal basis', 'es' => 'Base jurídica'], 'sezione' => 'consensi', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'fonte', 'tipo' => 'sistema', 'sistema' => 'fonte', 'nome' => ['it' => 'Fonte', 'en' => 'Source', 'es' => 'Origen'], 'sezione' => 'altro', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'codice_cliente', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Codice cliente', 'en' => 'Customer code', 'es' => 'Código de cliente'], 'sezione' => 'altro', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'data_primo_contatto', 'tipo' => 'data', 'sistema' => null, 'nome' => ['it' => 'Data primo contatto', 'en' => 'First contact date', 'es' => 'Fecha del primer contacto'], 'sezione' => 'altro', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
        ],
        'organizzazione' => [
            ['codice' => 'nome', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Ragione sociale', 'en' => 'Company name', 'es' => 'Razón social'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => true, 'in_campi' => false],
            ['codice' => 'sito', 'tipo' => 'url', 'sistema' => null, 'nome' => ['it' => 'Sito', 'en' => 'Website', 'es' => 'Sitio web'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'telefono', 'tipo' => 'telefono', 'sistema' => null, 'nome' => ['it' => 'Telefono', 'en' => 'Phone', 'es' => 'Teléfono'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'indirizzo', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Indirizzo', 'en' => 'Address', 'es' => 'Dirección'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'responsabile_id', 'tipo' => 'membro', 'sistema' => null, 'nome' => ['it' => 'Proprietario', 'en' => 'Owner', 'es' => 'Propietario'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'stato', 'tipo' => 'sistema', 'sistema' => 'stato', 'nome' => ['it' => 'Stato', 'en' => 'Status', 'es' => 'Estado'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'nome_commerciale', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Nome commerciale', 'en' => 'Trade name', 'es' => 'Nombre comercial'], 'sezione' => 'dati_aziendali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'forma_giuridica', 'tipo' => 'elenco', 'sistema' => null, 'nome' => ['it' => 'Forma giuridica', 'en' => 'Legal form', 'es' => 'Forma jurídica'], 'sezione' => 'dati_aziendali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'codice_ateco', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Codice ATECO', 'en' => 'Industry code (ATECO)', 'es' => 'Código ATECO'], 'sezione' => 'dati_aziendali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'numero_dipendenti', 'tipo' => 'numero', 'sistema' => null, 'nome' => ['it' => 'Numero dipendenti', 'en' => 'Employees', 'es' => 'Número de empleados'], 'sezione' => 'dati_aziendali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'fascia_fatturato', 'tipo' => 'elenco', 'sistema' => null, 'nome' => ['it' => 'Fascia di fatturato', 'en' => 'Revenue range', 'es' => 'Rango de facturación'], 'sezione' => 'dati_aziendali', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'email', 'tipo' => 'email', 'sistema' => null, 'nome' => ['it' => 'Email', 'en' => 'Email', 'es' => 'Correo'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'pec', 'tipo' => 'email', 'sistema' => null, 'nome' => ['it' => 'PEC', 'en' => 'Certified email (PEC)', 'es' => 'PEC'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'codice_sdi', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Codice SDI', 'en' => 'SDI code', 'es' => 'Código SDI'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'linkedin', 'tipo' => 'url', 'sistema' => null, 'nome' => ['it' => 'LinkedIn', 'en' => 'LinkedIn', 'es' => 'LinkedIn'], 'sezione' => 'recapiti', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'codice_cliente', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Codice cliente', 'en' => 'Customer code', 'es' => 'Código de cliente'], 'sezione' => 'altro', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
        ],
        'trattativa' => [
            ['codice' => 'titolo', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Titolo', 'en' => 'Title', 'es' => 'Título'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => true, 'in_campi' => false],
            ['codice' => 'persona_id', 'tipo' => 'sistema', 'sistema' => 'persona', 'nome' => ['it' => 'Contatto', 'en' => 'Contact', 'es' => 'Contacto'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'organizzazione_id', 'tipo' => 'sistema', 'sistema' => 'organizzazione', 'nome' => ['it' => 'Azienda', 'en' => 'Company', 'es' => 'Empresa'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'valore', 'tipo' => 'valuta', 'sistema' => null, 'nome' => ['it' => 'Valore', 'en' => 'Value', 'es' => 'Valor'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'fase_id', 'tipo' => 'sistema', 'sistema' => 'fase', 'nome' => ['it' => 'Fase', 'en' => 'Stage', 'es' => 'Fase'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'chiusura_prevista', 'tipo' => 'data', 'sistema' => null, 'nome' => ['it' => 'Chiusura prevista', 'en' => 'Expected close', 'es' => 'Cierre previsto'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'responsabile_id', 'tipo' => 'membro', 'sistema' => null, 'nome' => ['it' => 'Proprietario', 'en' => 'Owner', 'es' => 'Propietario'], 'sezione' => 'principali', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'probabilita', 'tipo' => 'percentuale', 'sistema' => null, 'nome' => ['it' => 'Probabilità', 'en' => 'Probability', 'es' => 'Probabilidad'], 'sezione' => 'dettagli', 'visibilita' => 'visibile', 'indispensabile' => false, 'in_campi' => false],
            ['codice' => 'campagna', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Campagna', 'en' => 'Campaign', 'es' => 'Campaña'], 'sezione' => 'dettagli', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'concorrente', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Concorrente', 'en' => 'Competitor', 'es' => 'Competidor'], 'sezione' => 'dettagli', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
            ['codice' => 'prossimo_passo', 'tipo' => 'testo', 'sistema' => null, 'nome' => ['it' => 'Prossimo passo', 'en' => 'Next step', 'es' => 'Próximo paso'], 'sezione' => 'dettagli', 'visibilita' => 'nascosto', 'indispensabile' => false, 'in_campi' => true],
        ],
    ];

    /**
     * Le sezioni e i campi di ogni workspace, per oggetto: le sezioni per id, i campi per chiave. Una riga porta ciò che il workspace
     * ha adesso (`scritti` sono i nomi scritti dal workspace, il resto viene dal catalogo) e gli istanti.
     *
     * @var array<string, array<string, array{sezioni: array<string, array<string, mixed>>, campi: array<string, array<string, mixed>>}>>
     */
    private array $campiCrm = [];

    // ———————————————————————————————————————— Sezioni

    /**
     * crm.sezioni.elenca (SezioniController::elenca): le sezioni vive di un oggetto per posto, «Principali» la prima; `oggetto`
     * obbligatorio, `limite` da 1 a 300 (300 se manca) e `cursore`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaSezioniCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $this->campiDelWorkspaceCrm($chi['workspace']);

        return $this->paginaDeiCampiCrm($richiesta, 'crm.sezioni.elenca', function (string $oggetto) use ($chi): array {
            $vive = $this->sezioniViveCrm($chi['workspace'], $oggetto);

            return array_map(fn (array $riga, int $i) => $this->voceSezioneCrm($riga, $i + 1), $vive, array_keys($vive));
        });
    }

    /**
     * crm.sezioni.crea (SezioniController::crea): in fondo all'elenco dell'oggetto; il corpo, poi il tetto di 50 (409
     * `limite_raggiunto`) e il nome `it` unico senza maiuscole né accenti (422 `duplicato` su `#/nome/it`).
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaSezioneCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.sezioni.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'oggetto' => ['required', 'string', Rule::in(self::CRM_OGGETTI_DEI_CAMPI)],
                'nome' => ['required', 'array'],
                'nome.it' => ['bail', 'required', 'string', 'max:'.self::CRM_NOME_DI_UNA_SEZIONE, new NomeScritto],
                'nome.en' => $this->regoleDiUnNomeCrm(),
                'nome.es' => $this->regoleDiUnNomeCrm(),
            ]);
            $this->campiDelWorkspaceCrm($chi['workspace']);
            $vive = $this->sezioniViveCrm($chi['workspace'], $campi['oggetto']);

            if (count($vive) >= self::CRM_TETTO_DELLE_SEZIONI) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTO_DELLE_SEZIONI]);
            }

            $this->sezioneUnicaCrm($vive, $campi['nome']['it'], null);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->campiCrm[$chi['workspace']][$campi['oggetto']]['sezioni'][$id] = [
                'id' => $id, 'oggetto' => $campi['oggetto'], 'codice' => null, 'catalogo' => [],
                'scritti' => array_filter($campi['nome'], fn ($nome) => is_string($nome) && $nome !== ''),
                'ord' => $vive[array_key_last($vive)]['ord'] + self::CRM_PASSO, 'fissa' => false,
                'creato_il' => $ora, 'aggiornato_il' => $ora, 'eliminata' => false,
            ];

            return [201, ['data' => $this->voceSezioneCrm($this->campiCrm[$chi['workspace']][$campi['oggetto']]['sezioni'][$id], count($vive) + 1)], ['Location' => "/v1/crm/sezioni/{$id}"]];
        });
    }

    /**
     * crm.sezioni.modifica (SezioniController::modifica): JSON Merge Patch di `nome` (`null` torna al catalogo, o toglie la lingua di
     * una sezione creata; `it` di una creata non si toglie) e `posizione` (da 2: il primo posto è di «Principali»). «Principali» non
     * cambia (409 `sezione_di_sistema`). Il corpo si valida prima di cercare la sezione.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaSezioneCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $campi = $this->modificaCrm($corpo, [
            'nome' => ['filled', 'array'],
            'nome.it' => $this->regoleDiUnNomeCrm(),
            'nome.en' => $this->regoleDiUnNomeCrm(),
            'nome.es' => $this->regoleDiUnNomeCrm(),
            'posizione' => ['filled', 'integer:strict', 'min:1'],
        ]);
        $this->campiDelWorkspaceCrm($chi['workspace']);
        [$riga, $lista] = $this->trovaSezioneCrm($chi['workspace'], $id);

        if ($riga['fissa']) {
            throw new Problema('sezione_di_sistema');
        }

        $nome = $this->nomiNuoviCrm($riga['catalogo'], $riga['scritti'], $campi['nome'] ?? [], $riga['codice'] === null);

        if ($nome['it'] !== self::nomiCrm($riga['catalogo'], $riga['scritti'])['it']) {
            $this->sezioneUnicaCrm($lista, $nome['it'], $riga['id']);
        }

        $ordine = array_values(array_filter($lista, fn (array $altra) => $altra['id'] !== $riga['id']));

        if (array_key_exists('posizione', $campi)) {
            if ($campi['posizione'] < 2) {
                throw $this->erroreCrm('posizione', 'regole.sezione_posizione_prima', Regola::FUORI_INTERVALLO);
            }

            array_splice($ordine, min($campi['posizione'], count($ordine) + 1) - 1, 0, [$riga]);
        } else {
            array_splice($ordine, (int) array_search($riga['id'], array_column($lista, 'id'), true), 0, [$riga]);
        }

        $sposta = array_column($ordine, 'id') !== array_column($lista, 'id');

        foreach ($ordine as $i => $altra) {
            $ord = $sposta ? ($i + 1) * self::CRM_PASSO : $altra['ord'];
            $nomeCambiato = $altra['id'] === $riga['id'] && ! self::scrittiUgualiCrm($nome['riga'], $altra['scritti']);

            if ($altra['ord'] === $ord && ! $nomeCambiato) {
                continue;
            }

            $scritta = $this->campiCrm[$chi['workspace']][$altra['oggetto']]['sezioni'][$altra['id']];
            $scritta['scritti'] = $nomeCambiato ? $nome['riga'] : $scritta['scritti'];
            $scritta['ord'] = $ord;
            $scritta['aggiornato_il'] = self::dopoCrm($scritta['aggiornato_il']);
            $this->campiCrm[$chi['workspace']][$altra['oggetto']]['sezioni'][$altra['id']] = $scritta;
        }

        [$nuova, , $posto] = $this->trovaSezioneCrm($chi['workspace'], $riga['id']);

        return [200, ['data' => $this->voceSezioneCrm($nuova, $posto)]];
    }

    /**
     * crm.sezioni.elimina (SezioniController::elimina): la sezione sparisce (404 da lì, il nome torna libero); «Principali» è 409
     * `sezione_di_sistema`, una sezione con campi vivi 409 `sezione_non_vuota`.
     *
     * @return array{int, null}
     */
    private function eliminaSezioneCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $this->campiDelWorkspaceCrm($chi['workspace']);
        [$riga] = $this->trovaSezioneCrm($chi['workspace'], $id);

        if ($riga['fissa']) {
            throw new Problema('sezione_di_sistema');
        }

        if (array_filter($this->campiCrm[$chi['workspace']][$riga['oggetto']]['campi'], fn (array $campo) => $campo['sezione_id'] === $riga['id']) !== []) {
            throw new Problema('sezione_non_vuota');
        }

        $this->campiCrm[$chi['workspace']][$riga['oggetto']]['sezioni'][$riga['id']]['eliminata'] = true;

        return [204, null];
    }

    // ———————————————————————————————————————— Campi

    /**
     * crm.campi.elenca (CampiController::elenca): i campi di un oggetto, sezione per sezione e per posto; stessi parametri di
     * `crm.sezioni.elenca`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCampiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $this->campiDelWorkspaceCrm($chi['workspace']);

        return $this->paginaDeiCampiCrm($richiesta, 'crm.campi.elenca', fn (string $oggetto): array => array_map(
            fn (array $campo) => $this->voceCampoCrm($campo),
            $this->campiDiUnOggettoCrm($chi['workspace'], $oggetto),
        ));
    }

    /**
     * crm.campi.crea (CampiController::crea): un campo «tuo» in fondo alla sezione chiesta (o a «Principali»); il corpo, poi il tetto
     * di 300 per oggetto (409 `limite_raggiunto`), il nome `it` unico senza maiuscole né accenti (422 `duplicato` su `#/nome/it`) e la
     * sezione. La chiave nasce dal nome (minuscole, senza accenti, `_`, al più 40; `_2`, `_3` se è presa) e non cambia. Elenco e
     * scelta multipla vogliono da 1 a 100 opzioni, ognuna con un `id`; gli altri tipi nessuna.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaCampoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.campi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'oggetto' => ['required', 'string', Rule::in(self::CRM_OGGETTI_DEI_CAMPI)],
                'nome' => ['required', 'array'],
                'nome.it' => ['bail', 'required', 'string', 'max:'.self::CRM_NOME_DI_UNA_SEZIONE, new NomeScritto],
                'nome.en' => $this->regoleDiUnNomeCrm(),
                'nome.es' => $this->regoleDiUnNomeCrm(),
                'tipo' => ['required', 'string', Rule::in(self::CRM_TIPI_DEI_CAMPI_TUOI)],
                'sezione_id' => ['filled', 'string', 'max:40'],
                'visibilita' => ['filled', 'string', Rule::in(['visibile', 'nascosto'])],
                'obbligatorio' => ['filled', 'boolean:strict'],
                'opzioni' => ['sometimes', 'array', 'max:'.self::CRM_OPZIONI_MASSIME],
                'opzioni.*' => ['array'],
                'opzioni.*.nome' => ['required', 'array'],
                'opzioni.*.nome.it' => ['bail', 'required', 'string', 'max:'.self::CRM_NOME_DI_UNA_SEZIONE, new NomeScritto],
                'opzioni.*.nome.en' => $this->regoleDiUnNomeCrm(),
                'opzioni.*.nome.es' => $this->regoleDiUnNomeCrm(),
                'opzioni.*.colore' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ]);
            $opzioni = $this->opzioniDelCampoCrm($campi);
            $oggetto = $campi['oggetto'];
            $this->campiDelWorkspaceCrm($chi['workspace']);
            $lista = $this->campiDiUnOggettoCrm($chi['workspace'], $oggetto);

            if (count($lista) >= self::CRM_TETTO_DEI_CAMPI) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTO_DEI_CAMPI]);
            }

            foreach ($lista as $altro) {
                if (self::nomeSimileCrm($altro['nome']['it'], null) === self::nomeSimileCrm($campi['nome']['it'], null)) {
                    throw $this->erroreCrm('nome.it', 'regole.campo_nome_duplicato', Regola::DUPLICATO);
                }
            }

            $sezione = $campi['sezione_id'] ?? "{$oggetto}.principali";

            if (! in_array($sezione, array_column($this->sezioniViveCrm($chi['workspace'], $oggetto), 'id'), true)) {
                throw $this->erroreCrm('sezione_id', 'regole.sezione_non_esiste', Regola::NON_TROVATO);
            }

            $chiave = $this->chiaveDelCampoCrm($campi['nome']['it'], array_keys($this->campiCrm[$chi['workspace']][$oggetto]['campi']));
            $ordini = array_column(array_filter($lista, fn (array $altro) => $altro['sezione_id'] === $sezione), 'ord');
            $ora = self::adessoCrm();
            $this->campiCrm[$chi['workspace']][$oggetto]['campi'][$chiave] = [
                'id' => "{$oggetto}.{$chiave}", 'oggetto' => $oggetto, 'codice' => $chiave, 'origine' => 'tuo', 'tipo' => $campi['tipo'], 'sistema' => null,
                'opzioni' => $opzioni, 'obbligatorio' => $campi['obbligatorio'] ?? false,
                'catalogo' => ['nome' => []], 'scritti' => array_filter($campi['nome'], fn ($nome) => is_string($nome) && $nome !== ''),
                'visibilita' => $campi['visibilita'] ?? 'visibile', 'sezione_id' => $sezione,
                'ord' => ($ordini === [] ? 0 : max($ordini)) + self::CRM_PASSO, 'indispensabile' => false, 'in_campi' => true,
                'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
            [$nuovo] = $this->trovaCampoCrm($chi['workspace'], "{$oggetto}.{$chiave}");

            return [201, ['data' => $this->voceCampoCrm($nuovo)], ['Location' => "/v1/crm/campi/{$oggetto}.{$chiave}"]];
        });
    }

    /**
     * Le opzioni di un elenco o di una scelta multipla come si scrivono: un `id` ciascuna, i nomi nelle tre lingue e il colore minuscolo.
     * Un elenco senza opzioni, o un altro tipo con le opzioni, è 422; due opzioni con lo stesso nome sono 422 `duplicato` sulla seconda.
     *
     * @param  array<string, mixed>  $campi
     * @return list<array<string, mixed>>|null
     */
    private function opzioniDelCampoCrm(array $campi): ?array
    {
        $date = $campi['opzioni'] ?? null;

        if (! in_array($campi['tipo'], ['elenco', 'scelta_multipla'], true)) {
            if ($date !== null) {
                throw $this->erroreCrm('opzioni', 'regole.campo_opzioni_non_ammesse', Regola::CAMPO_VIETATO);
            }

            return null;
        }

        if (! is_array($date) || $date === []) {
            throw $this->erroreCrm('opzioni', 'regole.campo_opzioni_obbligatorie', Regola::OBBLIGATORIO);
        }

        $viste = [];
        $opzioni = [];

        foreach (array_values($date) as $i => $opzione) {
            $chiave = self::nomeSimileCrm($opzione['nome']['it'], null);

            if (isset($viste[$chiave])) {
                throw $this->erroreCrm("opzioni.{$i}.nome.it", 'regole.campo_opzione_duplicata', Regola::DUPLICATO);
            }

            $viste[$chiave] = true;
            $opzioni[] = [
                'id' => self::id(),
                'nome' => ['it' => $opzione['nome']['it'], 'en' => $opzione['nome']['en'] ?? null, 'es' => $opzione['nome']['es'] ?? null],
                'colore' => isset($opzione['colore']) ? strtolower($opzione['colore']) : null,
            ];
        }

        return $opzioni;
    }

    /**
     * La chiave di un campo nuovo dal nome `it`: minuscole, senza accenti, `_` al posto del resto, al più 40 caratteri (`campo` se non
     * resta niente); `_2`, `_3`… se l'oggetto la ha già.
     *
     * @param  list<int|string>  $occupate
     */
    private function chiaveDelCampoCrm(string $nome, array $occupate): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii($nome))), '_');
        $base = $base === '' ? 'campo' : rtrim(substr($base, 0, 40), '_');
        $occupate = array_map('strval', $occupate);

        for ($n = 1, $chiave = $base; in_array($chiave, $occupate, true); $n++) {
            $suffisso = '_'.($n + 1);
            $chiave = rtrim(substr($base, 0, 40 - strlen($suffisso)), '_').$suffisso;
        }

        return $chiave;
    }

    /**
     * crm.campi.modifica (CampiController::modifica): JSON Merge Patch di `nome` (`null` torna al catalogo), `visibilita`,
     * `sezione_id` e `posizione` (da 1, nella sezione di arrivo; senza, un campo che cambia sezione va in fondo). Un campo
     * indispensabile non si nasconde e non lascia «Principali» (422 `campo_indispensabile`). Il corpo si valida prima di cercare il campo.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaCampoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $campi = $this->modificaCrm($corpo, [
            'nome' => ['filled', 'array'],
            'nome.it' => $this->regoleDiUnNomeCrm(),
            'nome.en' => $this->regoleDiUnNomeCrm(),
            'nome.es' => $this->regoleDiUnNomeCrm(),
            'visibilita' => ['filled', 'string', Rule::in(['visibile', 'nascosto'])],
            'sezione_id' => ['filled', 'string', 'max:40'],
            'posizione' => ['filled', 'integer:strict', 'min:1'],
        ]);
        $this->campiDelWorkspaceCrm($chi['workspace']);
        [$voce, $lista] = $this->trovaCampoCrm($chi['workspace'], $id);
        $oggetto = $voce['oggetto'];
        $cambiati = [];
        $nome = $this->nomiNuoviCrm($voce['catalogo']['nome'], $voce['scritti'], $campi['nome'] ?? [], $voce['origine'] === 'tuo');

        if (! self::scrittiUgualiCrm($nome['riga'], $voce['scritti'])) {
            $cambiati[] = 'nome';

            if ($nome['it'] !== $voce['nome']['it']) {
                foreach ($lista as $altro) {
                    if ($altro['id'] !== $voce['id'] && self::nomeSimileCrm($altro['nome']['it'], null) === self::nomeSimileCrm($nome['it'], null)) {
                        throw $this->erroreCrm('nome.it', 'regole.campo_nome_duplicato', Regola::DUPLICATO);
                    }
                }
            }
        }

        $visibilita = $campi['visibilita'] ?? $voce['visibilita'];

        if ($visibilita !== $voce['visibilita']) {
            if ($visibilita === 'nascosto' && $voce['indispensabile']) {
                throw $this->erroreCrm('visibilita', 'regole.campo_indispensabile_nascosto', Regola::CAMPO_INDISPENSABILE);
            }

            $cambiati[] = 'visibilita';
        }

        $sezione = $campi['sezione_id'] ?? $voce['sezione_id'];

        if (array_key_exists('sezione_id', $campi)) {
            if (! in_array($sezione, array_column($this->sezioniViveCrm($chi['workspace'], $oggetto), 'id'), true)) {
                throw $this->erroreCrm('sezione_id', 'regole.sezione_non_esiste', Regola::NON_TROVATO);
            }

            if ($voce['indispensabile'] && $sezione !== "{$oggetto}.principali") {
                throw $this->erroreCrm('sezione_id', 'regole.campo_indispensabile_sezione', Regola::CAMPO_INDISPENSABILE);
            }
        }

        $cambiaSezione = $sezione !== $voce['sezione_id'];
        $altri = array_values(array_filter($lista, fn (array $altro) => $altro['sezione_id'] === $sezione && $altro['id'] !== $voce['id']));
        $posto = array_key_exists('posizione', $campi) ? min($campi['posizione'], count($altri) + 1) : ($cambiaSezione ? count($altri) + 1 : $voce['posto']);
        $siSposta = $cambiaSezione || $posto !== $voce['posto'];

        if ($cambiaSezione) {
            $cambiati[] = 'sezione_id';
        }

        if ($siSposta) {
            $cambiati[] = 'posizione';
        }

        if ($cambiati === []) {
            return [200, ['data' => $this->voceCampoCrm($voce)]];
        }

        $ordine = $altri;
        array_splice($ordine, $posto - 1, 0, [$voce]);
        $riga = $this->campiCrm[$chi['workspace']][$oggetto]['campi'][$voce['codice']];
        $riga['scritti'] = $nome['riga'];
        $riga['visibilita'] = $visibilita;
        $riga['sezione_id'] = $sezione;
        $riga['aggiornato_il'] = self::dopoCrm($riga['aggiornato_il']);

        if ($siSposta) {
            $riga['ord'] = ((int) array_search($voce['id'], array_column($ordine, 'id'), true) + 1) * self::CRM_PASSO;

            foreach ($ordine as $i => $altro) {
                if ($altro['id'] !== $voce['id'] && $altro['ord'] !== ($i + 1) * self::CRM_PASSO) {
                    $this->campiCrm[$chi['workspace']][$oggetto]['campi'][$altro['codice']]['ord'] = ($i + 1) * self::CRM_PASSO;
                }
            }
        }

        $this->campiCrm[$chi['workspace']][$oggetto]['campi'][$voce['codice']] = $riga;
        [$nuovo] = $this->trovaCampoCrm($chi['workspace'], $voce['id']);

        return [200, ['data' => $this->voceCampoCrm($nuovo)]];
    }

    // ———————————————————————————————————————— Lo stato

    /** Il catalogo nel workspace, al primo metodo: ogni sezione e ogni campo con i valori del catalogo e la data dei suoi dati. */
    private function campiDelWorkspaceCrm(string $workspace): void
    {
        if (isset($this->campiCrm[$workspace])) {
            return;
        }

        $dal = CarbonImmutable::parse(self::CRM_DAL_DEL_CATALOGO);

        foreach (self::CRM_SEZIONI_STANDARD as $oggetto => $sezioni) {
            $this->campiCrm[$workspace][$oggetto] = ['sezioni' => [], 'campi' => []];

            foreach ($sezioni as $i => $sezione) {
                $id = "{$oggetto}.{$sezione['codice']}";
                $this->campiCrm[$workspace][$oggetto]['sezioni'][$id] = [
                    'id' => $id, 'oggetto' => $oggetto, 'codice' => $sezione['codice'], 'catalogo' => $sezione['nome'], 'scritti' => [],
                    'ord' => ($i + 1) * self::CRM_PASSO, 'fissa' => $sezione['fissa'], 'creato_il' => $dal, 'aggiornato_il' => $dal, 'eliminata' => false,
                ];
            }

            $inSezione = [];

            foreach (self::CRM_CAMPI_STANDARD[$oggetto] as $campo) {
                $sezione = "{$oggetto}.{$campo['sezione']}";
                $inSezione[$sezione] = ($inSezione[$sezione] ?? 0) + self::CRM_PASSO;
                $this->campiCrm[$workspace][$oggetto]['campi'][$campo['codice']] = [
                    'id' => "{$oggetto}.{$campo['codice']}", 'oggetto' => $oggetto, 'codice' => $campo['codice'], 'origine' => 'standard', 'tipo' => $campo['tipo'], 'sistema' => $campo['sistema'],
                    'opzioni' => null, 'obbligatorio' => false,
                    'catalogo' => ['nome' => $campo['nome']], 'scritti' => [], 'visibilita' => $campo['visibilita'], 'sezione_id' => $sezione,
                    'ord' => $inSezione[$sezione], 'indispensabile' => $campo['indispensabile'], 'in_campi' => $campo['in_campi'],
                    'creato_il' => $dal, 'aggiornato_il' => $dal,
                ];
            }
        }
    }

    /**
     * Le sezioni vive di un oggetto, nell'ordine dell'elenco: «Principali» la prima, poi per posto e per id.
     *
     * @return list<array<string, mixed>>
     */
    private function sezioniViveCrm(string $workspace, string $oggetto): array
    {
        $vive = array_values(array_filter($this->campiCrm[$workspace][$oggetto]['sezioni'], fn (array $riga) => ! $riga['eliminata']));
        usort($vive, fn (array $a, array $b) => [$b['fissa'], $a['ord'], $a['id']] <=> [$a['fissa'], $b['ord'], $b['id']]);

        return $vive;
    }

    /**
     * I campi di un oggetto, sezione per sezione (nell'ordine delle sezioni vive), per posto e per chiave, ognuno col suo posto da 1
     * nella sezione e il nome che ha adesso.
     *
     * @return list<array<string, mixed>>
     */
    private function campiDiUnOggettoCrm(string $workspace, string $oggetto): array
    {
        $ordineDelleSezioni = array_flip(array_column($this->sezioniViveCrm($workspace, $oggetto), 'id'));
        $campi = array_values($this->campiCrm[$workspace][$oggetto]['campi']);
        usort($campi, fn (array $a, array $b) => [$ordineDelleSezioni[$a['sezione_id']] ?? PHP_INT_MAX, $a['ord'], $a['codice']] <=> [$ordineDelleSezioni[$b['sezione_id']] ?? PHP_INT_MAX, $b['ord'], $b['codice']]);
        $contati = [];

        foreach ($campi as $i => $campo) {
            $contati[$campo['sezione_id']] = ($contati[$campo['sezione_id']] ?? 0) + 1;
            $campi[$i]['posto'] = $contati[$campo['sezione_id']];
            $campi[$i]['nome'] = self::nomiCrm($campo['catalogo']['nome'], $campo['scritti']);
        }

        return $campi;
    }

    /**
     * La sezione viva di un id (`persona.lavoro` o una ULID), con le vive del suo oggetto e il suo posto; un id che non c'è, eliminato
     * o di un altro workspace è lo stesso 404.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: int}
     */
    private function trovaSezioneCrm(string $workspace, string $id): array
    {
        $id = strlen($id) === 26 ? strtolower($id) : $id;

        foreach (self::CRM_OGGETTI_DEI_CAMPI as $oggetto) {
            $lista = $this->sezioniViveCrm($workspace, $oggetto);

            foreach ($lista as $i => $riga) {
                if ($riga['id'] === $id) {
                    return [$riga, $lista, $i + 1];
                }
            }
        }

        throw new Problema('non_trovato');
    }

    /**
     * Il campo di un id (`persona.ruolo`) con i campi del suo oggetto; un id che non c'è è 404.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function trovaCampoCrm(string $workspace, string $id): array
    {
        if (preg_match('/^([a-z]+)\.[a-z0-9_]+$/', $id, $trovato) === 1 && in_array($trovato[1], self::CRM_OGGETTI_DEI_CAMPI, true)) {
            $lista = $this->campiDiUnOggettoCrm($workspace, $trovato[1]);

            foreach ($lista as $campo) {
                if ($campo['id'] === $id) {
                    return [$campo, $lista];
                }
            }
        }

        throw new Problema('non_trovato');
    }

    // ———————————————————————————————————————— Forme e regole

    /** @return array<string, mixed> lo schema Sezione (Forme::sezione) */
    private function voceSezioneCrm(array $riga, int $posto): array
    {
        return [
            'id' => $riga['id'],
            'oggetto' => $riga['oggetto'],
            'nome' => self::nomiCrm($riga['catalogo'], $riga['scritti']),
            'posizione' => $posto,
            'fissa' => $riga['fissa'],
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
        ];
    }

    /** @return array<string, mixed> lo schema Campo (Forme::campo): `sistema` solo per i campi di tipo `sistema`, `obbligatorio` e `opzioni` solo per uno «tuo» */
    private function voceCampoCrm(array $campo): array
    {
        return [
            'id' => $campo['id'],
            'oggetto' => $campo['oggetto'],
            'chiave' => $campo['codice'],
            'origine' => $campo['origine'],
            'tipo' => $campo['tipo'],
        ] + ($campo['tipo'] === 'sistema' ? ['sistema' => $campo['sistema']] : []) + [
            'opzioni' => $campo['opzioni'],
            'nome' => $campo['nome'],
            'visibilita' => $campo['visibilita'],
            'sezione_id' => $campo['sezione_id'],
            'posizione' => $campo['posto'],
            'obbligatorio' => $campo['obbligatorio'],
            'indispensabile' => $campo['indispensabile'],
            'in_campi' => $campo['in_campi'],
            'creato_il' => self::iso($campo['creato_il']),
            'aggiornato_il' => self::iso($campo['aggiornato_il']),
        ];
    }

    /** @return list<mixed> le regole del nome in una lingua: facoltativo e che si può togliere */
    private function regoleDiUnNomeCrm(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:'.self::CRM_NOME_DI_UNA_SEZIONE, new NomeScritto];
    }

    /**
     * Il nome in tre lingue: quello scritto dal workspace, altrimenti quello del catalogo, altrimenti `null`.
     *
     * @param  array<string, string>  $catalogo
     * @param  array<string, string>  $scritti
     * @return array{it: ?string, en: ?string, es: ?string}
     */
    private static function nomiCrm(array $catalogo, array $scritti): array
    {
        return ['it' => $scritti['it'] ?? $catalogo['it'] ?? null, 'en' => $scritti['en'] ?? $catalogo['en'] ?? null, 'es' => $scritti['es'] ?? $catalogo['es'] ?? null];
    }

    /**
     * I nomi dopo una modifica: le lingue date in `$cambi` (`null` torna al catalogo, o toglie la lingua se non ce n'è uno; un nome uguale
     * a quello del catalogo non si scrive). `it` di una sezione creata non si toglie: 422 `obbligatorio` su
     * `#/nome/it`.
     *
     * @param  array<string, string>  $catalogo
     * @param  array<string, string>  $scritti
     * @param  array<string, ?string>  $cambi
     * @return array{it: ?string, en: ?string, es: ?string, riga: array<string, string>}
     */
    private function nomiNuoviCrm(array $catalogo, array $scritti, array $cambi, bool $creata): array
    {
        foreach ($cambi as $lingua => $valore) {
            if ($valore === null && $creata && $lingua === 'it') {
                throw $this->erroreCrm('nome.it', 'regole.nome_obbligatorio', Regola::OBBLIGATORIO);
            }

            if ($valore === null || $valore === ($catalogo[$lingua] ?? null)) {
                unset($scritti[$lingua]);
            } else {
                $scritti[$lingua] = $valore;
            }
        }

        return [...self::nomiCrm($catalogo, $scritti), 'riga' => $scritti];
    }

    /** I nomi scritti sono gli stessi, in qualunque ordine. */
    private static function scrittiUgualiCrm(array $una, array $altra): bool
    {
        ksort($una);
        ksort($altra);

        return $una === $altra;
    }

    /**
     * Il nome `it` non è di un'altra sezione viva dell'oggetto, senza maiuscole, accenti e spazi ai bordi: altrimenti 422 `duplicato`
     * su `#/nome/it`.
     *
     * @param  list<array<string, mixed>>  $vive
     */
    private function sezioneUnicaCrm(array $vive, ?string $nome, ?string $tranne): void
    {
        foreach ($vive as $altra) {
            if ($altra['id'] !== $tranne && self::nomeSimileCrm(self::nomiCrm($altra['catalogo'], $altra['scritti'])['it'], null) === self::nomeSimileCrm($nome, null)) {
                throw $this->erroreCrm('nome.it', 'regole.sezione_duplicata', Regola::DUPLICATO);
            }
        }
    }

    /**
     * Una pagina di un elenco di sezioni o di campi (ListaRequest): `oggetto` obbligatorio, `limite` da 1 a 300 (300 se manca) e
     * `cursore`, il `successivo` della pagina prima, legato all'oggetto, che porta quante voci sono già uscite.
     *
     * @param  Closure(string): list<array<string, mixed>>  $voci  le voci di un oggetto, nell'ordine dell'elenco
     * @return array{int, array<string, mixed>}
     */
    private function paginaDeiCampiCrm(Request $richiesta, string $operazione, Closure $voci): array
    {
        $grezza = self::query($richiesta);
        $lista = $operazione.':'.(is_string($grezza['oggetto'] ?? null) ? $grezza['oggetto'] : '');
        $query = $this->testi->validaQuery($grezza, [
            'oggetto' => ['required', 'string', Rule::in(self::CRM_OGGETTI_DEI_CAMPI)],
            'limite' => ['sometimes', 'integer', 'between:1,'.self::CRM_LIMITE_DEI_CAMPI],
            'cursore' => ['sometimes', 'string', function (string $attributo, mixed $valore, Closure $fail) use ($lista) {
                if ($this->leggiCursore($lista, 'posizione', $valore) === null) {
                    $this->testi->dichiara($attributo, 'formato');
                    $fail('regole.cursore')->translate();
                }
            }],
        ]);
        $limite = (int) ($query['limite'] ?? self::CRM_LIMITE_DEI_CAMPI);
        $dopo = isset($query['cursore']) ? (int) $this->leggiCursore($lista, 'posizione', $query['cursore']) : 0;
        $resto = array_slice($voci($query['oggetto']), $dopo);

        return [200, [
            'data' => array_slice($resto, 0, $limite),
            'successivo' => count($resto) > $limite ? $this->firmaCursore($lista, ['posizione' => (string) ($dopo + $limite)]) : null,
        ]];
    }
}
