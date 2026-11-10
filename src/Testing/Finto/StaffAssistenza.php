<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * «Accedi come» nel backoffice finto (#2914, F4): lo staff entra in un'app a nome di una persona. `staff.utenti.impersonificazioni.crea` dà il codice monouso (60
 * secondi), che il client firmato dell'app di destinazione scambia, senza verificatore, con `ingressi.scambio.crea`: il gettone di assistenza, nel workspace
 * dell'assistenza, per quattro ore e fino a trenta minuti senza chiamate. Il gettone apre soltanto i metodi che l'assistenza apre (`MetodiDellAssistenza`: il
 * supporto in sola lettura, il superadmin anche le scritture di contenuto), ogni altro è 403 `azione_non_consentita_in_assistenza`; ogni chiamata ammessa è una
 * riga del registro dello staff con l'assistenza. La persona non vede niente: né nei suoi accessi (`accessi.elenca`), né in una risposta (`accessi.corrente.mostra`
 * dà `impersonificazione` solo al gettone di assistenza, e solo la persona assistita). Si chiude con `accessi.corrente.elimina` («Termina») o con
 * `staff.impersonificazioni.elimina`; il comando che chiude le assistenze ferme il finto non lo ha: un gettone fermo da trenta minuti è 401 e basta.
 */
trait StaffAssistenza
{
    private const ORE_DELL_ASSISTENZA = 4;

    private const MINUTI_DI_INATTIVITA_DELL_ASSISTENZA = 30;

    /** @var array<string, string> i nomi che la regola per famiglia escluderebbe, o che non ne fanno parte, e che l'assistenza apre (MetodiDellAssistenza::ESPLICITI) */
    private const ESPLICITI_DELL_ASSISTENZA = [
        'accessi.corrente.mostra' => 'lettura',
        'accessi.corrente.elimina' => 'lettura',
        'io.mostra' => 'lettura',
        'io.workspace.elenca' => 'lettura',
        'io.aziende.elenca' => 'lettura',
        'io.notifiche.elenca' => 'lettura',
        'io.notifiche.preferenze.mostra' => 'lettura',
        'app.elenca' => 'lettura',
        'metodi.elenca' => 'lettura',
        'lingue.elenca' => 'lettura',
        'ricerca.elenca' => 'lettura',
        'ricerca.crea' => 'lettura',
    ];

    private const FAMIGLIE_DI_LETTURA_DELL_ASSISTENZA = ['board', 'crm', 'bookings', 'workspace'];

    private const NON_SI_LEGGE_IN_ASSISTENZA = ['*.esportazion*', '*.importazion*', '*.link', '*.link.*', '*.inviti', '*.inviti.*', '*.feed', '*.feed.*', '*.collegamenti', '*.collegamenti.*', 'bookings.pubblico.*', '*.scaricamento.*', '*.archivio.*'];

    private const SI_SCRIVE_IN_ASSISTENZA = ['board.*', 'crm.persone.*', 'crm.organizzazioni.*', 'crm.trattative.*', 'crm.attivita.*', 'crm.note.*', 'crm.scadenze.*', 'bookings.aperture.*', 'bookings.calendari.*'];

    private const NON_SI_SCRIVE_IN_ASSISTENZA = ['board.todo.elementi.promemoria.*', 'crm.persone.unioni.*', 'crm.persone.anonimizzazione.*', 'crm.persone.importazioni.*', 'crm.organizzazioni.importazioni.*', '*.ruoli.*'];

    /** @var array<string, array{staff: string, utente: string, workspace: string, app: string, sola_lettura: bool, accesso: ?string, inizio: CarbonImmutable, scade: CarbonImmutable, ultima: ?CarbonImmutable, fine: ?CarbonImmutable}> le assistenze, per id */
    private array $assistenze = [];

