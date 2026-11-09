<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

/**
 * Lo staff di Zeiras nel backoffice finto (#2911, F1): i quattordici metodi `staff.*`, per il frontend dello staff (zr-superadmin). Tutti vogliono la firma
 * del client (`Api::staff()` la manda sempre): senza è 401 cliente_non_riconosciuto, come dal backoffice vero, dove il client dev'essere `superadmin`.
 * Il finto modella le regole che un controller dello staff deve rispettare: l'ingresso in due passi con la stessa risposta per ogni primo passo (R16), i
 * freni, il TOTP (il codice `000000` vale per un solo passo di 30 secondi, poi serve altro tempo, `Carbon::setTestNow`), i dieci codici di recupero una volta
 * sola, i due gettoni (staff: 8 ore e 30 minuti senza chiamate; enrolment: 15 minuti, solo `staff.totp.*`), i ruoli, il perimetro `solo_uat`,
 * l'ultimo superadmin e il registro dello staff. Non modella la catena di hash del registro, la posta né l'avviso ai superadmin.
 */
trait Staff
{
    /** Il codice TOTP che il finto accetta (un passo di 30 secondi alla volta), per ogni persona dello staff. */
    public const CODICE_TOTP_DELLO_STAFF = '000000';

    private const RUOLI_DELLO_STAFF = ['superadmin', 'supporto', 'fatturazione'];

    private const DOMINI_DELLO_STAFF = ['lucianocastro.com', 'castroandpartners.com'];

    private const MOTIVI_DELLO_STAFF = ['assistenza', 'problema_tecnico', 'sicurezza', 'verifica_account'];

    private const BERSAGLI_DEL_REGISTRO = ['staff', 'utente', 'workspace', 'registro', 'esportazione'];

    private const FINESTRA_DELLO_STAFF = 900;

    private const GIORNO_DELLO_STAFF = 86400;

    /** @var list<string> i domini da cui chi ha l'email verificata entra nello staff alla prima volta (`ZR_STAFF_DOMINI_AMMESSI` del backoffice, #3445): vuoto = nessuno */
    private array $dominiDelloStaff = [];

    private string $ruoloDelDominioDelloStaff = 'supporto';

    /** @var array<string, array<string, mixed>> le persone dello staff per id */
    private array $righeStaff = [];

    /** @var array<string, array{staff: string, scade_il: \Carbon\CarbonImmutable, consumato: bool}> gli inviti per segreto */
    private array $invitiStaff = [];

    /** @var array<string, array{staff: string, scade_il: \Carbon\CarbonImmutable, errori: int, ip: string}> le sfide del secondo passo per id */
    private array $sfideStaff = [];

    /** @var array<string, array{staff: string, tipo: string, creato_il: \Carbon\CarbonImmutable, ultima: \Carbon\CarbonImmutable, chiuso: bool, errori: int}> i gettoni dello staff */
    private array $gettoniStaff = [];

    /** @var list<array<string, mixed>> il registro dello staff, dalla prima riga */
    private array $registroStaff = [];

    /** @var array<string, array{codice: string, estensioni: array<string, mixed>}> i fallimenti che il test ha preparato, per operazione: il primo vale una volta */
    private array $fallimentiStaff = [];

    // ── Quello che il test prepara ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Una persona dello staff, per una persona del finto (persona()). Con `$conTotp` ha già confermato il secondo fattore (codice `000000`) e ha i suoi dieci
     * codici di recupero (codiciDiRecuperoDelloStaff()); senza, ha solo un invito da accettare (invitoDelloStaff()). `$soloUat`: agisce solo su `uat+…`.
     *
     * @param  array<string, mixed>  $persona  una persona di persona()
     * @param  array<string, mixed>|null  $creataDa  la persona dello staff che l'ha fatta entrare
     * @return array<string, mixed> la persona dello staff come la danno le letture (`id`, `utente_id`, `ruolo`, …)
     */
    public function staffDi(array $persona, string $ruolo = 'superadmin', bool $conTotp = true, bool $soloUat = false, ?array $creataDa = null): array
    {
        if (! in_array($ruolo, self::RUOLI_DELLO_STAFF, true)) {
            throw new InvalidArgumentException("Il ruolo dello staff è ".implode(', ', self::RUOLI_DELLO_STAFF)." e non «{$ruolo}».");
        }

        $utente = (string) ($persona['id'] ?? throw new InvalidArgumentException('La persona dello staff è una persona() del finto.'));

        foreach ($this->righeStaff as $riga) {
            if ($riga['utente_id'] === $utente && $riga['archiviato_il'] === null) {
                throw new LogicException('Nel finto questa persona è già nello staff.');
            }
        }

        $id = self::id();
        $this->righeStaff[$id] = [
            'id' => $id,
            'utente_id' => $utente,
            'ruolo' => $ruolo,
            'solo_uat' => $soloUat,
            'totp' => $conTotp ? 'finto' : null,
            'totp_confermato_il' => $conTotp ? now()->toImmutable() : null,
            'ultimo_passo' => null,
            'creato_il' => now()->toImmutable(),
            'creato_da' => $creataDa['id'] ?? null,
            'archiviato_il' => null,
            'recupero' => [],
        ];

        if ($conTotp) {
            $this->rigeneraIRecuperi($id);
        } else {
            $this->nuovoInvitoDelloStaff($id);
        }

        return $this->voceDelloStaff($id);
    }

    /**
     * Il gettone dello staff di una persona dello staff, come lo dà l'ingresso con il login normale di Zeiras (#3445: ingressi.staff.crea e ingressi.scambio.crea), senza fare il giro: per i test del
     * frontend dello staff che partono già da dentro. La riga di registro è quella dello scambio. Le persone di prova (`solo_uat`) non entrano così, ma con la password e il codice
     * (staff.accessi.crea e .verifica.crea).
     *
     * @param  array<string, mixed>|string  $staff  una persona dello staff di staffDi(), o la sua email
     */
    public function ingressoDelloStaff(array|string $staff): string
    {
        if (is_string($staff)) {
            $persona = $this->conEmail(self::normalizza($staff));
            $riga = $persona === null ? null : $this->rigaDellaPersona($persona);
        } else {
            $riga = $this->righeStaff[$staff['id'] ?? ''] ?? null;
        }

        $riga ?? throw new InvalidArgumentException('Nel finto non c\'è questa persona dello staff.');

        if ($riga['solo_uat']) {
            throw new LogicException('Una persona di prova entra con la password e il codice, non con il login normale: staff.accessi.crea e .verifica.crea.');
        }

        $gettone = $this->emettiIlGettoneDelloStaff($riga['id'], 'staff');
        $this->scriviNelRegistroDelloStaff($riga, 'ingressi.scambio.crea', 'staff', $riga['id']);

        return $gettone;
    }

    /**
     * Chi ha l'email verificata su uno di questi domini entra nello staff alla prima volta di `ingressi.staff.crea`, con `$ruolo` (la config `zeiras.staff.domini_ammessi` e `ruolo_dominio` del
     * backoffice, #3445). Per default nessun dominio: entra solo chi ha già la sua riga (staffDi()). I domini valgono solo se sono anche tra quelli dello staff.
     *
     * @param  list<string>  $domini
     */
    public function staffDaiDomini(array $domini, string $ruolo = 'supporto'): self
    {
        if (! in_array($ruolo, self::RUOLI_DELLO_STAFF, true)) {
            throw new InvalidArgumentException("Il ruolo dello staff è ".implode(', ', self::RUOLI_DELLO_STAFF)." e non «{$ruolo}».");
        }

        $this->dominiDelloStaff = array_map('strtolower', $domini);
        $this->ruoloDelDominioDelloStaff = $ruolo;

        return $this;
    }

