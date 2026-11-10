<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Gli utenti dei clienti nel backoffice finto (#2912, F2): gli undici metodi `staff.utenti.*` e `staff.esportazioni.*`, per il frontend dello staff
 * (zr-superadmin). Le regole dello staff sono quelle di `Staff`: il client firmato, il gettone dello staff, il ruolo, il perimetro `solo_uat`. In più: il
 * bersaglio che è anche dello staff (o la persona stessa) è 403, un utente che non c'è 404; i 300 pagine all'ora delle letture e i 30 all'ora delle
 * azioni sensibili per persona dello staff; la sospensione, che chiude le sessioni e fa rispondere 403 `account_sospeso` a ogni nuovo accesso; le
 * esportazioni, di chi le ha avviate e di nessun altro. Ogni campo che il vero manda `null` il finto lo manda `null` (cognome, ultimo_accesso, paese,
 * nota_staff, sospensione, eliminazione, dimensione_byte, errore, campi e motivo del registro). Non manda email a nessuno: il recupero della password
 * mette il codice nella posta del finto come `password.recupero.crea`.
 */
trait StaffUtenti
{
    private const STATI_DELL_UTENTE = ['attivo', 'sospeso', 'in_eliminazione'];

    private const MOTIVI_DELLA_SOSPENSIONE = ['segnalazione_abuso', 'pagamento_non_riuscito', 'accesso_sospetto', 'altro'];

    /** I motivi del registro dello staff (l'enum `Motivo` del backoffice): un elenco chiuso, mai testo libero. */
    private const MOTIVI_DEL_REGISTRO_DELLO_STAFF = ['assistenza', 'problema_tecnico', 'sicurezza', 'verifica_account'];

    private const LETTURE_ALL_ORA = 300;

    private const AZIONI_SENSIBILI_ALL_ORA = 30;

    private const LIMITE_DELLO_STAFF = 25;

    private const LIMITE_MASSIMO_DELLO_STAFF = 50;

    /** @var array<string, array{motivo: string, nota: ?string, dal: \Carbon\CarbonImmutable}> le sospensioni, per utente */
    private array $sospensioniStaff = [];

    /** @var array<string, array{staff: string, tipo: string, stato: string, creata: \Carbon\CarbonImmutable, byte: ?int, errore: ?string, utente: ?string, filtri: array<string, mixed>, file: ?string}> le esportazioni dello staff, per id */
    private array $esportazioniStaff = [];

    /** @var array<string, list<\Carbon\CarbonImmutable>> le pagine lette nell'ultima ora, per persona dello staff */
    private array $lettureStaff = [];

    /** @var array<string, list<\Carbon\CarbonImmutable>> le azioni sensibili dell'ultima ora, per persona dello staff */
    private array $azioniSensibiliStaff = [];

    /** @var array<string, array{paese?: ?string, nota_staff?: ?string}> i dati della scheda che il finto non ha altrove, per utente */
    private array $datiStaffSugliUtenti = [];

    /** @var array<string, array{email: string, effettivo_il: \Carbon\CarbonImmutable, motivo: string}> il cambio di email in attesa, per utente (#2915, F5.5) */
    private array $cambiEmailStaff = [];

    // ── Quello che il test prepara ─────────────────────────────────────────────────────

    /**
     * I due dati della scheda che una persona del finto non ha: `paese` (ISO 3166-1 alpha-2) e `nota_staff`. Senza, la scheda li manda `null`.
     *
     * @param  array<string, mixed>  $persona  una persona di persona()
     * @param  array{paese?: ?string, nota_staff?: ?string}  $dati
     */
    public function datiDelloStaffSuLUtente(array $persona, array $dati): self
    {
        $id = $this->idDellaPersonaDelloStaff($persona);

        if (array_diff(array_keys($dati), ['paese', 'nota_staff']) !== []) {
            throw new InvalidArgumentException('La scheda ha due dati in più della persona: `paese` e `nota_staff`.');
        }

        $this->datiStaffSugliUtenti[$id] = $dati + ($this->datiStaffSugliUtenti[$id] ?? []);

        return $this;
    }

    /**
     * Applica i cambi di email dello staff arrivati a scadenza (il comando orario del backoffice, `staff:applica-cambi-email`): l'email cambia ed è non verificata, le sessioni si chiudono e la richiesta
     * sparisce. Nessun avviso a nessuno. Il finto non ha un orologio che lo chiami: un test lo chiama dopo aver fatto passare le 72 ore (`Carbon::setTestNow`), e il finto lo chiama da sé prima di
     * rispondere con una scheda o con una modifica.
     */
    public function applicaICambiEmailDelloStaff(): self
    {
        foreach ($this->cambiEmailStaff as $utente => $cambio) {
            if ($cambio['effettivo_il']->gt(now())) {
                continue;
            }

            unset($this->cambiEmailStaff[$utente]);

            if ($this->conEmail($cambio['email']) !== null || ! isset($this->persone[$utente])) {
                continue;
            }

            $this->persone[$utente]['email'] = $cambio['email'];
            $this->persone[$utente]['email_verificata_il'] = null;
            $this->chiudiLeSessioniDellUtente($utente);
        }

        return $this;
    }

    /**
     * Fa finire le esportazioni dello staff ancora in corso: da qui sono `pronta`, con il file e la scadenza a un'ora dalla creazione. Il lavoro del
     * backoffice è in coda e il finto non ne ha una: un test della pagina che segue lo stato sta in `in_corso` finché non chiama questo, o
     * `fallisciLeEsportazioniDelloStaff()`.
     */
    public function preparaLeEsportazioniDelloStaff(): void
    {
        foreach ($this->esportazioniStaff as $id => $voce) {
            if ($voce['stato'] !== 'in_corso') {
                continue;
            }

            $file = $voce['tipo'] === 'elenco_utenti' ? "\xEF\xBB\xBF".$this->csvDegliUtenti($voce['staff'], $voce['filtri']) : "PK\x05\x06".str_repeat("\x00", 18);
            $this->esportazioniStaff[$id]['stato'] = 'pronta';
            $this->esportazioniStaff[$id]['file'] = $file;
            $this->esportazioniStaff[$id]['byte'] = strlen($file);
        }
    }

    /** Fa fallire le esportazioni dello staff ancora in corso, perché il file è oltre il tetto (`esportazione_troppo_grande`). */
    public function fallisciLeEsportazioniDelloStaff(): void
    {
        foreach ($this->esportazioniStaff as $id => $voce) {
            if ($voce['stato'] === 'in_corso') {
                $this->esportazioniStaff[$id]['stato'] = 'fallita';
                $this->esportazioniStaff[$id]['errore'] = 'esportazione_troppo_grande';
            }
        }
    }

    // ── Gli utenti ─────────────────────────────────────────────────────────────────────

    /** @return array{int, array<string, mixed>} */
    private function elencaGliUtentiDelloStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $this->contaLaLettura($chi);
        $regole = [
            'stato' => ['sometimes', 'string', 'in:'.implode(',', self::STATI_DELL_UTENTE)],
            'mai_acceduto' => ['sometimes', 'string', 'in:true,false'],
            'prova' => $this->regolaDellaProva(),
        ];
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'string', $this->regolaDelCursoreDelloStaff('staff.utenti')],
        ] + $regole);
        $filtri = array_intersect_key($query, $regole);

        if (isset($filtri['mai_acceduto'])) {
            $filtri['mai_acceduto'] = $filtri['mai_acceduto'] === 'true';
        }

        return $this->impaginaGliUtenti($query, 'staff.utenti', $this->righeDegliUtenti($chi, $filtri));
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function cercaGliUtentiDelloStaff(Request $richiesta, array $corpo): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $this->contaLaLettura($chi);
        $campi = array_filter($this->testi->validaStretta($corpo, $this->regoleDellaRicercaDegliUtenti() + [
            'limite' => ['sometimes', 'nullable', 'integer:strict', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'nullable', 'string'],
        ]), fn (mixed $valore) => $valore !== null);
        $filtri = array_diff_key($campi, ['limite' => 1, 'cursore' => 1]);
        // Il cursore è di questa ricerca e di questi filtri: con altri non vale (422 su #/cursore).
        $lista = 'staff.utenti.ricerca:'.sha1(json_encode($filtri, JSON_THROW_ON_ERROR));

        $this->parametriDelCorpo = true;

        try {
            return $this->impaginaGliUtenti($campi, $lista, $this->righeDegliUtenti($chi, $filtri));
        } finally {
            $this->parametriDelCorpo = false;
        }
    }

    /** @return array{int, array<string, mixed>} */
    private function mostraUnUtenteDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->applicaICambiEmailDelloStaff();
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.mostra', 'utente', $utente);

        return [200, ['data' => $this->schedaDellUtente($utente)]];
    }

    /**
     * staff.utenti.modifica (#2915, F5.1, F5.4, F5.5): l'anagrafica di una persona, come JSON Merge Patch. Solo il superadmin. Nell'ordine del backoffice: il ruolo, il bersaglio (404, 403), il corpo
     * (422: almeno un campo, un estraneo come la password o il TOTP, un valore fuori regola; con `email` anche `identita_verificata: true` e `motivo` dell'elenco chiuso), l'email di un'altra persona (422
     * sul campo). Un valore uguale a quello di oggi non scrive niente; un cambio vero lascia una riga nel registro dello staff coi nomi dei campi. L'email non cambia subito: resta in attesa 72 ore
     * (`cambio_email` della scheda), una richiesta nuova sostituisce la precedente. Risponde con la scheda. Nessun avviso a nessuno.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaUnUtenteDelloStaff(Request $richiesta, array $corpo, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->applicaICambiEmailDelloStaff();
        $conEmail = array_key_exists('email', $corpo);
        $regole = [
            'nome' => ['filled', 'string', 'max:255', new NomeScritto],
            'cognome' => ['sometimes', 'nullable', 'string', 'max:255', new NomeScritto],
            'telefono' => ['sometimes', 'nullable', 'bail', 'string', 'max:32', 'regex:/^(?=(?:\\D*\\d){5})[0-9+().\\- ]+$/'],
            'lingua' => ['filled', 'string', Rule::in(Testi::LINGUE)],
            'fuso_orario' => ['filled', 'string', 'timezone:all'],
            'paese' => ['sometimes', 'nullable', 'bail', 'string', Rule::in(self::PAESI)],
            'nota_staff' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email' => ['sometimes', 'bail', 'required', 'string', 'max:254', 'email:rfc,filter'],
            ...$conEmail ? [
                'identita_verificata' => ['bail', 'required', 'boolean:strict', 'accepted'],
                'motivo' => ['bail', 'required', 'string', Rule::in(self::MOTIVI_DEL_REGISTRO_DELLO_STAFF)],
            ] : ['identita_verificata' => ['sometimes', 'nullable'], 'motivo' => ['sometimes', 'nullable']],
        ];
        $campi = $this->testi->validaStretta($corpo, $regole);

        if ($campi === []) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => implode(', ', array_keys($regole))]), 'regola' => 'obbligatorio', 'pointer' => '#']]);
        }

        $cambiati = [];
        $correnti = ['paese' => $this->datiStaffSugliUtenti[$utente]['paese'] ?? null, 'nota_staff' => $this->datiStaffSugliUtenti[$utente]['nota_staff'] ?? null] + $this->persone[$utente];

        foreach (['nome', 'cognome', 'telefono', 'lingua', 'fuso_orario', 'paese', 'nota_staff'] as $campo) {
            if (array_key_exists($campo, $campi) && $campi[$campo] !== $correnti[$campo]) {
                $cambiati[] = $campo;
            }
        }

        $inAttesa = false;

        if ($conEmail) {
            $nuova = strtolower(trim((string) $campi['email']));
            $vivo = $this->cambiEmailStaff[$utente] ?? null;

            if ($nuova !== $this->persone[$utente]['email'] && ($vivo === null || $vivo['email'] !== $nuova)) {
                if ($this->conEmail($nuova) !== null) {
                    throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.unique', ['attribute' => 'email']), 'regola' => 'duplicato', 'pointer' => '#/email']]);
                }

                $inAttesa = true;
            }
        }

        foreach ($cambiati as $campo) {
            if (in_array($campo, ['paese', 'nota_staff'], true)) {
                $this->datiStaffSugliUtenti[$utente][$campo] = $campi[$campo];
            } else {
                $this->persone[$utente][$campo] = $campi[$campo];
            }
        }

        if ($inAttesa) {
            $this->cambiEmailStaff[$utente] = ['email' => $nuova, 'effettivo_il' => now()->toImmutable()->startOfMillisecond()->addHours(72), 'motivo' => (string) $campi['motivo']];
            $cambiati[] = 'email';
        }

        if ($cambiati !== []) {
            $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.modifica', 'utente', $utente, $cambiati, $inAttesa ? (string) $campi['motivo'] : null);
        }

        return [200, ['data' => $this->schedaDellUtente($utente)]];
    }

    /**
     * staff.utenti.cambio-email.elimina (#2915, F5.5): annulla il cambio di email in attesa. Solo il superadmin; 204, o 404 se non ce n'è uno. Una riga nel registro dello staff.
     *
     * @return array{int, null}
     */
    private function annullaIlCambioEmailDiUnUtente(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->applicaICambiEmailDelloStaff();

        if (! isset($this->cambiEmailStaff[$utente])) {
            throw new Problema('non_trovato');
        }

        unset($this->cambiEmailStaff[$utente]);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.cambio-email.elimina', 'utente', $utente);

        return [204, null];
    }

    /** @return array{int, array<string, mixed>} */
    private function elencaIlRegistroDiUnUtente(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->contaLaLettura($chi);
        $regole = [
            'staff_id' => ['sometimes', 'string', 'regex:/^[0-9A-Za-z]{26}$/'],
            'azione' => ['sometimes', 'string', 'max:120'],
            'dal' => ['sometimes', 'string', 'date'],
            'al' => ['sometimes', 'string', 'date'],
            'prova' => ['prohibited'],
        ];
        $lista = 'staff.utenti.registro:'.$utente;
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'string', $this->regolaDelCursoreDelloStaff($lista)],
        ] + $regole);
        $filtri = array_intersect_key($query, $regole) + ['bersaglio_tipo' => 'utente', 'bersaglio_id' => $utente];
        $voci = array_map(fn (array $riga) => $this->voceDelRegistro($riga), $this->righeDelRegistro($chi, $filtri));
        $query['limite'] ??= self::LIMITE_DELLO_STAFF;

        return $this->impagina(
            $query, $lista, 'id', $voci,
            fn (array $voce) => [str_pad((string) $voce['posizione'], 12, '0', STR_PAD_LEFT)],
            function (string $riga) {
                foreach ($this->registroStaff as $voce) {
                    if ($voce['id'] === $riga) {
                        return [str_pad((string) $voce['posizione'], 12, '0', STR_PAD_LEFT)];
                    }
                }

                return null;
            },
            true,
        );
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function sospendiUnUtenteDelloStaff(Request $richiesta, array $corpo, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $utente = $this->bersaglioUtente($chi, $id);
        $dati = $this->testi->validaStretta($corpo, [
            'motivo' => ['required', 'string', 'in:'.implode(',', self::MOTIVI_DELLA_SOSPENSIONE)],
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (isset($this->sospensioniStaff[$utente])) {
            throw new Problema('gia_sospeso');
        }

        $this->contaUnaAzioneSensibile($chi);
        $this->sospensioniStaff[$utente] = ['motivo' => $dati['motivo'], 'nota' => $dati['nota'] ?? null, 'dal' => now()->toImmutable()->startOfMillisecond()];
        $this->chiudiLeSessioniDellUtente($utente);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.sospensione.crea', 'utente', $utente, null, $dati['motivo']);

        return [201, ['data' => $this->formaDellaSospensione($this->sospensioniStaff[$utente])]];
    }

    /** @return array{int, null} */
    private function riattivaUnUtenteDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $utente = $this->bersaglioUtente($chi, $id);

        if (! isset($this->sospensioniStaff[$utente])) {
            throw new Problema('non_trovato');
        }

        $this->contaUnaAzioneSensibile($chi);
        unset($this->sospensioniStaff[$utente]);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.sospensione.elimina', 'utente', $utente);

        return [204, null];
    }

    /** @return array{int, null} */
    private function chiudiLeSessioniDiUnUtente(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->contaUnaAzioneSensibile($chi);
        $this->chiudiLeSessioniDellUtente($utente);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.sessioni.elimina', 'utente', $utente);

        return [204, null];
    }

    /** @return array{int, array<string, mixed>} */
    private function avviaIlRecuperoDiUnUtente(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->contaUnaAzioneSensibile($chi);
        $email = $this->persone[$utente]['email'];
        // Come password.recupero.crea: il codice nasce e parte, solo, verso l'indirizzo registrato. Lo staff non lo vede.
        $this->recuperi[$utente] = ['codice' => $this->codiceNuovo($email), 'tentativi' => 0, 'scade' => now()->getTimestamp() + self::MINUTI_DEL_CODICE * 60];
        $this->posta[$email] = $this->recuperi[$utente]['codice'];
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.password.recupero.crea', 'utente', $utente);

        return [202, ['data' => ['scade_il' => self::iso(now()->toImmutable()->startOfMillisecond()->addMinutes(self::MINUTI_DEL_CODICE))]]];
    }

    // ── Le esportazioni ────────────────────────────────────────────────────────────────

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function esportaUnUtenteDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $utente = $this->bersaglioUtente($chi, $id);
        $this->contaUnaAzioneSensibile($chi);
        $esportazione = $this->avviaUnaEsportazioneDelloStaff($chi, 'utente_gdpr', $utente, []);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.esportazioni.crea', 'utente', $utente);

        return [202, ['data' => $this->formaDellEsportazioneDelloStaff($esportazione)], ['Location' => '/v1/staff/esportazioni/'.$esportazione]];
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function esportaGliUtentiDelloStaff(Request $richiesta, array $corpo): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $filtri = array_filter($this->testi->validaStretta($corpo, $this->regoleDellaRicercaDegliUtenti()), fn (mixed $valore) => $valore !== null);
        $this->contaUnaAzioneSensibile($chi);
        $esportazione = $this->avviaUnaEsportazioneDelloStaff($chi, 'elenco_utenti', null, $filtri);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.utenti.ricerca.esportazioni.crea', 'esportazione', $esportazione);

        return [202, ['data' => $this->formaDellEsportazioneDelloStaff($esportazione)], ['Location' => '/v1/staff/esportazioni/'.$esportazione]];
    }

    /** @return array{int, array<string, mixed>} */
    private function mostraUnaEsportazioneDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);

        return [200, ['data' => $this->formaDellEsportazioneDelloStaff($this->esportazioneDi($chi, $id))]];
    }

    /** @return array{int, string, array<string, string>} */
    private function scaricaUnaEsportazioneDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $esportazione = $this->esportazioneDi($chi, $id);
        $voce = $this->esportazioniStaff[$esportazione];

        if ($this->statoDellEsportazioneDelloStaff($voce) !== 'pronta') {
            throw new Problema('esportazione_non_disponibile');
        }

        $this->contaUnaAzioneSensibile($chi);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.esportazioni.scaricamento.crea', 'esportazione', $esportazione);
        $csv = $voce['tipo'] === 'elenco_utenti';

        return [200, (string) $voce['file'], [
            'Content-Type' => $csv ? 'text/csv; charset=utf-8' : 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.($csv ? 'utenti-' : 'utente-').gmdate('Y-m-d').($csv ? '.csv' : '.zip').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]];
    }

    // ── Sotto il cofano ──────────────────────────────────────────────────────────────────

    /**
     * Il 403 che un utente sospeso riceve quando prova ad accedere o a chiamare (M10). Le credenziali sono già giuste: nessun oracolo.
     */
    private function rifiutaSeSospeso(?string $persona): void
    {
        if ($persona !== null && isset($this->sospensioniStaff[$persona])) {
            throw new Problema('account_sospeso');
        }
    }

    private function chiudiLeSessioniDellUtente(string $utente): void
    {
        foreach ($this->accessi as $id => $accesso) {
            if ($accesso['utente'] === $utente) {
                $this->accessi[$id]['chiuso'] = true;
            }
        }
    }

    /** @param  array{motivo: string, nota: ?string, dal: \Carbon\CarbonImmutable}  $sospensione */
    private function formaDellaSospensione(array $sospensione): array
    {
        return ['motivo' => $sospensione['motivo'], 'nota' => $sospensione['nota'], 'dal' => self::iso($sospensione['dal'])];
    }

    /** @return array<string, mixed> */
    private function regoleDellaRicercaDegliUtenti(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'string', 'max:254'],
            'stato' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::STATI_DELL_UTENTE)],
            'mai_acceduto' => ['sometimes', 'nullable', 'boolean:strict'],
            'prova' => $this->regolaDellaProva(true),
        ];
    }

    /** Il controllo del `cursore` di una lista dello staff, come nel paginatore del finto. */
    private function regolaDelCursoreDelloStaff(string $lista): Closure
    {
        return function (string $attributo, mixed $valore, Closure $fail) use ($lista) {
            if ($this->leggiPosizione($lista, ['id'], $valore) === null) {
                $this->testi->dichiara($attributo, 'formato');
                $fail('regole.cursore')->translate();
            }
        };
    }

    /**
     * @param  array<string, mixed>  $query  `limite` e `cursore`, già validati
     * @param  list<array<string, mixed>>  $righe
     * @return array{int, array<string, mixed>}
     */
    private function impaginaGliUtenti(array $query, string $lista, array $righe): array
    {
        $query['limite'] ??= self::LIMITE_DELLO_STAFF;

        return $this->impagina(
            $query, $lista, 'id', $righe,
            fn (array $voce) => [$voce['creato_il'], $voce['id']],
            function (string $id) {
                foreach ($this->persone as $persona => $dati) {
                    if ($persona === $id) {
                        return [self::iso($this->creataIlDellaPersona($persona)), $persona];
                    }
                }

                return null;
            },
            true,
        );
    }

    /**
     * Gli utenti che chi chiama vede, con i filtri: tutte le persone vive, o per chi è solo_uat quelle del suo perimetro. Mai le anonimizzate né le archiviate: il finto non ne ha.
     *
     * @param  array<string, mixed>  $chi
     * @param  array<string, mixed>  $filtri
     * @return list<array<string, mixed>>
     */
    private function righeDegliUtenti(array $chi, array $filtri): array
    {
        $righe = [];
        $parole = isset($filtri['q']) ? array_values(array_filter(preg_split('/\s+/u', Str::lower(trim((string) $filtri['q']))) ?: [])) : [];

        foreach ($this->persone as $id => $persona) {
            if (($chi['solo_uat'] && ! $this->emailDelPerimetroUat($persona['email'])) || ($this->escludiLaProva($filtri) && $this->personaDiProva($id))) {
                continue;
            }

            $nome = Str::lower(Str::ascii($persona['nome'].' '.($persona['cognome'] ?? '')));

            if (array_filter($parole, fn (string $parola) => ! str_contains($nome, Str::ascii($parola))) !== []) {
                continue;
            }

            if (isset($filtri['email']) && self::normalizza((string) $filtri['email']) !== $persona['email']) {
                continue;
            }

            $riga = $this->rigaDellUtente($id);

            if ((isset($filtri['stato']) && $riga['stato'] !== $filtri['stato']) || (isset($filtri['mai_acceduto']) && $riga['mai_acceduto'] !== $filtri['mai_acceduto'])) {
                continue;
            }

            $righe[] = $riga;
        }

        return $righe;
    }

    /** @return array<string, mixed> la riga dell'elenco */
    private function rigaDellUtente(string $id): array
    {
        $persona = $this->persone[$id];
        $accessi = array_filter($this->accessi, fn (array $accesso) => $accesso['utente'] === $id);
        $ultimo = array_reduce($accessi, fn (?\Carbon\CarbonImmutable $corrente, array $accesso) => $corrente === null || $accesso['creato_il']->gt($corrente) ? $accesso['creato_il'] : $corrente);

        return [
            'id' => $id,
            'nome' => $persona['nome'],
            'cognome' => $persona['cognome'],
            'email' => $persona['email'],
            'stato' => $this->statoDellUtente($id),
            'mai_acceduto' => array_filter($accessi, fn (array $accesso) => ($accesso['via'] ?? null) !== 'assistenza') === [],
            'di_prova' => $this->personaDiProva($id),
            'workspace_n' => count(array_filter($this->membri, fn (array $membri, string $workspace) => isset($membri[$id]) && ! isset($this->eliminazioni[$workspace]), ARRAY_FILTER_USE_BOTH)),
            'ultimo_accesso' => $ultimo === null ? null : self::iso($ultimo),
            'creato_il' => self::iso($this->creataIlDellaPersona($id)),
        ];
    }

    /** @return array<string, mixed> la scheda */
    private function schedaDellUtente(string $id): array
    {
        $persona = $this->persone[$id];
        $accessi = array_filter($this->accessi, fn (array $accesso) => $accesso['utente'] === $id);
        $chiavi = array_keys($this->providerCollegati[$id] ?? []);
        $ultimi = [];

        foreach ($this->accettazioni[$id] ?? [] as $chiave => $istante) {
            [$documento, $versione] = explode('@', $chiave, 2) + [1 => ''];

            if (! isset($ultimi[$documento]) || strcmp((string) $istante, $ultimi[$documento]['accettata_il']) >= 0) {
                $ultimi[$documento] = ['documento' => $documento, 'versione' => $versione, 'accettata_il' => (string) $istante];
            }
        }

        $workspace = [];

        foreach ($this->membri as $idWorkspace => $membri) {
            if (! isset($membri[$id]) || isset($this->eliminazioni[$idWorkspace])) {
                continue;
            }

            $proprietari = array_keys(array_filter($membri, fn (string $ruolo) => $ruolo === 'proprietario'));
            $workspace[] = [
                'id' => $idWorkspace,
                'nome' => $this->workspace[$idWorkspace]['nome'],
                'azienda' => $this->aziende[$this->workspace[$idWorkspace]['azienda_id']] ?? null,
                'ruolo' => $membri[$id],
                'proprietario_unico' => $membri[$id] === 'proprietario' && $proprietari === [$id],
            ];
        }

        $eliminazione = $this->eliminazioniPersona[$id] ?? null;

        return $this->rigaDellUtente($id) + [
            'telefono' => $persona['telefono'],
            'lingua' => $persona['lingua'],
            'fuso_orario' => $persona['fuso_orario'],
            'paese' => $this->datiStaffSugliUtenti[$id]['paese'] ?? null,
            'nota_staff' => $this->datiStaffSugliUtenti[$id]['nota_staff'] ?? null,
            'consensi' => array_values($ultimi),
            'sicurezza' => [
                'provider' => array_values($chiavi),
                'sessioni_aperte' => count(array_filter($this->gettoni, fn (array $gettone) => ($this->accessi[$gettone['accesso']]['utente'] ?? null) === $id && ! $this->accessi[$gettone['accesso']]['chiuso'] && $this->scadenza($this->accessi[$gettone['accesso']])->gt(now()))),
            ],
            'accessi_n' => count($accessi),
            'workspace' => $workspace,
            'sospensione' => isset($this->sospensioniStaff[$id]) ? $this->formaDellaSospensione($this->sospensioniStaff[$id]) : null,
            'eliminazione' => $eliminazione === null ? null : [
                'prevista_il' => self::iso($eliminazione->addDays(30)),
                'motivo' => null,
                'quando' => null,
            ],
            'cambio_email' => isset($this->cambiEmailStaff[$id]) ? [
                'email' => $this->cambiEmailStaff[$id]['email'],
                'effettivo_il' => self::iso($this->cambiEmailStaff[$id]['effettivo_il']),
                'motivo' => $this->cambiEmailStaff[$id]['motivo'],
            ] : null,
        ];
    }

    private function statoDellUtente(string $id): string
    {
        return isset($this->eliminazioniPersona[$id]) ? 'in_eliminazione' : (isset($this->sospensioniStaff[$id]) ? 'sospeso' : 'attivo');
    }

    private function creataIlDellaPersona(string $id): \Carbon\CarbonImmutable
    {
        return $this->persone[$id]['creato_il'] ?? \Carbon\CarbonImmutable::parse('2026-01-01T00:00:00.000Z');
    }

    /**
     * Il bersaglio di un metodo di `staff.utenti.*`: 404 se non c'è o sta fuori dal perimetro (lo stesso per tutti: R16); 403 se è anche dello staff o è chi chiama.
     *
     * @param  array<string, mixed>  $chi
     */
    private function bersaglioUtente(array $chi, string $id): string
    {
        $id = strtolower($id);

        if (! isset($this->persone[$id]) || ($chi['solo_uat'] && ! $this->emailDelPerimetroUat($this->persone[$id]['email']))) {
            throw new Problema('non_trovato');
        }

        if ($chi['utente_id'] === $id || $this->rigaDellaPersona($id) !== null) {
            throw new Problema('permesso_negato');
        }

        return $id;
    }

    /** La pagina letta: 300 all'ora per persona dello staff. Oltre, 429 e una riga nel registro. */
    private function contaLaLettura(array $chi): void
    {
        $adesso = now()->toImmutable();
        $this->lettureStaff[$chi['id']] = array_values(array_filter($this->lettureStaff[$chi['id']] ?? [], fn (\Carbon\CarbonImmutable $quando) => $quando->gt($adesso->subHour())));

        if (count($this->lettureStaff[$chi['id']]) >= self::LETTURE_ALL_ORA) {
            $this->scriviNelRegistroDelloStaff($chi, $this->operazioneInCorso, 'registro', null);

            throw new Problema('troppe_richieste', header: ['Retry-After' => (string) max(1, (int) ceil($this->lettureStaff[$chi['id']][0]->addHour()->diffInSeconds($adesso, true)))]);
        }

        $this->lettureStaff[$chi['id']][] = $adesso;
    }

    /** Un'azione sensibile: 30 all'ora per persona dello staff. Oltre, 429, senza la riga di registro. */
    private function contaUnaAzioneSensibile(array $chi): void
    {
        $adesso = now()->toImmutable();
        $this->azioniSensibiliStaff[$chi['id']] = array_values(array_filter($this->azioniSensibiliStaff[$chi['id']] ?? [], fn (\Carbon\CarbonImmutable $quando) => $quando->gt($adesso->subHour())));

        if (count($this->azioniSensibiliStaff[$chi['id']]) >= self::AZIONI_SENSIBILI_ALL_ORA) {
            throw new Problema('troppe_richieste', header: ['Retry-After' => (string) max(1, (int) ceil($this->azioniSensibiliStaff[$chi['id']][0]->addHour()->diffInSeconds($adesso, true)))]);
        }

        $this->azioniSensibiliStaff[$chi['id']][] = $adesso;
    }

    /**
     * @param  array<string, mixed>  $chi
     * @param  array<string, mixed>  $filtri
     */
    private function avviaUnaEsportazioneDelloStaff(array $chi, string $tipo, ?string $utente, array $filtri): string
    {
        $id = self::id();
        $this->esportazioniStaff[$id] = ['staff' => $chi['id'], 'tipo' => $tipo, 'stato' => 'in_corso', 'creata' => now()->toImmutable()->startOfMillisecond(), 'byte' => null, 'errore' => null, 'utente' => $utente, 'filtri' => $filtri, 'file' => null];

        return $id;
    }

    /**
     * L'esportazione di chi chiama: un'altra persona dello staff, anche superadmin, e un'esportazione che non c'è sono 404.
     *
     * @param  array<string, mixed>  $chi
     */
    private function esportazioneDi(array $chi, string $id): string
    {
        $id = strtolower($id);

        if (($this->esportazioniStaff[$id]['staff'] ?? null) !== $chi['id']) {
            throw new Problema('non_trovato');
        }

        return $id;
    }

    /** @param  array<string, mixed>  $voce */
    private function statoDellEsportazioneDelloStaff(array $voce): string
    {
        return $voce['stato'] === 'pronta' && $voce['creata']->addHour()->lte(now()) ? 'scaduta' : $voce['stato'];
    }

    /** @return array<string, mixed> */
    private function formaDellEsportazioneDelloStaff(string $id): array
    {
        $voce = $this->esportazioniStaff[$id];

        return [
            'id' => $id,
            'tipo' => $voce['tipo'],
            'stato' => $this->statoDellEsportazioneDelloStaff($voce),
            'creata_il' => self::iso($voce['creata']),
            'scade_il' => self::iso($voce['creata']->addHour()),
            'dimensione_byte' => $voce['byte'],
            'errore' => $voce['errore'],
        ];
    }

    /**
     * Il CSV di un elenco: esattamente i campi della riga dell'elenco, nell'ordine, snake_case, righe CRLF, una cella che sembra una formula con l'apostrofo davanti.
     *
     * @param  array<string, mixed>  $filtri
     */
    private function csvDegliUtenti(string $staff, array $filtri): string
    {
        $colonne = ['id', 'nome', 'cognome', 'email', 'stato', 'mai_acceduto', 'workspace_n', 'ultimo_accesso', 'creato_il'];
        $righe = $this->righeDegliUtenti($this->righeStaff[$staff], $filtri);
        // Nell'ordine dell'elenco: dal più recente.
        usort($righe, fn (array $una, array $altra) => self::confronta([$altra['creato_il'], $altra['id']], [$una['creato_il'], $una['id']]));
        $file = implode(',', $colonne)."\r\n";

        foreach ($righe as $riga) {
            $celle = array_map(function (string $colonna) use ($riga) {
                $valore = $riga[$colonna];
                $testo = is_bool($valore) ? ($valore ? 'true' : 'false') : (string) ($valore ?? '');

                return $testo !== '' && str_contains("=+-@\t\r\n", $testo[0]) ? "'".$testo : $testo;
            }, $colonne);
            $file .= implode(',', array_map(fn (string $cella) => preg_match('/[",\r\n]/', $cella) === 1 ? '"'.str_replace('"', '""', $cella).'"' : $cella, $celle))."\r\n";
        }

        return $file;
    }

    /** @param  array<string, mixed>  $persona */
    private function idDellaPersonaDelloStaff(array $persona): string
    {
        foreach ($this->persone as $id => $dati) {
            if ($dati['email'] === ($persona['email'] ?? null)) {
                return $id;
            }
        }

        throw new InvalidArgumentException('Nel finto non c\'è questa persona: si crea con persona().');
    }
}