    /**
     * staff.utenti.impersonificazioni.crea (StaffUtentiController::impersonificazioniCrea): il codice di un «Accedi come». Superadmin e supporto (il supporto apre
     * un'assistenza di sola lettura). Nell'ordine del backoffice: il corpo, il tetto delle azioni sensibili, la persona (404; 403 se è chi chiama o è anche dello
     * staff), `utente_mai_acceduto` (409), la persona sospesa o in eliminazione (403), il workspace (404 se non è suo; 422 su `#/workspace_id` se ne ha zero o
     * più d'uno e non lo dice), l'app attiva lì (403) e un ritorno https nel catalogo (503). Un `ritorno` nel corpo è 422 come ogni campo estraneo.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaUnaAssistenza(Request $richiesta, array $corpo, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $dati = $this->testi->validaStretta($corpo, [
            'app' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', Rule::in(array_keys(self::CATALOGO))],
            'workspace_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9A-Za-z]{26}$/'],
            'motivo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $this->contaUnaAzioneSensibile($chi);

        $id = strtolower($id);

        if (! isset($this->persone[$id]) || ($chi['solo_uat'] && ! $this->emailDelPerimetroUat($this->persone[$id]['email']))) {
            throw new Problema('non_trovato');
        }

        if ($chi['utente_id'] === $id || $this->rigaDellaPersona($id) !== null) {
            throw new Problema('impersonificazione_non_consentita');
        }

        if (! $this->haAccedutoComeCliente($id)) {
            throw new Problema('utente_mai_acceduto');
        }

        if ($this->nonSiPuoUsareInAssistenza($id)) {
            throw new Problema('impersonificazione_non_consentita');
        }

        $workspace = $this->workspaceDellAssistenza($id, isset($dati['workspace_id']) ? strtolower((string) $dati['workspace_id']) : null);
        $app = (string) $dati['app'];

        if (! $this->appAttiva($workspace, $app)) {
            throw new Problema('app_non_attiva');
        }

        $ritorno = $this->ritorni[$app] ?? '';

        if (! str_starts_with($ritorno, 'https://')) {
            throw new Problema('servizio_non_disponibile');
        }

        $adesso = now()->toImmutable()->startOfMillisecond();
        $assistenza = self::id();
        $this->assistenze[$assistenza] = [
            'staff' => $chi['id'], 'utente' => $id, 'workspace' => $workspace, 'app' => $app, 'sola_lettura' => $chi['ruolo'] === 'supporto',
            'accesso' => null, 'inizio' => $adesso, 'scade' => $adesso->addHours(self::ORE_DELL_ASSISTENZA), 'ultima' => null, 'fine' => null,
        ];
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.impersonificazioni.crea', 'utente', $id, null, 'assistenza', $assistenza);

        $codice = self::base64url(random_bytes(32));
        $scade = now()->getTimestamp() + self::SECONDI_DELL_INGRESSO;
        $this->ingressi[$codice] = ['assistenza' => $assistenza, 'accesso' => '', 'workspace' => null, 'app' => $app, 'sfida' => '', 'scade' => $scade];

        return [201, ['data' => [
            'impersonificazione_id' => $assistenza,
            'codice' => $codice,
            'ritorno' => $ritorno,
            'scade_il' => self::iso(CarbonImmutable::createFromTimestampUTC($scade)),
        ]]];
    }

    /**
     * staff.impersonificazioni.elenca (#3390, StaffImpersonificazioniController::elenca): le assistenze dal più recente (`inizio`, poi id), a cursore, `aperte` di base (senza `fine` e
     * non scadute), `chiuse` (con `fine`, o scadute) o `tutte`; un altro `stato` è 422 su `stato`. Superadmin e supporto; il superadmin le vede tutte, una persona `solo_uat` solo quelle
     * di workspace il cui proprietario è `uat+*@castroandpartners.com`. La riga ha i soli campi dichiarati (mai email, codice, gettone, IP, motivo né l'id dello staff). Una lettura:
     * conta nel tetto delle letture e non scrive nel registro dello staff.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaLeAssistenzeDelloStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $this->contaLaLettura($chi);
        $stato = self::query($richiesta)['stato'] ?? 'aperte';
        $lista = 'staff.impersonificazioni.elenca:'.(is_string($stato) && in_array($stato, ['aperte', 'chiuse', 'tutte'], true) ? $stato : 'aperte');
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'string', $this->regolaDelCursoreDelloStaff($lista)],
            'stato' => ['bail', 'sometimes', 'string', 'in:aperte,chiuse,tutte'],
            'prova' => $this->regolaDellaProva(),
        ]);
        $stato = (string) ($query['stato'] ?? 'aperte');
        $adesso = now()->toImmutable();
        $voci = [];

        foreach ($this->assistenze as $id => $assistenza) {
            $aperta = $assistenza['fine'] === null && $assistenza['scade']->gt($adesso);

            // `prova=escludi` (#3574): via le assistenze su una persona o su un workspace di prova.
            $diProva = $this->escludiLaProva($query) && ($this->personaDiProva($assistenza['utente']) || $this->workspaceDiProva($assistenza['workspace']));

            if (($stato === 'aperte' && ! $aperta) || ($stato === 'chiuse' && $aperta) || ($chi['solo_uat'] && ! $this->proprietarioDiProva($assistenza['workspace'])) || $diProva) {
                continue;
            }

            $persona = $this->persone[$assistenza['utente']] ?? null;
            $staff = $this->righeStaff[$assistenza['staff']] ?? null;
            $voci[] = [
                'id' => $id,
                'staff' => ['nome' => $staff === null ? null : ($this->persone[$staff['utente_id']]['nome'] ?? null), 'cognome' => $staff === null ? null : ($this->persone[$staff['utente_id']]['cognome'] ?? null)],
                'utente' => ['id' => $assistenza['utente'], 'nome' => $persona['nome'] ?? null, 'cognome' => $persona['cognome'] ?? null],
                'app' => $assistenza['app'],
                'scade_il' => self::iso($assistenza['scade']),
            ];
        }

        $inizio = fn (string $id) => self::iso($this->assistenze[$id]['inizio']);

        return $this->impagina(
            $query, $lista, 'id', $voci,
            fn (array $voce) => [$inizio($voce['id']), $voce['id']],
            fn (string $id) => isset($this->assistenze[$id]) ? [$inizio($id), $id] : null,
            true,
        );
    }

    /**
     * Lo scambio del codice di un «Accedi come» (Ingresso::scambia → Assistenza::apri): senza verificatore (uno mandato è un errore), solo per il client firmato
     * dell'app del codice, e solo se l'assistenza si può ancora aprire. Ogni altro esito è la stessa 422 `verifica_non_riuscita`, e il codice è già consumato.
     *
     * @param  array<string, mixed>  $ingresso
     * @param  array<string, mixed>  $campi
     * @return array{int, array<string, mixed>}
     */
    private function apriLAssistenza(array $ingresso, array $campi): array
    {
        $assistenza = $this->assistenze[$ingresso['assistenza']] ?? null;

        if ($ingresso['scade'] <= now()->getTimestamp() || isset($campi['verificatore'])
            || ! in_array($this->clienteFirmato, self::CLIENT_DELL_APP[$ingresso['app']] ?? [], true)
            || $assistenza === null || $assistenza['app'] !== $ingresso['app'] || $assistenza['accesso'] !== null || $assistenza['fine'] !== null || $assistenza['scade']->lte(now())) {
            throw new Problema('verifica_non_riuscita', verifica: 'ingresso');
        }

        $ruolo = $this->membri[$assistenza['workspace']][$assistenza['utente']] ?? null;
        $staff = $this->righeStaff[$assistenza['staff']] ?? null;

        if ($staff === null || $staff['archiviato_il'] !== null || $ruolo === null || $this->nonSiPuoUsareInAssistenza($assistenza['utente'])
            || ! $this->appAttiva($assistenza['workspace'], $assistenza['app']) || isset($this->eliminazioni[$assistenza['workspace']])) {
            throw new Problema('verifica_non_riuscita', verifica: 'ingresso');
        }

        $adesso = now()->toImmutable()->startOfMillisecond();
        $scade = $adesso->addHours(self::ORE_DELL_ASSISTENZA);
        $accesso = self::id();
        $this->accessi[$accesso] = ['utente' => $assistenza['utente'], 'creato_il' => $adesso, 'chiuso' => false, 'via' => 'assistenza', 'scade' => $scade];
        $this->assistenze[$ingresso['assistenza']] = ['accesso' => $accesso, 'inizio' => $adesso, 'scade' => $scade, 'ultima' => $adesso] + $assistenza;

        return [201, ['data' => $this->emetti($accesso, $assistenza['workspace'], null, 'assistenza')]];
    }