    /**
     * Il segreto di un nuovo invito per la persona dello staff (24 ore, uno solo vale: i precedenti si annullano), come lo porterebbe il link dell'email.
     *
     * @param  array<string, mixed>  $staff  una persona dello staff di staffDi()
     */
    public function invitoDelloStaff(array $staff): string
    {
        $id = (string) $staff['id'];
        $this->rigaDelloStaff($id);

        return $this->nuovoInvitoDelloStaff($id);
    }

    /** L'ultimo invito nato per quest'email (da staff.crea, dal reset del secondo fattore o da invitoDelloStaff()), ancora da consumare; null se non ce n'è. */
    public function ultimoInvitoDelloStaff(string $email): ?string
    {
        $persona = $this->conEmail(self::normalizza($email));
        $trovato = null;

        foreach ($this->invitiStaff as $segreto => $invito) {
            if ($persona !== null && $this->righeStaff[$invito['staff']]['utente_id'] === $persona && ! $invito['consumato']) {
                $trovato = $segreto;
            }
        }

        return $trovato;
    }

    /**
     * I codici di recupero ancora da usare di una persona dello staff.
     *
     * @param  array<string, mixed>|string  $staff  una persona dello staff di staffDi(), o il suo id
     * @return list<string>
     */
    public function codiciDiRecuperoDelloStaff(array|string $staff): array
    {
        $riga = $this->righeStaff[is_array($staff) ? (string) $staff['id'] : $staff] ?? throw new LogicException('Nel finto non c\'è questa persona dello staff.');

        return array_keys(array_filter($riga['recupero'], fn (bool $usato) => ! $usato));
    }

    /**
     * La prossima chiamata a `$operazione` (uno dei metodi `staff.*`), superata la guardia (client, gettone, ruolo) e il corpo, risponde con questo errore del catalogo, una
     * volta sola: per provare i rami d'errore che il finto non raggiunge da solo, come `ultimo_superadmin` (nel finto una chiamata dopo l'altra non lo produce: succede solo
     * con due richieste in gara) o `servizio_non_disponibile` (la base dell'invito che manca).
     *
     * @param  array<string, mixed>  $estensioni
     */
    public function fallisciLoStaff(string $operazione, string $codice, array $estensioni = []): self
    {
        if (! str_starts_with($operazione, 'staff.')) {
            throw new InvalidArgumentException("«{$operazione}» non è un metodo dello staff.");
        }

        $this->fallimentiStaff[$operazione] = ['codice' => $codice, 'estensioni' => $estensioni];

        return $this;
    }

    /**
     * Le righe del registro dello staff scritte finora, dalla prima.
     *
     * @return list<array<string, mixed>>
     */
    public function registroDelloStaff(): array
    {
        return $this->registroStaff;
    }

    // ── Ingresso ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * staff.accessi.crea: il primo passo. Risponde sempre con una sfida di 5 minuti, vera solo se email, password, dominio e riga dello staff tornano (R16): chi sbaglia non
     * lo scopre qui. Cinque tentativi per email e venti per IP firmato in 15 minuti.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaAccessoDelloStaff(Request $richiesta, array $corpo): array
    {
        $this->riconosciLoStaff($richiesta);
        $dati = $this->testi->valida($corpo, self::credenziali());
        $this->eventualeFallimentoDelloStaff();
        $email = self::normalizza($dati['email']);
        $this->frena('staff-ingresso:'.$email, 5, self::FINESTRA_DELLO_STAFF);
        $this->frena('staff-ingresso-ip:'.($this->ipFirmato ?? 'anonimo'), 20, self::FINESTRA_DELLO_STAFF);

        $persona = $this->conCredenziali($email, $dati['password']);
        $staff = $persona === null ? null : $this->rigaDellaPersona($persona);
        // Dal login normale di Zeiras (#3445) il primo passo è vero solo per le persone di prova: per le altre è una sfida inventata, come ogni caso negativo.
        $staff = $staff !== null && $staff['solo_uat'] && $this->emailAmmessaDalloStaff($email, true) ? $staff : null;
        $id = bin2hex(random_bytes(32));
        $scade = now()->toImmutable()->addMinutes(5)->startOfMillisecond();

        if ($staff !== null) {
            $this->sfideStaff[$id] = ['staff' => $staff['id'], 'scade_il' => $scade, 'errori' => 0, 'ip' => $this->ipFirmato ?? 'anonimo'];
        }

        return [200, ['data' => ['sfida' => ['id' => $id, 'scade_il' => self::iso($scade)]]]];
    }

