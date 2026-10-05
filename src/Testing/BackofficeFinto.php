<?php

namespace Zeiras\Auth\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Zeiras\Auth\Testing\Finto\Problema;
use Zeiras\Auth\Testing\Finto\RichiestaSconosciuta;
use Zeiras\Auth\Testing\Finto\SenzaCarattereNullo;
use Zeiras\Auth\Testing\Finto\Testi;

/**
 * Il backoffice finto, per i test dei frontend (nota 5721): risponde alle chiamate di /v1 come zr-backoffice, senza rete
 * e senza database, con le forme del contratto, i codici del catalogo, i testi del backoffice e i suoi freni. Che risponda
 * come il contratto lo prova la CI del backoffice, che passa ogni sua risposta alla validazione del contratto vero (D17).
 *
 *     $finto = BackofficeFinto::attiva();
 *     $anna = $finto->persona('anna@example.com', 'una password lunga e sicura');
 *     $studio = $finto->workspace('Studio Anna', $anna);
 *
 * I dati nascono dai metodi del finto, mai da campi comodi nelle risposte: il codice di verifica si legge da
 * ultimoCodice(), che fa da casella di posta. Il tempo è now(): un test lo sposta con travel(). Una chiamata di /v1 che il
 * finto non conosce lancia RichiestaSconosciuta; una chiamata che non va alle API resta agli altri Http::fake del test.
 */
final class BackofficeFinto
{
    private const DOCUMENTAZIONE = 'https://docs.zeiras.com/v1/';

    /** I metodi che il finto fa: verbo, percorso, operationId. */
    private const METODI = [
        ['POST', '#^/v1/accessi$#', 'accessi.crea'],
        ['DELETE', '#^/v1/accessi/([^/]+)$#', 'accessi.elimina'],
        ['POST', '#^/v1/gettoni$#', 'gettoni.crea'],
        ['POST', '#^/v1/io/email/codice$#', 'io.email.codice.crea'],
        ['POST', '#^/v1/io/email/verifica$#', 'io.email.verifica.crea'],
    ];

    /** Quante ore valgono i gettoni di un accesso, dalla sua nascita (Gettoni::ORE). */
    private const ORE = 12;

    /** I freni per email dei metodi senza gettone (config zeiras.freni del backoffice): richieste in un minuto. */
    private const FRENI = ['accessi' => 5, 'codici' => 5, 'verifiche' => 5];

    private const MINUTO = 60;

    /** I freni degli invii dei codici di una persona, contando quello della nascita: invii, secondi (VerificaEmail). */
    private const INVII = ['invio' => [1, 60], 'ora' => [5, 3600], 'giorno' => [10, 86400]];

    /** Quanto vale un codice, e quanti errori ammette (VerificaEmail::MINUTI, TENTATIVI, ERRORI_AL_GIORNO). */
    private const MINUTI_DEL_CODICE = 10;

    private const TENTATIVI = 5;

    private const ERRORI_AL_GIORNO = 10;

    private const RUOLI = ['proprietario', 'amministratore', 'membro'];

    /** Utente::REGOLE_EMAIL del backoffice. */
    private const REGOLE_EMAIL = ['bail', 'required', 'string', 'max:254', 'email:rfc,filter'];

    /** @var array<string, array{nome: string, email: string, email_verificata_il: ?CarbonImmutable, lingua: string, fuso_orario: string, password: string}> per id */
    private array $persone = [];

    /** @var array<string, array{utente: string, creato_il: CarbonImmutable, chiuso: bool}> per id */
    private array $accessi = [];

    /** @var array<string, array{accesso: string, workspace: ?string}> per gettone, in chiaro: il finto vive nella memoria del test */
    private array $gettoni = [];

    /** @var array<string, array{id: string, nome: string}> per id */
    private array $workspace = [];

    /** @var array<string, array<string, string>> il ruolo, per workspace e per persona */
    private array $membri = [];

    /** @var array<string, array{codice: string, tentativi: int, scade: int}> il codice che vale, per persona */
    private array $codici = [];

    /** @var array<string, string> l'ultimo codice partito, per email */
    private array $posta = [];

    /** I freni, con RateLimiter come nel backoffice: in memoria, e col tempo di now(). */
    private readonly RateLimiter $freni;

    private readonly Testi $testi;

    private function __construct()
    {
        $this->freni = new RateLimiter(new Repository(new ArrayStore));
        $this->testi = new Testi;
    }