    /**
     * accessi.corrente.mostra (AccessiController::mostraCorrente): l'accesso del gettone che chiama e, se è di assistenza, la fascia: la persona assistita e mai chi la
     * assiste. Ogni altro gettone ha `impersonificazione: null`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraLAccessoCorrente(Request $richiesta): array
    {
        $chi = $this->autentica($richiesta);
        $accesso = $this->accessi[$chi['accesso']];
        $scade = self::iso($this->scadenza($accesso));
        $assistenza = ($this->gettoni[(string) self::gettoneDi($richiesta)]['tipo'] ?? null) === 'assistenza' ? $this->assistenzaDelLAccesso($chi['accesso']) : null;
        $persona = $assistenza === null ? null : $this->persone[$assistenza['utente']];

        return [200, ['data' => [
            'accesso_id' => $chi['accesso'],
            'scade_il' => $scade,
            'impersonificazione' => $assistenza === null ? null : [
                'id' => $assistenza['id'],
                'scade_il' => $scade,
                'sola_lettura' => $assistenza['sola_lettura'],
                'utente' => ['nome' => $persona['nome'], 'cognome' => $persona['cognome'], 'email' => $persona['email']],
            ],
        ]]];
    }

    /**
     * staff.impersonificazioni.elimina (StaffImpersonificazioniController::elimina): chiude dal backoffice un'assistenza, con la chiusura del «Termina». Il superadmin
     * ogni assistenza, il supporto la propria; quella di un altro, o una che non c'è, è 404. Una già chiusa è 204 di nuovo e non scrive una seconda riga.
     *
     * @return array{int, null}
     */
    private function chiudiUnaAssistenzaDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $id = strtolower($id);
        $assistenza = $this->assistenze[$id] ?? null;