    /**
     * staff.accessi.verifica.crea: il secondo passo, con la sfida e il codice del TOTP (`000000`, una volta per passo) o uno di recupero (una volta sola). Un solo errore,
     * `secondo_fattore_non_valido`, per ogni caso: sfida sconosciuta, scaduta, esaurita (cinque codici sbagliati), di un altro IP, codice sbagliato o già usato. Dieci
     * codici sbagliati in 24 ore per persona bloccano: da lì lo stesso errore, anche col codice giusto, senza 429 né minuti (M2).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function verificaAccessoDelloStaff(Request $richiesta, array $corpo): array
    {
        $this->riconosciLoStaff($richiesta);
        $dati = $this->testi->valida($corpo, ['sfida' => ['bail', 'required', 'string', 'max:128'], 'codice' => ['bail', 'required', 'string', 'max:32']]);
        $this->eventualeFallimentoDelloStaff();
        $this->frena('staff-verifica-ip:'.($this->ipFirmato ?? 'anonimo'), 20, self::FINESTRA_DELLO_STAFF);

        $sfida = $this->sfideStaff[$dati['sfida']] ?? null;
        $staff = $sfida === null ? null : ($this->righeStaff[$sfida['staff']] ?? null);

        if ($sfida === null || $staff === null || $staff['archiviato_il'] !== null || ! $sfida['scade_il']->gt(now()) || $sfida['errori'] >= 5
            || $sfida['ip'] !== ($this->ipFirmato ?? 'anonimo') || $staff['totp_confermato_il'] === null
            || ! $this->emailAmmessaDalloStaff($this->persone[$staff['utente_id']]['email'], $staff['solo_uat'])) {
            throw new Problema('secondo_fattore_non_valido');
        }

        $persona = 'staff-fattore:'.$staff['id'];

        // Come il backoffice (M2): la persona bloccata risponde come una sfida inventata, senza il 429 né i minuti.
        if ($this->freni->hit($persona, self::GIORNO_DELLO_STAFF) > 10) {
            throw new Problema('secondo_fattore_non_valido');
        }

        $this->sfideStaff[$dati['sfida']]['errori']++;
        $prova = $this->provaIlCodiceDelloStaff($staff, $dati['codice']);

        if ($prova === null) {
            throw new Problema('secondo_fattore_non_valido');
        }

        // Come il backoffice (RateLimiter::decrement): un secondo fattore riuscito toglie il solo colpo di questo tentativo, non azzera i precedenti.
        $this->freni->decrement($persona);
        unset($this->sfideStaff[$dati['sfida']]);

        if ($prova === 'totp') {
            $this->righeStaff[$staff['id']]['ultimo_passo'] = self::passoDelloStaff();
        } else {
            $this->righeStaff[$staff['id']]['recupero'][self::codiceDiRecuperoPulito($dati['codice'])] = true;
        }

        $gettone = $this->emettiIlGettoneDelloStaff($staff['id'], 'staff');
        $this->scriviNelRegistroDelloStaff($staff, 'staff.accessi.verifica.crea', 'staff', $staff['id']);

        return [201, ['data' => $this->gettoneDelloStaff($gettone) + ['id' => $staff['id']] + $this->nomeDelloStaff($staff) + ['ruolo' => $staff['ruolo']]]];
    }

    /**
     * staff.inviti.accettazione.crea: il segreto dell'invito e la password danno il gettone di enrolment (15 minuti, uno per persona). Ogni caso negativo è lo stesso
     * 401 invito_non_valido: segreto sconosciuto, scaduto, già consumato, password sbagliata, persona tolta dallo staff. Cinque tentativi per segreto e venti per IP.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function accettaLInvitoDelloStaff(Request $richiesta, array $corpo): array
    {
        $this->riconosciLoStaff($richiesta);
        $dati = $this->testi->valida($corpo, ['segreto' => ['bail', 'required', 'string', 'max:128'], 'password' => ['required', 'string', new SenzaCarattereNullo]]);
        $this->eventualeFallimentoDelloStaff();
        $this->frena('staff-invito-ip:'.($this->ipFirmato ?? 'anonimo'), 20, self::FINESTRA_DELLO_STAFF);
        $this->frena('staff-invito:'.hash('sha256', $dati['segreto']), 5, self::FINESTRA_DELLO_STAFF);

        $invito = $this->invitiStaff[$dati['segreto']] ?? null;
        $staff = $invito === null ? null : ($this->righeStaff[$invito['staff']] ?? null);

        // Dal login normale di Zeiras (#3445) l'invito vale solo per le persone di prova: per ogni altra riga è 404, come il metodo che non c'è più.
        if ($staff !== null && ! $staff['solo_uat']) {
            throw new Problema('non_trovato');
        }

        if ($invito === null || $staff === null || $staff['archiviato_il'] !== null || $invito['consumato'] || ! $invito['scade_il']->gt(now())
            || $this->conCredenziali($this->persone[$staff['utente_id']]['email'], $dati['password']) !== $staff['utente_id']) {
            throw new Problema('invito_non_valido');
        }

        foreach ($this->gettoniStaff as $gettone => $riga) {
            if ($riga['staff'] === $staff['id'] && $riga['tipo'] === 'enrolment') {
                $this->gettoniStaff[$gettone]['chiuso'] = true;
            }
        }

        $gettone = $this->emettiIlGettoneDelloStaff($staff['id'], 'enrolment');
        $this->scriviNelRegistroDelloStaff($staff, 'staff.inviti.accettazione.crea', 'staff', $staff['id']);

        return [201, ['data' => ['gettone' => $gettone, 'scade_il' => self::iso($this->gettoniStaff[$gettone]['creato_il']->addMinutes(15))]]];
    }

    /**
     * ingressi.staff.crea (IngressiController::staffCrea, #3445): il codice monouso per entrare in `admin` con il login normale di Zeiras, per la persona del gettone dell'accesso
     * (il gettone di un workspace è 403 gettone_con_workspace, prima del corpo). Entra chi ha una riga viva (non archiviata, non di prova, con l'email ancora ammessa); chi non ce l'ha
     * ma ha l'email verificata su un dominio di staffDaiDomini() riceve la riga alla prima volta. Ogni altro caso è la stessa 403 permesso_negato (R16). Il ritorno è quello dell'app
     * `admin` (ritorno()), https e senza `#`, altrimenti 503. Dieci codici al minuto per persona.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaIngressoStaff(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);

        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }

        $campi = $this->testi->validaStretta($corpo, [
            'app' => ['required', 'string', Rule::in(['admin'])],
            'sfida' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ]);
        $this->frena('ingressi-staff:'.$chi['persona'], 10, self::MINUTO);

        $persona = (string) $chi['persona'];
        $riga = $this->rigaDellaPersona($persona);
        $email = $this->persone[$persona]['email'];

        if ($riga !== null) {
            if ($riga['solo_uat'] || ! $this->emailAmmessaDalloStaff($email, false)) {
                throw new Problema('permesso_negato');
            }
        } elseif (! $this->dalDominioDelloStaff($persona)) {
            throw new Problema('permesso_negato');
        }

        $ritorno = $this->ritorni['admin'] ?? '';

        if (! str_starts_with($ritorno, 'https://') || str_contains($ritorno, '#')) {
            throw new Problema('servizio_non_disponibile');
        }

        // La riga nasce alla prima volta, con la sua riga nel registro (nella transazione del backoffice).
        if ($riga === null) {
            $id = self::id();
            $this->righeStaff[$id] = [
                'id' => $id, 'utente_id' => $persona, 'ruolo' => $this->ruoloDelDominioDelloStaff, 'solo_uat' => false, 'totp' => null, 'totp_confermato_il' => null, 'ultimo_passo' => null,
                'creato_il' => now()->toImmutable(), 'creato_da' => null, 'archiviato_il' => null, 'recupero' => [],
            ];
            $this->scriviNelRegistroDelloStaff($this->righeStaff[$id], 'ingressi.staff.crea', 'staff', $id, ['ruolo']);
        }

        $codice = self::base64url(random_bytes(32));
        $scade = now()->getTimestamp() + self::SECONDI_DELL_INGRESSO;
        $this->ingressi[$codice] = ['accesso' => $chi['accesso'], 'workspace' => null, 'app' => 'admin', 'sfida' => (string) $campi['sfida'], 'scade' => $scade, 'staff' => true];

        return [201, ['data' => ['codice' => $codice, 'ritorno' => $ritorno, 'scade_il' => self::iso(\Carbon\CarbonImmutable::createFromTimestampUTC($scade))]]];
    }

    /**
     * Lo scambio del codice dello staff (IngressoSso::scambia, #3445), dentro ingressi.scambio.crea: il verificatore dà la sfida e il client è `superadmin` (o il generico dei test), mai uno
     * di sistema; la riga dev'essere ancora viva. Dà il gettone dello staff nella forma di staff.accessi.verifica.crea. Ogni altro caso è la solita 422 verifica_non_riuscita.
     *
     * @param  array<string, mixed>  $ingresso
     * @param  array<string, mixed>  $campi
     * @return array{int, array<string, mixed>}
     */
    private function apriLoStaff(array $ingresso, array $campi): array
    {
        $accesso = $this->accessi[$ingresso['accesso']] ?? null;
        $staff = $accesso === null || $accesso['utente'] === null ? null : $this->rigaDellaPersona($accesso['utente']);

        if ($ingresso['scade'] <= now()->getTimestamp()
            || ! isset($campi['verificatore']) || ! hash_equals($ingresso['sfida'], self::base64url(hash('sha256', (string) $campi['verificatore'], true)))
            || ! in_array($this->clienteFirmato, [self::CLIENTE, self::CLIENT_DELLO_STAFF], true)
            || $accesso === null || $accesso['chiuso'] || ! $this->scadenza($accesso)->gt(now())
            || $staff === null || $staff['solo_uat'] || ! $this->emailAmmessaDalloStaff($this->persone[$staff['utente_id']]['email'], false)) {
            throw new Problema('verifica_non_riuscita', verifica: 'ingresso');
        }

        $gettone = $this->emettiIlGettoneDelloStaff($staff['id'], 'staff');
        $this->scriviNelRegistroDelloStaff($staff, 'ingressi.scambio.crea', 'staff', $staff['id']);

        return [201, ['data' => $this->gettoneDelloStaff($gettone) + ['id' => $staff['id']] + $this->nomeDelloStaff($staff) + ['ruolo' => $staff['ruolo']]]];
    }