    /** Accende il finto: da qui le chiamate alle API di `zr-auth.api` le riceve lui. */
    public static function attiva(): self
    {
        $finto = new self;
        Http::fake(fn (Request $richiesta) => $finto->risponde($richiesta));

        return $finto;
    }

    /**
     * Fa nascere una persona, con l'email già verificata o da verificare: allora le parte il primo codice, come alla
     * registrazione, e conta fra i suoi invii.
     *
     * @return array<string, mixed> la persona, come la dà il backoffice (lo schema Utente)
     */
    public function persona(string $email, #[SensitiveParameter] string $password, string $nome = 'Anna', string $lingua = 'it', bool $verificata = true): array
    {
        $email = self::normalizza($email);

        if (! in_array($lingua, Testi::LINGUE, true)) {
            throw new InvalidArgumentException("Zeiras non parla «{$lingua}»: le sue lingue sono ".implode(', ', Testi::LINGUE).'.');
        }

        if ($this->conEmail($email) !== null) {
            throw new LogicException("Nel finto c'è già una persona con l'email {$email}.");
        }

        $id = self::id();
        $this->persone[$id] = [
            'nome' => $nome,
            'email' => $email,
            'email_verificata_il' => $verificata ? now()->toImmutable() : null,
            'lingua' => $lingua,
            'fuso_orario' => 'Europe/Rome',
            'password' => $password,
        ];

        if (! $verificata) {
            $this->chiediCodice($id);
        }

        return $this->utente($id);
    }

    /**
     * Fa nascere un workspace, con la persona come proprietaria.
     *
     * @param  array<string, mixed>  $proprietaria  una persona di persona()
     * @return array{id: string, nome: string}
     */
    public function workspace(string $nome, array $proprietaria): array
    {
        $id = self::id();
        $this->workspace[$id] = ['id' => $id, 'nome' => $nome];
        $this->membro($this->workspace[$id], $proprietaria, 'proprietario');

        return $this->workspace[$id];
    }

    /**
     * Mette una persona in un workspace, col ruolo: proprietario, amministratore o membro.
     *
     * @param  array<string, mixed>  $workspace  un workspace di workspace()
     * @param  array<string, mixed>  $persona  una persona di persona()
     */
    public function membro(array $workspace, array $persona, string $ruolo): void
    {
        $idWorkspace = $workspace['id'] ?? null;
        $idPersona = $persona['id'] ?? null;

        if (! is_string($idWorkspace) || ! isset($this->workspace[$idWorkspace])) {
            throw new InvalidArgumentException('Il workspace non è del finto: nasce con workspace().');
        }

        if (! is_string($idPersona) || ! isset($this->persone[$idPersona])) {
            throw new InvalidArgumentException('La persona non è del finto: nasce con persona().');
        }

        if (! in_array($ruolo, self::RUOLI, true)) {
            throw new InvalidArgumentException("Il ruolo «{$ruolo}» non c'è: i ruoli sono ".implode(', ', self::RUOLI).'.');
        }

        $this->membri[$idWorkspace][$idPersona] = $ruolo;
    }

    /** L'ultimo codice di verifica partito per l'email, null se nessuno: la casella di posta del finto. */
    public function ultimoCodice(string $email): ?string
    {
        return $this->posta[self::normalizza($email)] ?? null;
    }

    /** La risposta a una chiamata: null se non va alle API /v1, e allora resta agli altri Http::fake del test. */
    private function risponde(Request $richiesta): ?PromiseInterface
    {
        $api = rtrim((string) config('zr-auth.api'), '/');
        $indirizzo = explode('?', $richiesta->url(), 2)[0];

        if (! str_starts_with($indirizzo, $api.'/')) {
            return null;
        }

        // Come il router di Laravel, il percorso senza la barra finale.
        $percorso = '/'.trim(substr($indirizzo, strlen($api)), '/');

        if ($percorso !== '/v1' && ! str_starts_with($percorso, '/v1/')) {
            return null;
        }

        foreach (self::METODI as [$verbo, $forma, $operazione]) {
            if ($richiesta->method() === $verbo && preg_match($forma, $percorso, $parti) === 1) {
                return $this->esegue($operazione, $richiesta, array_map(rawurldecode(...), array_slice($parti, 1)));
            }
        }

        throw new RichiestaSconosciuta($richiesta->method(), $percorso);
    }

    /**
     * Esegue un metodo, e scrive la risposta come il backoffice: JSON, o un problema di RFC 9457 coi testi nella lingua
     * della richiesta (o della persona del gettone), e su ogni risposta il Link alla pagina del metodo.
     *
     * @param  list<string>  $parametri  i parametri del percorso
     */
    private function esegue(string $operazione, Request $richiesta, array $parametri): PromiseInterface
    {
        $this->testi->usa(Testi::dellaRichiesta(self::header($richiesta, 'Accept-Language')));
        $corpo = $richiesta->data();
        $corpo = is_array($corpo) ? $corpo : [];

        try {
            [$stato, $dati] = match ($operazione) {
                'accessi.crea' => $this->creaAccesso($corpo),
                'accessi.elimina' => $this->eliminaAccesso($richiesta, $parametri[0]),
                'gettoni.crea' => $this->creaGettone($richiesta, $corpo),
                'io.email.codice.crea' => $this->creaCodice($corpo),
                'io.email.verifica.crea' => $this->verificaEmail($corpo),
            };
            $header = $dati === null ? [] : ['Content-Type' => 'application/json'];
        } catch (Problema $problema) {
            [$stato, $dati] = [$problema->stato(), $this->testi->problema($problema)];
            $header = ['Content-Type' => 'application/problem+json', ...$problema->header];
        }

        $header['Link'] = '<'.self::DOCUMENTAZIONE.$operazione.'>; rel="describedby"';

        return Http::response($dati === null ? null : json_encode($dati, JSON_THROW_ON_ERROR), $stato, $header);
    }

    /**
     * accessi.crea (AccessiController::crea): l'accesso e il suo gettone, senza workspace. Il freno dell'email si conta
     * prima delle credenziali, e un accesso riuscito lo azzera.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaAccesso(array $corpo): array
    {
        $dati = $this->testi->valida($corpo, self::credenziali());
        $email = self::normalizza($dati['email']);
        $freno = 'accessi:'.$email;
        $this->frena($freno, self::FRENI['accessi'], self::MINUTO);

        $persona = $this->conCredenziali($email, $dati['password']);

        if ($persona === null || $this->persone[$persona]['email_verificata_il'] === null) {
            throw new Problema('credenziali_non_valide');
        }

        $this->freni->clear($freno);
        $accesso = self::id();
        $this->accessi[$accesso] = ['utente' => $persona, 'creato_il' => now()->toImmutable(), 'chiuso' => false];

        return [201, ['data' => [
            'id' => $accesso,
            'creato_il' => self::iso($this->accessi[$accesso]['creato_il']),
            'gettone' => $this->emetti($accesso, null),
        ]]];
    }

    /**
     * accessi.elimina (AccessiController::elimina): chiude un accesso della persona del gettone, e con lui i suoi gettoni.
     * Un accesso di un'altra persona, o già chiuso, è 404 come uno che non esiste.
     *
     * @return array{int, null}
     */
    private function eliminaAccesso(Request $richiesta, string $accesso): array
    {
        $chi = $this->autentica($richiesta);
        $riga = $this->accessi[$accesso] ?? null;

        if ($riga === null || $riga['utente'] !== $chi['persona'] || $riga['chiuso']) {
            throw new Problema('non_trovato');
        }

        $this->accessi[$accesso]['chiuso'] = true;

        return [204, null];
    }

    /**
     * gettoni.crea (GettoniController::crea): il gettone di un workspace della persona, dallo stesso accesso. Al gettone
     * di un workspace 403, prima del corpo; un workspace non suo è lo stesso 422 di uno che non esiste.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaGettone(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);

        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }

        $workspace = $this->testi->valida($corpo, ['workspace_id' => ['required', 'string']])['workspace_id'];

        if (! isset($this->membri[$workspace][$chi['persona']])) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.workspace_non_tuo'), 'pointer' => '#/workspace_id']]);
        }

        return [201, ['data' => $this->emetti($chi['accesso'], $workspace)]];
    }

    /**
     * io.email.codice.crea (CodiciEmailController::crea): 202 con l'email, la stessa risposta in ogni caso. Il codice
     * parte solo a email e password di una persona da verificare, e se i freni degli invii lo ammettono.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaCodice(array $corpo): array
    {
        $dati = $this->testi->valida($corpo, self::credenziali());
        $email = self::normalizza($dati['email']);
        $this->frena('codici:'.$email, self::FRENI['codici'], self::MINUTO);

        $persona = $this->conCredenziali($email, $dati['password']);

        if ($persona !== null && $this->persone[$persona]['email_verificata_il'] === null) {
            $this->chiediCodice($persona);
        }

        return [202, ['data' => ['email' => $email]]];
    }

    /**
     * io.email.verifica.crea (VerificheEmailController::crea): 200 con la persona verificata a email, password e codice
     * giusti; ogni altra richiesta è lo stesso 422. Un tentativo sul codice si conta solo con la password giusta.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function verificaEmail(array $corpo): array
    {
        $dati = $this->testi->valida($corpo, self::credenziali() + ['codice' => ['required', 'string', 'digits:6']]);
        $email = self::normalizza($dati['email']);
        $this->frena('verifiche:'.$email, self::FRENI['verifiche'], self::MINUTO);

        $persona = $this->conCredenziali($email, $dati['password']);

        if ($persona === null || $this->persone[$persona]['email_verificata_il'] !== null || ! $this->provaCodice($persona, $dati['codice'])) {
            throw new Problema('verifica_non_riuscita');
        }

        return [200, ['data' => $this->utente($persona)]];
    }

    /**
     * Conta una richiesta nel freno (Freno::conta del backoffice): oltre `$massimo` nella finestra, che parte dal primo
     * colpo, 429 troppe_richieste con Retry-After, i secondi alla fine della finestra. Conta anche la richiesta frenata.
     */
    private function frena(string $chiave, int $massimo, int $secondi): void
    {
        if ($this->freni->hit($chiave, $secondi) > $massimo) {
            throw new Problema('troppe_richieste', header: ['Retry-After' => (string) $this->freni->availableIn($chiave)]);
        }
    }

    /**
     * La persona del gettone della richiesta (la guardia del backoffice): senza un gettone 401 gettone_assente; con un
     * gettone che non è di Zeiras, sconosciuto, scaduto, di un accesso chiuso, o di un workspace di cui la persona non è
     * più membro, 401 gettone_non_valido. Da qui si risponde nella lingua della persona, anche negli errori.
     *
     * @return array{persona: string, accesso: string, workspace: ?string}
     */
    private function autentica(Request $richiesta): array
    {
        $gettone = self::gettoneDi($richiesta);

        if ($gettone === null || $gettone === '') {
            throw new Problema('gettone_assente', header: ['WWW-Authenticate' => 'Bearer realm="zeiras"']);
        }

        $riga = preg_match('/^zr_[A-Za-z0-9]{48}$/', $gettone) === 1 ? ($this->gettoni[$gettone] ?? null) : null;
        $accesso = $riga === null ? null : $this->accessi[$riga['accesso']];

        if ($riga === null || $accesso === null || $accesso['chiuso'] || ! $this->scadenza($accesso)->isFuture()
            || ($riga['workspace'] !== null && ! isset($this->membri[$riga['workspace']][$accesso['utente']]))) {
            throw new Problema('gettone_non_valido', header: ['WWW-Authenticate' => 'Bearer realm="zeiras", error="invalid_token"']);
        }

        $this->testi->usa($this->persone[$accesso['utente']]['lingua']);

        return ['persona' => $accesso['utente'], 'accesso' => $riga['accesso'], 'workspace' => $riga['workspace']];
    }

    /**
     * Un gettone nuovo di un accesso, del workspace se c'è: lo schema Gettone. Scade 12 ore dopo la nascita dell'accesso.
     *
     * @return array<string, mixed>
     */
    private function emetti(string $accesso, ?string $workspace): array
    {
        $gettone = 'zr_'.Str::random(48);
        $this->gettoni[$gettone] = ['accesso' => $accesso, 'workspace' => $workspace];
        $persona = $this->accessi[$accesso]['utente'];

        return [
            'gettone' => $gettone,
            'scade_il' => self::iso($this->scadenza($this->accessi[$accesso])),
            'utente' => $this->utente($persona),
            'workspace' => $workspace === null ? null : $this->workspace[$workspace],
            'ruolo' => $workspace === null ? null : $this->membri[$workspace][$persona],
        ];
    }

    /**
     * Un codice nuovo per la persona (VerificaEmail::chiedi): se i freni degli invii lo ammettono conta l'invio e lo manda,
     * e il codice di prima non vale più; se no, niente, in silenzio.
     */
    private function chiediCodice(string $persona): void
    {
        foreach (self::INVII as $freno => [$invii]) {
            if ($this->freni->tooManyAttempts("invii:{$freno}:{$persona}", $invii)) {
                return;
            }
        }

        foreach (self::INVII as $freno => [, $secondi]) {
            $this->freni->hit("invii:{$freno}:{$persona}", $secondi);
        }

        $email = $this->persone[$persona]['email'];

        // Mai uguale a quello di prima: un test che chiede un codice nuovo vede da ultimoCodice() se è partito.
        do {
            $codice = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        } while ($codice === ($this->posta[$email] ?? null));

        $this->codici[$persona] = ['codice' => $codice, 'tentativi' => 0, 'scade' => now()->getTimestamp() + self::MINUTI_DEL_CODICE * 60];
        $this->posta[$email] = $codice;
    }

    /**
     * Prova il codice (VerificaEmail::verifica): true se è giusto, e allora l'email è verificata e il codice consumato;
     * false se è sbagliato, scaduto, esaurito, o se la persona ha già sbagliato 10 codici nel giorno. Il tentativo si conta
     * prima del confronto.
     */
    private function provaCodice(string $persona, #[SensitiveParameter] string $codice): bool
    {
        $stato = $this->codici[$persona] ?? null;
        $errori = "errori:{$persona}";

        if ($stato === null || $stato['scade'] - now()->getTimestamp() <= 0 || $stato['tentativi'] >= self::TENTATIVI
            || $this->freni->tooManyAttempts($errori, self::ERRORI_AL_GIORNO)) {
            return false;
        }

        $this->codici[$persona]['tentativi']++;

        if (! hash_equals($stato['codice'], $codice)) {
            $this->freni->hit($errori, 86400);

            return false;
        }

        unset($this->codici[$persona]);
        $this->persone[$persona]['email_verificata_il'] = now()->toImmutable();

        return true;
    }

    /** @return array<string, mixed> la persona come la dà il backoffice (Forme::utente) */
    private function utente(string $id): array
    {
        $persona = $this->persone[$id];

        return [
            'id' => $id,
            'nome' => $persona['nome'],
            'email' => $persona['email'],
            'email_verificata_il' => $persona['email_verificata_il'] === null ? null : self::iso($persona['email_verificata_il']),
            'lingua' => $persona['lingua'],
            'fuso_orario' => $persona['fuso_orario'],
        ];
    }

    /** L'id della persona con queste credenziali, o null (Utente::conCredenziali); l'email si confronta byte per byte. */
    private function conCredenziali(string $email, #[SensitiveParameter] string $password): ?string
    {
        $persona = $this->conEmail($email);

        return $persona !== null && hash_equals($this->persone[$persona]['password'], $password) ? $persona : null;
    }

    private function conEmail(string $email): ?string
    {
        foreach ($this->persone as $id => $persona) {
            if ($persona['email'] === $email) {
                return $id;
            }
        }

        return null;
    }

    /** @param  array{utente: string, creato_il: CarbonImmutable, chiuso: bool}  $accesso */
    private function scadenza(array $accesso): CarbonImmutable
    {
        return $accesso['creato_il']->addHours(self::ORE);
    }

    /** @return array<string, mixed> le regole di email e password dei metodi senza gettone */
    private static function credenziali(): array
    {
        return [
            'email' => self::REGOLE_EMAIL,
            'password' => ['required', 'string', new SenzaCarattereNullo],
        ];
    }

    /** Il gettone di Authorization, come Request::bearerToken() di Laravel. */
    private static function gettoneDi(Request $richiesta): ?string
    {
        $intestazione = (string) self::header($richiesta, 'Authorization');
        $posizione = strripos($intestazione, 'Bearer ');

        if ($posizione === false) {
            return null;
        }

        $gettone = substr($intestazione, $posizione + 7);

        return str_contains($gettone, ',') ? strstr($gettone, ',', true) : $gettone;
    }

    private static function header(Request $richiesta, string $nome): ?string
    {
        $valore = $richiesta->toPsrRequest()->getHeaderLine($nome);

        return $valore === '' ? null : $valore;
    }

    /** Utente::normalizzaEmail del backoffice. */
    private static function normalizza(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** Un id come quelli del backoffice: un ULID in minuscolo. */
    private static function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** Un istante come lo scrive il backoffice (Istante::iso): ISO 8601, in UTC, coi millesimi. */
    private static function iso(CarbonInterface $istante): string
    {
        return $istante->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