        if ($assistenza === null || ($chi['ruolo'] !== 'superadmin' && $assistenza['staff'] !== $chi['id'])) {
            throw new Problema('non_trovato');
        }

        $eraAperta = $assistenza['fine'] === null;
        $this->chiudiLAssistenza($id);

        if ($eraAperta) {
            $this->scriviNelRegistroDelloStaff($chi, 'staff.impersonificazioni.elimina', 'utente', $assistenza['utente'], null, null, $id);
        }

        return [204, null];
    }

    /** Il «Termina» (accessi.corrente.elimina con un gettone di assistenza): l'accesso di assistenza e i suoi gettoni si chiudono, gli altri accessi della persona restano. */
    private function chiudiLAssistenza(string $id): void
    {
        $assistenza = $this->assistenze[$id];

        if ($assistenza['fine'] === null) {
            $this->assistenze[$id]['fine'] = now()->toImmutable()->startOfMillisecond();
        }

        if ($assistenza['accesso'] !== null) {
            $this->accessi[$assistenza['accesso']]['chiuso'] = true;
        }
    }

    /**
     * L'ambito del gettone di assistenza (AmbitoDelGettone, F4.3): fermo da più di trenta minuti è 401 e non scrive niente; un metodo che l'assistenza non apre è 403
     * `azione_non_consentita_in_assistenza` (prima del corpo, quindi nemmeno un id altrui dice niente); uno ammesso rinnova i trenta minuti e lascia la sua riga nel
     * registro dello staff, con la persona vera dello staff e l'assistenza.
     */
    private function ambitoDellAssistenza(string $accesso): void
    {
        $assistenza = $this->assistenzaDelLAccesso($accesso);
        $staff = $assistenza === null ? null : ($this->righeStaff[$assistenza['staff']] ?? null);

        if ($assistenza !== null && ($assistenza['ultima'] === null || $assistenza['ultima']->addMinutes(self::MINUTI_DI_INATTIVITA_DELL_ASSISTENZA)->lte(now()))) {
            throw new Problema('gettone_non_valido', header: ['WWW-Authenticate' => 'Bearer realm="zeiras", error="invalid_token"']);
        }

        if ($assistenza === null || $staff === null || ! $this->assistenzaAmmette((string) $this->operazioneInCorso, $assistenza['sola_lettura'])) {
            throw new Problema('azione_non_consentita_in_assistenza');
        }

        $this->assistenze[$assistenza['id']]['ultima'] = now()->toImmutable()->startOfMillisecond();
        $this->scriviNelRegistroDelloStaff($staff, (string) $this->operazioneInCorso, 'utente', $assistenza['utente'], null, null, $assistenza['id']);
    }

    /** @return (array{id: string, staff: string, utente: string, workspace: string, app: string, sola_lettura: bool, accesso: ?string, scade: CarbonImmutable, ultima: ?CarbonImmutable, fine: ?CarbonImmutable})|null */
    private function assistenzaDelLAccesso(string $accesso): ?array
    {
        foreach ($this->assistenze as $id => $assistenza) {
            if ($assistenza['accesso'] === $accesso) {
                return ['id' => $id] + $assistenza;
            }
        }

        return null;
    }

    /** Il livello del metodo (MetodiDellAssistenza::livello), o null se l'assistenza non lo apre. */
    private function livelloDellAssistenza(string $metodo): ?string
    {
        if ($metodo === '') {
            return null;
        }

        if (isset(self::ESPLICITI_DELL_ASSISTENZA[$metodo])) {
            return self::ESPLICITI_DELL_ASSISTENZA[$metodo];
        }

        if (Str::is(self::NON_SI_LEGGE_IN_ASSISTENZA, $metodo)) {
            return null;
        }

        $famiglia = Str::before($metodo, '.');
        $azione = Str::afterLast($metodo, '.');
        $ricerca = $metodo === 'ricerca.crea' || str_ends_with($metodo, '.ricerca.crea');

        if (in_array($famiglia, self::FAMIGLIE_DI_LETTURA_DELL_ASSISTENZA, true) && ($ricerca || in_array($azione, ['elenca', 'mostra'], true))) {
            return 'lettura';
        }

        if (in_array($azione, ['crea', 'modifica'], true) && Str::is(self::SI_SCRIVE_IN_ASSISTENZA, $metodo) && ! Str::is(self::NON_SI_SCRIVE_IN_ASSISTENZA, $metodo)) {
            return 'scrittura';
        }

        return null;
    }

    /** Se un gettone di assistenza apre il metodo: il supporto (sola lettura) solo `lettura`, il superadmin anche `scrittura`. */
    private function assistenzaAmmette(string $metodo, bool $solaLettura): bool
    {
        $livello = $this->livelloDellAssistenza($metodo);

        return $livello === 'lettura' || ($livello === 'scrittura' && ! $solaLettura);
    }

    /** Se la persona ha avuto almeno un accesso suo: un'altra assistenza non è un accesso del cliente (anche chiuso conta). */
    private function haAccedutoComeCliente(string $persona): bool
    {
        foreach ($this->accessi as $accesso) {
            if ($accesso['utente'] === $persona && ($accesso['via'] ?? null) !== 'assistenza') {
                return true;
            }
        }

        return false;
    }

    /** Sospesa dallo staff, o con l'eliminazione chiesta: lo staff non entra come lei. */
    private function nonSiPuoUsareInAssistenza(string $persona): bool
    {
        return isset($this->sospensioniStaff[$persona]) || isset($this->eliminazioniPersona[$persona]);
    }

    /** Il workspace dell'assistenza: quello dato, di cui la persona dev'essere membro (404), o l'unico che ha (422 sul campo se sono zero o più d'uno). */
    private function workspaceDellAssistenza(string $persona, ?string $dato): string
    {
        $suoi = array_keys(array_filter($this->membri, fn (array $membri, string $workspace) => isset($membri[$persona]) && isset($this->workspace[$workspace]), ARRAY_FILTER_USE_BOTH));

        if ($dato !== null) {
            return in_array($dato, $suoi, true) ? $dato : throw new Problema('non_trovato');
        }

        if (count($suoi) !== 1) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.assistenza_workspace_obbligatorio'), 'regola' => 'obbligatorio', 'pointer' => '#/workspace_id']]);
        }

        return $suoi[0];
    }
}