    /** L'email verificata della persona sta su un dominio dell'ingresso dal dominio (e su uno dello staff, non la casella `uat@`, senza `+`). */
    private function dalDominioDelloStaff(string $persona): bool
    {
        $dati = $this->persone[$persona];
        $email = self::normalizza($dati['email']);
        $dominio = substr($email, (int) strrpos($email, '@') + 1);

        return $this->dominiDelloStaff !== [] && $dati['email_verificata_il'] !== null && in_array($dominio, $this->dominiDelloStaff, true) && $this->emailAmmessaDalloStaff($email, false);
    }

    /**
     * Dal login normale di Zeiras (#3445) il secondo fattore, l'invito e l'enrolment valgono solo per le persone di prova: per ogni altra riga è 404 non_trovato.
     *
     * @param  array<string, mixed>  $riga
     */
    private function soloPerLeProve(array $riga): void
    {
        if (! $riga['solo_uat']) {
            throw new Problema('non_trovato');
        }
    }

    /**
     * staff.accessi.corrente.elimina: chiude il gettone dello staff che chiama.
     *
     * @return array{int, null}
     */
    private function eliminaAccessoDelloStaff(Request $richiesta): array
    {
        [$gettone, $staff] = $this->autenticaLoStaff($richiesta, self::RUOLI_DELLO_STAFF);
        $this->gettoniStaff[$gettone]['chiuso'] = true;
        $this->scriviNelRegistroDelloStaff($staff, 'staff.accessi.corrente.elimina', 'staff', $staff['id']);

        return [204, null];
    }

    /** @return array{int, array<string, mixed>} */
    private function mostraLoStaffCorrente(Request $richiesta): array
    {
        [$gettone, $staff] = $this->autenticaLoStaff($richiesta, self::RUOLI_DELLO_STAFF);

        $persona = $this->persone[$staff['utente_id']];

        return [200, ['data' => ['id' => $staff['id']] + $this->nomeDelloStaff($staff) + ['ruolo' => $staff['ruolo'], 'lingua' => $persona['lingua'], 'fuso_orario' => $persona['fuso_orario'], 'gettone' => $this->gettoneDelloStaff($gettone, false)]]];
    }

    // ── Il secondo fattore ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * staff.totp.crea: il segreto del TOTP, nuovo a ogni chiamata finché non è confermato. Col solo gettone di enrolment; chi lo ha già confermato è 403.
     *
     * @return array{int, array<string, mixed>}
     */
    private function avviaIlTotpDelloStaff(Request $richiesta): array
    {
        [, $staff] = $this->autenticaLoStaff($richiesta, self::RUOLI_DELLO_STAFF, true);
        $this->soloPerLeProve($staff);

        if ($staff['totp_confermato_il'] !== null) {
            throw new Problema('permesso_negato');
        }

        $segreto = '';

        foreach (range(1, 32) as $_) {
            $segreto .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0, 31)];
        }

        $this->righeStaff[$staff['id']]['totp'] = $segreto;
        $this->scriviNelRegistroDelloStaff($staff, 'staff.totp.crea', 'staff', $staff['id']);
        $email = $this->persone[$staff['utente_id']]['email'];

        return [201, ['data' => ['segreto' => $segreto, 'otpauth' => 'otpauth://totp/'.rawurlencode('Zeiras:'.$email).'?'.http_build_query(['secret' => $segreto, 'issuer' => 'Zeiras', 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30])]]];
    }

    /**
     * staff.totp.conferma.crea: il codice (`000000`) conferma il secondo fattore, consuma l'invito, chiude il gettone di enrolment e dà i dieci codici di recupero, una
     * volta sola. Sbagliato, o senza un segreto avviato: `secondo_fattore_non_valido`; al quinto errore il gettone di enrolment si chiude (poi 401).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function confermaIlTotpDelloStaff(Request $richiesta, array $corpo): array
    {
        [$gettone, $staff] = $this->autenticaLoStaff($richiesta, self::RUOLI_DELLO_STAFF, true);
        $this->soloPerLeProve($staff);
        $dati = $this->testi->valida($corpo, ['codice' => ['bail', 'required', 'string', 'max:32']]);
        $errori = ++$this->gettoniStaff[$gettone]['errori'];

        if ($staff['totp'] === null || $staff['totp_confermato_il'] !== null || $dati['codice'] !== self::CODICE_TOTP_DELLO_STAFF) {
            if ($errori >= 5) {
                $this->gettoniStaff[$gettone]['chiuso'] = true;
            }

            throw new Problema('secondo_fattore_non_valido');
        }

        $this->righeStaff[$staff['id']]['totp_confermato_il'] = now()->toImmutable();
        $this->righeStaff[$staff['id']]['ultimo_passo'] = self::passoDelloStaff();

        foreach ($this->invitiStaff as $segreto => $invito) {
            if ($invito['staff'] === $staff['id']) {
                $this->invitiStaff[$segreto]['consumato'] = true;
            }
        }

        $this->gettoniStaff[$gettone]['chiuso'] = true;
        $codici = $this->rigeneraIRecuperi($staff['id']);
        $this->scriviNelRegistroDelloStaff($staff, 'staff.totp.conferma.crea', 'staff', $staff['id']);

        return [201, ['data' => ['codici_di_recupero' => $codici]]];
    }

    /**
     * staff.totp.codici-di-recupero.crea (#3443): la persona del gettone dello staff rigenera i propri dieci codici col codice attuale dell'app (`000000`); i vecchi non valgono più.
     * Dal login normale di Zeiras (#3445) vale solo per le persone di prova: per ogni altra riga è 404. Un codice sbagliato, o l'ultimo passo già usato, è `secondo_fattore_non_valido`
     * e conta su un freno suo (decisione zr-pm 9/11): cinque tentativi in un'ora per gettone, poi `429` `troppe_richieste` anche per il codice giusto, e non sul freno dell'accesso.
     * Un codice di recupero non vale qui.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function rigeneraICodiciDelloStaff(Request $richiesta, array $corpo): array
    {
        [$gettone, $staff] = $this->autenticaLoStaff($richiesta, self::RUOLI_DELLO_STAFF);
        $this->soloPerLeProve($staff);
        $dati = $this->testi->valida($corpo, ['codice' => ['bail', 'required', 'string', 'max:32']]);
        $chiave = 'staff-codici:'.$gettone;

        $this->frena($chiave, 5, 3600);

        if ($dati['codice'] !== self::CODICE_TOTP_DELLO_STAFF || $staff['ultimo_passo'] === self::passoDelloStaff()) {
            throw new Problema('secondo_fattore_non_valido');
        }

        $this->freni->decrement($chiave);
        $this->righeStaff[$staff['id']]['ultimo_passo'] = self::passoDelloStaff();
        $codici = $this->rigeneraIRecuperi($staff['id']);
        $this->scriviNelRegistroDelloStaff($staff, 'staff.totp.codici-di-recupero.crea', 'staff', $staff['id']);

        return [201, ['data' => ['codici_di_recupero' => $codici]]];
    }

    // ── Le persone dello staff ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{int, array<string, mixed>} */
    private function elencaLoStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $this->contaLaLettura($chi);

        return $this->pagina(
            $richiesta, 'staff', 'id',
            fn () => array_map(fn (array $riga) => $this->voceDelloStaff($riga['id']), $this->righeDelPerimetro($chi)),
            fn (array $voce) => [$voce['id']],
            fn (string $chiave) => [$chiave],
        );
    }

    /** @return array{int, array<string, mixed>} */
    private function mostraUnoDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);

        return [200, ['data' => $this->voceDelloStaff($this->bersaglioDelloStaff($chi, $id)['id'])]];
    }

    /**
     * staff.crea: una persona che ha già un account verificato entra nello staff, senza invito né posta (#3445): entra con ingressi.staff.crea. Il dominio si controlla prima di guardare
     * se l'account c'è, così l'esito non dice se un'email fuori dominio ha un account; fuori dal perimetro di chi chiama la persona «non c'è».
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaUnoDelloStaff(Request $richiesta, array $corpo): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $dati = $this->testi->validaStretta($corpo, ['email' => self::REGOLE_EMAIL, 'ruolo' => ['bail', 'required', 'string', 'in:'.implode(',', self::RUOLI_DELLO_STAFF)]]);
        $email = self::normalizza($dati['email']);
        $dentro = ! $chi['solo_uat'] || $this->emailDelPerimetroUat($email);

        if ($dentro && ! $this->emailAmmessaDalloStaff($email, false)) {
            throw new Problema('dominio_non_ammesso');
        }

        $persona = $dentro ? $this->conEmail($email) : null;

        // Il metodo non crea mai una persona (#3445): un account che non c'è o non ha l'email verificata è 404.
        if ($persona === null || $this->persone[$persona]['email_verificata_il'] === null) {
            throw new Problema('non_trovato');
        }

        if ($this->rigaDellaPersona($persona) !== null) {
            throw new Problema('staff_gia_presente');
        }

        $id = self::id();
        $this->righeStaff[$id] = [
            'id' => $id, 'utente_id' => $persona, 'ruolo' => $dati['ruolo'], 'solo_uat' => false, 'totp' => null, 'totp_confermato_il' => null, 'ultimo_passo' => null,
            'creato_il' => now()->toImmutable(), 'creato_da' => $chi['id'], 'archiviato_il' => null, 'recupero' => [],
        ];
        $this->scriviNelRegistroDelloStaff($chi, 'staff.crea', 'staff', $id, ['ruolo']);

        return [201, ['data' => $this->voceDelloStaff($id)]];
    }

    /**
     * staff.modifica: il ruolo di un'altra persona. Nessuno cambia il proprio (403); l'ultimo superadmin non lo perde (409); lo stesso ruolo di prima non scrive niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaUnoDelloStaff(Request $richiesta, array $corpo, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $dati = $this->testi->validaStretta($corpo, ['ruolo' => ['bail', 'required', 'string', 'in:'.implode(',', self::RUOLI_DELLO_STAFF)]]);
        $this->nonSeStessi($chi, $id);
        $bersaglio = $this->bersaglioDelloStaff($chi, $id);

        if ($bersaglio['ruolo'] !== $dati['ruolo']) {
            $this->nonRestiSenzaSuperadmin($bersaglio);
            $this->righeStaff[$bersaglio['id']]['ruolo'] = $dati['ruolo'];
            $this->scriviNelRegistroDelloStaff($chi, 'staff.modifica', 'staff', $bersaglio['id'], ['ruolo']);
        }

        return [200, ['data' => $this->voceDelloStaff($bersaglio['id'])]];
    }

    /**
     * staff.elimina: toglie una persona dallo staff (si archivia) e chiude i suoi gettoni. Nessuno toglie se stesso (403); l'ultimo superadmin resta (409).
     *
     * @return array{int, null}
     */
    private function eliminaUnoDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $this->nonSeStessi($chi, $id);
        $bersaglio = $this->bersaglioDelloStaff($chi, $id);
        $this->nonRestiSenzaSuperadmin($bersaglio);
        $this->righeStaff[$bersaglio['id']]['archiviato_il'] = now()->toImmutable();
        $this->chiudiIGettoniDelloStaff($bersaglio['id']);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.elimina', 'staff', $bersaglio['id']);

        return [204, null];
    }

    /**
     * staff.totp.reimpostazione.crea: rifà l'ingresso di un'altra persona da capo: secondo fattore e codici di recupero via, gettoni chiusi, un invito nuovo
     * (ultimoInvitoDelloStaff()). Il `motivo` è obbligatorio e va nel registro.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function reimpostaIlTotpDelloStaff(Request $richiesta, array $corpo, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $dati = $this->testi->validaStretta($corpo, ['motivo' => ['bail', 'required', 'string', 'in:'.implode(',', self::MOTIVI_DELLO_STAFF)]]);
        $this->nonSeStessi($chi, $id);
        $bersaglio = $this->bersaglioDelloStaff($chi, $id);
        $this->soloPerLeProve($bersaglio);
        $this->righeStaff[$bersaglio['id']]['totp'] = null;
        $this->righeStaff[$bersaglio['id']]['totp_confermato_il'] = null;
        $this->righeStaff[$bersaglio['id']]['ultimo_passo'] = null;
        $this->righeStaff[$bersaglio['id']]['recupero'] = [];
        $this->chiudiIGettoniDelloStaff($bersaglio['id']);
        $this->nuovoInvitoDelloStaff($bersaglio['id']);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.totp.reimpostazione.crea', 'staff', $bersaglio['id'], ['totp'], $dati['motivo']);

        return [201, ['data' => $this->voceDelloStaff($bersaglio['id'])]];
    }

    // ── I numeri della Panoramica ────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * staff.utenti.conteggi.mostra (#2995): quante persone, per stato, solo numeri. `in_eliminazione` ha chiesto di eliminare l'account (io.elimina); il finto non anonimizza, quindi nessuna
     * persona esce dal conto. Superadmin e supporto; una persona `solo_uat` conta le persone `uat+*@castroandpartners.com`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function contaLePersoneDelloStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $persone = array_filter(array_keys($this->persone), fn (string $id) => ! $chi['solo_uat'] || $this->emailDelPerimetroUat($this->persone[$id]['email']));

        $inEliminazione = array_filter($persone, fn (string $id) => isset($this->eliminazioniPersona[$id]));
        $sospesi = array_filter($persone, fn (string $id) => isset($this->sospensioniStaff[$id]) && ! isset($this->eliminazioniPersona[$id]));

        return [200, ['data' => $this->conteggiPerStato(count($persone), count($inEliminazione), count($sospesi))]];
    }

    /**
     * staff.workspace.conteggi.mostra (#2995): quanti workspace, per stato, solo numeri. `in_eliminazione` ha l'eliminazione programmata viva. Una persona `solo_uat` conta i workspace
     * il cui proprietario ha un'email `uat+*@castroandpartners.com`, non quelli con lo slug `uat-`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function contaIWorkspaceDelloStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $workspace = array_filter(array_keys($this->workspace), fn (string $id) => ! $chi['solo_uat'] || $this->proprietarioDiProva($id));

        return [200, ['data' => $this->conteggiPerStato(count($workspace), count(array_filter($workspace, fn (string $id) => isset($this->eliminazioni[$id]) && $this->eliminazioni[$id]->isFuture())))]];
    }

    /** Un workspace il cui proprietario è una persona del perimetro di prova. */
    private function proprietarioDiProva(string $workspace): bool
    {
        foreach ($this->membri[$workspace] ?? [] as $persona => $ruolo) {
            if ($ruolo === 'proprietario' && isset($this->persone[$persona]) && $this->emailDelPerimetroUat($this->persone[$persona]['email'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * I numeri per stato; `$sospesi` solo per gli utenti (i workspace non si sospendono ancora): senza, la chiave `sospeso` non c'è.
     *
     * @return array<string, int>
     */
    private function conteggiPerStato(int $tutti, int $inEliminazione, ?int $sospesi = null): array
    {
        return ['tutti' => $tutti, 'attivo' => $tutti - $inEliminazione - ($sospesi ?? 0)]
            + ($sospesi === null ? [] : ['sospeso' => $sospesi])
            + ['in_eliminazione' => $inEliminazione];
    }

    // ── Il registro ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * staff.registro.elenca: il superadmin legge tutto, gli altri (e chi è solo_uat) le proprie righe; dal più recente, a cursore sulla posizione; i filtri della query.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaIlRegistroDelloStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, self::RUOLI_DELLO_STAFF);
        $this->contaLaLettura($chi);
        $regole = $this->regoleDelRegistro();

        return $this->pagina(
            $richiesta, 'staff.registro', 'id',
            fn () => array_map(fn (array $riga) => $this->voceDelRegistro($riga), $this->righeDelRegistro($chi, array_intersect_key(self::query($richiesta), $regole))),
            fn (array $voce) => [str_pad((string) $voce['posizione'], 12, '0', STR_PAD_LEFT)],
            // Il cursore porta l'id della riga (una stringa, come negli altri elenchi del finto); l'ordine è quello della posizione.
            function (string $id) {
                foreach ($this->registroStaff as $riga) {
                    if ($riga['id'] === $id) {
                        return [str_pad((string) $riga['posizione'], 12, '0', STR_PAD_LEFT)];
                    }
                }

                return null;
            },
            true,
            regole: $regole,
        );
    }

    /**
     * staff.registro.esportazioni.crea: tutto il registro che i filtri del corpo lasciano, in un CSV (solo ids, come il backoffice). Solo il superadmin.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, string, array<string, string>}
     */
    private function esportaIlRegistroDelloStaff(Request $richiesta, array $corpo): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $filtri = $this->testi->validaStretta($corpo, $this->regoleDelRegistro());
        $colonne = ['posizione', 'id', 'staff_id', 'ruolo', 'azione', 'bersaglio_tipo', 'bersaglio_id', 'campi', 'motivo', 'ip', 'impersonificazione_id', 'quando'];
        // La riga di questa esportazione si scrive prima, e il file arriva fino a lei: dalla più vecchia, come il backoffice.
        $this->scriviNelRegistroDelloStaff($chi, 'staff.registro.esportazioni.crea', 'registro', null);
        $righe = $this->righeDelRegistro($chi, $filtri);
        $file = implode(',', $colonne)."\r\n";

        foreach ($righe as $riga) {
            $celle = array_map(function (string $colonna) use ($riga) {
                $valore = $riga[$colonna];
                $testo = is_array($valore) ? json_encode($valore) : (string) ($valore instanceof \DateTimeInterface ? self::iso($valore) : ($valore ?? ''));

                // Una cella che comincia con = + - @ o un a capo non è una formula nel foglio di calcolo: un apostrofo davanti, come nel backoffice.
                return $testo !== '' && str_contains("=+-@\t\r\n", $testo[0]) ? "'".$testo : $testo;
            }, $colonne);
            $file .= implode(',', array_map(fn (string $cella) => preg_match('/[",\r\n]/', $cella) === 1 ? '"'.str_replace('"', '""', $cella).'"' : $cella, $celle))."\r\n";
        }

        return [200, "\xEF\xBB\xBF".$file, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="registro-staff-'.gmdate('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]];
    }

    // ── Sotto il cofano ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function regoleDelRegistro(): array
    {
        $id = ['sometimes', 'string', 'regex:/^[0-9A-Za-z]{26}$/'];

        return [
            'staff_id' => $id,
            'bersaglio_tipo' => ['sometimes', 'string', 'in:'.implode(',', self::BERSAGLI_DEL_REGISTRO)],
            'bersaglio_id' => $id,
            'azione' => ['sometimes', 'string', 'max:120'],
            'dal' => ['sometimes', 'string', 'date'],
            'al' => ['sometimes', 'string', 'date'],
        ];
    }

    /** Il fallimento preparato per l'operazione in corso, se c'è: si consuma. */
    private function eventualeFallimentoDelloStaff(): void
    {
        $fallimento = $this->fallimentiStaff[$this->operazioneInCorso] ?? null;

        if ($fallimento !== null) {
            unset($this->fallimentiStaff[$this->operazioneInCorso]);

            throw new Problema($fallimento['codice'], estensioni: $fallimento['estensioni']);
        }
    }

    /**
     * Ogni richiesta dello staff porta la firma del client, anche col gettone: senza i quattro header (o con una firma che non torna) è 401 cliente_non_riconosciuto.
     */
    private function riconosciLoStaff(Request $richiesta): void
    {
        foreach (['Zr-Cliente', 'Zr-Ip', 'Zr-Istante', 'Zr-Firma'] as $nome) {
            if (self::header($richiesta, $nome) === null) {
                throw new Problema('cliente_non_riconosciuto');
            }
        }

        $this->riconosciIlCliente($richiesta, $this->operazioneInCorso);
    }

    /**
     * La guardia di un metodo dello staff: il client firmato, poi il gettone (gettone_assente; sconosciuto, chiuso, scaduto, nato per un'altra cosa o di una persona tolta
     * dallo staff: gettone_non_valido), il tipo (permesso_negato: un gettone di una persona, o di enrolment fuori da `staff.totp.*`, o dello staff dentro), poi il ruolo (permesso_negato). Il gettone dello staff scade dopo 8 ore o 30 minuti senza chiamate, e ogni chiamata rinnova i 30 minuti;
     * quello di enrolment dopo 15 minuti e apre solo `staff.totp.*`, dove invece quello dello staff non vale (`$conEnrolment`).
     *
     * @param  list<string>  $ruoli
     * @return array{0: string, 1: array<string, mixed>} il gettone e la persona dello staff
     */
    private function autenticaLoStaff(Request $richiesta, array $ruoli, bool $conEnrolment = false): array
    {
        $this->riconosciLoStaff($richiesta);
        $gettone = self::gettoneDi($richiesta);

        if ($gettone === null || $gettone === '') {
            throw new Problema('gettone_assente', header: ['WWW-Authenticate' => 'Bearer realm="zeiras"']);
        }

        $riga = $this->gettoniStaff[$gettone] ?? null;
        $staff = $riga === null ? null : ($this->righeStaff[$riga['staff']] ?? null);

        // Un gettone di una persona (di accesso o di workspace) non apre lo staff: 403, come AmbitoDelGettone del backoffice, e non un 401.
        if ($riga === null && isset($this->gettoni[$gettone])) {
            throw new Problema('permesso_negato');
        }

        $limite = $riga === null ? null : $this->scadenzaDelGettoneDelloStaff($riga);

        if ($riga === null || $staff === null || $staff['archiviato_il'] !== null || $riga['chiuso'] || ! $limite->gt(now())) {
            throw new Problema('gettone_non_valido', header: ['WWW-Authenticate' => 'Bearer realm="zeiras", error="invalid_token"']);
        }

        // Il tipo giusto per il metodo (ConStaff): `staff.totp.*` vuole l'enrolment, tutti gli altri lo staff; l'altro è 403.
        if ($riga['tipo'] !== ($conEnrolment ? 'enrolment' : 'staff')) {
            throw new Problema('permesso_negato');
        }

        // L'inattività si rinnova prima del controllo del ruolo, come nel backoffice: un 403 la rinnova.
        $this->gettoniStaff[$gettone]['ultima'] = now()->toImmutable();

        if (! in_array($staff['ruolo'], $ruoli, true)) {
            throw new Problema('permesso_negato');
        }

        $this->eventualeFallimentoDelloStaff();

        return [$gettone, $staff];
    }

    /** @param  array{creato_il: \Carbon\CarbonImmutable, ultima: \Carbon\CarbonImmutable, tipo: string}  $riga */
    private function scadenzaDelGettoneDelloStaff(array $riga): \Carbon\CarbonImmutable
    {
        if ($riga['tipo'] === 'enrolment') {
            return $riga['creato_il']->addMinutes(15);
        }

        return min($riga['creato_il']->addHours(8), $riga['ultima']->addMinutes(30));
    }

    private function emettiIlGettoneDelloStaff(string $staff, string $tipo): string
    {
        $gettone = 'zr_'.Str::random(48);
        $this->gettoniStaff[$gettone] = ['staff' => $staff, 'tipo' => $tipo, 'creato_il' => now()->toImmutable()->startOfMillisecond(), 'ultima' => now()->toImmutable()->startOfMillisecond(), 'chiuso' => false, 'errori' => 0];

        if ($tipo === 'staff') {
            $this->righeStaff[$staff]['ultimo_ingresso'] = now()->toImmutable()->startOfMillisecond();
        }

        return $gettone;
    }

    /**
     * Il gettone dello staff come lo danno le risposte: con `$conGettone` il gettone stesso e le due scadenze, senza solo le scadenze (staff.corrente.mostra).
     *
     * @return array<string, string>
     */
    private function gettoneDelloStaff(string $gettone, bool $conGettone = true): array
    {
        $riga = $this->gettoniStaff[$gettone];

        return ($conGettone ? ['gettone' => $gettone] : [])
            + ['scade_il' => self::iso($riga['creato_il']->addHours(8)), 'inattivita_scade_il' => self::iso($riga['ultima']->addMinutes(30))];
    }

    private function chiudiIGettoniDelloStaff(string $staff): void
    {
        foreach ($this->gettoniStaff as $gettone => $riga) {
            if ($riga['staff'] === $staff) {
                $this->gettoniStaff[$gettone]['chiuso'] = true;
            }
        }
    }

    private function nuovoInvitoDelloStaff(string $staff): string
    {
        foreach ($this->invitiStaff as $segreto => $invito) {
            if ($invito['staff'] === $staff) {
                $this->invitiStaff[$segreto]['consumato'] = true;
            }
        }

        $segreto = bin2hex(random_bytes(24));
        $this->invitiStaff[$segreto] = ['staff' => $staff, 'scade_il' => now()->toImmutable()->addHours(24), 'consumato' => false];

        return $segreto;
    }

    /** @return list<string> i dieci codici di recupero nuovi, in chiaro; quelli di prima non valgono più */
    private function rigeneraIRecuperi(string $staff): array
    {
        $codici = [];

        // Come CodiciDiRecupero del backoffice: cinque più cinque caratteri maiuscoli senza I, O, 0 e 1 (i confronti ignorano maiuscole, trattini e spazi).
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        while (count($codici) < 10) {
            $codice = '';

            foreach (range(1, 10) as $_) {
                $codice .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }

            $codici[substr($codice, 0, 5).'-'.substr($codice, 5)] = false;
        }

        $this->righeStaff[$staff]['recupero'] = $codici;

        return array_keys($codici);
    }

    /**
     * Cosa combacia del codice: `totp` (il codice del finto, se il passo non è già stato usato), `recupero` (uno non ancora usato) o null.
     *
     * @param  array<string, mixed>  $staff
     */
    private function provaIlCodiceDelloStaff(array $staff, string $codice): ?string
    {
        if (preg_match('/^\d{6}$/', $codice) === 1) {
            return $codice === self::CODICE_TOTP_DELLO_STAFF && $staff['ultimo_passo'] !== self::passoDelloStaff() ? 'totp' : null;
        }

        $codice = self::codiceDiRecuperoPulito($codice);

        return isset($staff['recupero'][$codice]) && $staff['recupero'][$codice] === false ? 'recupero' : null;
    }

    private static function codiceDiRecuperoPulito(string $codice): string
    {
        $pulito = strtoupper(str_replace(['-', ' '], '', trim($codice)));

        return strlen($pulito) === 10 ? substr($pulito, 0, 5).'-'.substr($pulito, 5) : $pulito;
    }

    private static function passoDelloStaff(): int
    {
        return intdiv(now()->timestamp, 30);
    }

    /** @return array<string, mixed>|null */
    private function rigaDellaPersona(string $persona): ?array
    {
        foreach ($this->righeStaff as $riga) {
            if ($riga['utente_id'] === $persona && $riga['archiviato_il'] === null) {
                return $riga;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function rigaDelloStaff(string $id): array
    {
        return $this->righeStaff[$id] ?? throw new LogicException('Nel finto non c\'è questa persona dello staff.');
    }

    /**
     * L'email sta su un dominio dello staff (uguaglianza esatta dopo l'ultima `@`), non è la casella `uat@`, e non ha un `+` salvo `$soloUat`.
     */
    private function emailAmmessaDalloStaff(string $email, bool $soloUat): bool
    {
        $email = self::normalizza($email);
        $chiocciola = strrpos($email, '@');

        if ($chiocciola === false) {
            return false;
        }

        $locale = substr($email, 0, $chiocciola);

        return in_array(substr($email, $chiocciola + 1), self::DOMINI_DELLO_STAFF, true) && $locale !== 'uat' && ($soloUat || ! str_contains($locale, '+'));
    }

    /** Il perimetro di chi è `solo_uat`: utenti `uat+…@castroandpartners.com`. */
    private function emailDelPerimetroUat(string $email): bool
    {
        return preg_match('/^uat\+[^@\s]+@castroandpartners\.com$/', self::normalizza($email)) === 1;
    }

    /**
     * Le persone dello staff che chi chiama vede: tutte le vive, o, se è solo_uat, quelle del suo perimetro. Nell'ordine degli id.
     *
     * @param  array<string, mixed>  $chi
     * @return list<array<string, mixed>>
     */
    private function righeDelPerimetro(array $chi): array
    {
        $righe = array_values(array_filter($this->righeStaff, fn (array $riga) => $riga['archiviato_il'] === null
            && (! $chi['solo_uat'] || $this->emailDelPerimetroUat($this->persone[$riga['utente_id']]['email']))));
        usort($righe, fn (array $una, array $altra) => strcmp($una['id'], $altra['id']));

        return $righe;
    }

    /**
     * Il bersaglio di un metodo dello staff: 404 se non c'è, è archiviato o sta fuori dal perimetro (lo stesso per tutti: R16).
     *
     * @param  array<string, mixed>  $chi
     * @return array<string, mixed>
     */
    private function bersaglioDelloStaff(array $chi, string $id): array
    {
        foreach ($this->righeDelPerimetro($chi) as $riga) {
            if ($riga['id'] === strtolower($id)) {
                return $riga;
            }
        }

        throw new Problema('non_trovato');
    }

    /** @param  array<string, mixed>  $chi */
    private function nonSeStessi(array $chi, string $id): void
    {
        if ($chi['id'] === strtolower($id)) {
            throw new Problema('permesso_negato');
        }
    }

    /** @param  array<string, mixed>  $bersaglio */
    private function nonRestiSenzaSuperadmin(array $bersaglio): void
    {
        $altri = array_filter($this->righeStaff, fn (array $riga) => $riga['archiviato_il'] === null && $riga['ruolo'] === 'superadmin' && $riga['id'] !== $bersaglio['id']);

        if ($bersaglio['ruolo'] === 'superadmin' && $altri === []) {
            throw new Problema('ultimo_superadmin');
        }
    }

    /** @return array{nome: ?string, cognome: ?string, email: string} */
    private function nomeDelloStaff(array $staff): array
    {
        $persona = $this->persone[$staff['utente_id']];

        return ['nome' => $persona['nome'], 'cognome' => $persona['cognome'], 'email' => $persona['email']];
    }

    /** @return array<string, mixed> la persona dello staff come la danno le letture */
    private function voceDelloStaff(string $id): array
    {
        $staff = $this->righeStaff[$id];
        $persona = $this->persone[$staff['utente_id']];
        $creatore = $staff['creato_da'] === null ? null : ($this->righeStaff[$staff['creato_da']] ?? null);
        $creatoDa = $creatore === null ? null : ['nome' => $this->persone[$creatore['utente_id']]['nome'], 'cognome' => $this->persone[$creatore['utente_id']]['cognome']];

        return [
            'id' => $staff['id'],
            'utente_id' => $staff['utente_id'],
            'nome' => $persona['nome'],
            'cognome' => $persona['cognome'],
            'email' => $persona['email'],
            'ruolo' => $staff['ruolo'],
            'creato_il' => self::iso($staff['creato_il']),
            'creato_da' => $creatoDa,
            'ultimo_ingresso' => isset($staff['ultimo_ingresso']) ? self::iso($staff['ultimo_ingresso']) : null,
        ];
    }

    /**
     * Una riga nel registro dello staff: chi (la persona dello staff che agisce), cosa, su chi e quando. Nessun dato personale: i nomi si risolvono alla lettura.
     *
     * @param  array<string, mixed>  $chi
     * @param  list<string>|null  $campi
     */
    private function scriviNelRegistroDelloStaff(array $chi, string $azione, string $bersaglioTipo, ?string $bersaglioId, ?array $campi = null, ?string $motivo = null, ?string $impersonificazione = null): void
    {
        $posizione = count($this->registroStaff) + 1;
        $this->registroStaff[] = [
            'posizione' => $posizione,
            'id' => self::id(),
            'staff_id' => $chi['id'],
            'ruolo' => $chi['ruolo'],
            'azione' => $azione,
            'bersaglio_tipo' => $bersaglioTipo,
            'bersaglio_id' => $bersaglioId,
            'campi' => $campi,
            'motivo' => $motivo,
            'ip' => $this->ipFirmato ?? '0.0.0.0',
            'impersonificazione_id' => $impersonificazione,
            'quando' => now()->toImmutable()->startOfMillisecond(),
        ];
    }

    /**
     * Le righe del registro che chi chiama legge, dalla prima, coi filtri: il superadmin vero tutte; gli altri, e chi è solo_uat, le proprie.
     *
     * @param  array<string, mixed>  $chi
     * @param  array<string, mixed>  $filtri
     * @return list<array<string, mixed>>
     */
    private function righeDelRegistro(array $chi, array $filtri): array
    {
        return array_values(array_filter($this->registroStaff, function (array $riga) use ($chi, $filtri) {
            if (($chi['ruolo'] !== 'superadmin' || $chi['solo_uat']) && $riga['staff_id'] !== $chi['id']) {
                return false;
            }

            return (! isset($filtri['staff_id']) || $riga['staff_id'] === strtolower($filtri['staff_id']))
                && (! isset($filtri['bersaglio_tipo']) || $riga['bersaglio_tipo'] === $filtri['bersaglio_tipo'])
                && (! isset($filtri['bersaglio_id']) || $riga['bersaglio_id'] === strtolower($filtri['bersaglio_id']))
                && (! isset($filtri['azione']) || $riga['azione'] === $filtri['azione'])
                && (! isset($filtri['dal']) || $riga['quando']->gte(\Illuminate\Support\Carbon::parse($filtri['dal'])))
                && (! isset($filtri['al']) || $riga['quando']->lte(\Illuminate\Support\Carbon::parse($filtri['al'])));
        }));
    }

    /**
     * Una riga del registro come la dà staff.registro.elenca: i nomi di chi ha agito e del bersaglio si risolvono qui, alla lettura.
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function voceDelRegistro(array $riga): array
    {
        $dellaPersona = function (?string $staff): ?array {
            $riga = $staff === null ? null : ($this->righeStaff[$staff] ?? null);

            return $riga === null || $riga['archiviato_il'] !== null ? null : ['nome' => $this->persone[$riga['utente_id']]['nome'], 'cognome' => $this->persone[$riga['utente_id']]['cognome']];
        };

        return [
            'posizione' => $riga['posizione'],
            'id' => $riga['id'],
            'staff_id' => $riga['staff_id'],
            'ruolo' => $riga['ruolo'],
            'azione' => $riga['azione'],
            'bersaglio_tipo' => $riga['bersaglio_tipo'],
            'bersaglio_id' => $riga['bersaglio_id'],
            'campi' => $riga['campi'],
            'motivo' => $riga['motivo'],
            'ip' => $riga['ip'],
            'impersonificazione_id' => $riga['impersonificazione_id'],
            'quando' => self::iso($riga['quando']),
            'staff' => $dellaPersona($riga['staff_id']),
            'bersaglio' => match ($riga['bersaglio_tipo']) {
                'staff' => $dellaPersona($riga['bersaglio_id']),
                'utente' => isset($this->persone[$riga['bersaglio_id']]) ? ['nome' => $this->persone[$riga['bersaglio_id']]['nome'], 'cognome' => $this->persone[$riga['bersaglio_id']]['cognome']] : null,
                default => null,
            },
        ];
    }
}
