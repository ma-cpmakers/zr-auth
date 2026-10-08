<?php

namespace Zeiras\Auth\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use Random\Randomizer;
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
 * Fa i metodi del nucleo (registrarsi, entrare, uscire, il gettone di un workspace, la verifica dell'email) e le letture
 * della persona e del workspace: io.mostra, io.workspace.elenca, app.elenca, workspace.membri.elenca. La registrazione è
 * chiusa come nel backoffice, finché il test non dà la lista dei consentiti (consenti()) o la apre (apri(), che vale solo
 * con Turnstile acceso); Turnstile è spento, finché il test non lo accende (accendiTurnstile()) o lo guasta
 * (guastaTurnstile()).
 *
 * I dati nascono dai metodi del finto, mai da campi comodi nelle risposte: il codice di verifica si legge da
 * ultimoCodice(), che fa da casella di posta. Il tempo è now(): un test lo sposta con travel(). Una chiamata di /v1 che il
 * finto non conosce lancia RichiestaSconosciuta; una chiamata che non va alle API resta agli altri Http::fake del test.
 */
final class BackofficeFinto
{
    /** La risposta del widget che il finto accetta con Turnstile acceso: quella che danno i tasti di prova di Cloudflare. */
    public const TURNSTILE_VALIDO = 'XXXX.DUMMY.TOKEN.XXXX';

    /** La password che il finto dà per trapelata, al posto di Have I Been Pwned: utenti.crea la rifiuta. */
    public const PASSWORD_TRAPELATA = 'una password trapelata';

    private const DOCUMENTAZIONE = 'https://docs.zeiras.com/v1/';

    /** I metodi che il finto fa: verbo, percorso, operationId. */
    private const METODI = [
        ['POST', '#^/v1/accessi$#', 'accessi.crea'],
        ['DELETE', '#^/v1/accessi/([^/]+)$#', 'accessi.elimina'],
        ['GET', '#^/v1/app$#', 'app.elenca'],
        ['PATCH', '#^/v1/app/([^/]+)$#', 'app.modifica'],
        ['POST', '#^/v1/gettoni$#', 'gettoni.crea'],
        ['POST', '#^/v1/ingressi$#', 'ingressi.crea'],
        ['POST', '#^/v1/ingressi/scambio$#', 'ingressi.scambio.crea'],
        ['GET', '#^/v1/io$#', 'io.mostra'],
        ['POST', '#^/v1/io/email/codice$#', 'io.email.codice.crea'],
        ['POST', '#^/v1/io/email/verifica$#', 'io.email.verifica.crea'],
        ['GET', '#^/v1/io/workspace$#', 'io.workspace.elenca'],
        ['POST', '#^/v1/password/recupero$#', 'password.recupero.crea'],
        ['POST', '#^/v1/password/reimpostazione$#', 'password.reimpostazione.crea'],
        ['POST', '#^/v1/utenti$#', 'utenti.crea'],
        ['GET', '#^/v1/workspace/membri$#', 'workspace.membri.elenca'],
    ];

    /** Il catalogo delle app (config/catalogo.php del backoffice, D15): lo stato di ogni app, per codice. */
    private const CATALOGO = [
        'automations' => 'in_arrivo',
        'bookings' => 'in_arrivo',
        'content' => 'in_arrivo',
        'crm' => 'in_arrivo',
        'pm' => 'disponibile',
        'reports' => 'in_arrivo',
    ];

    /**
     * Il nome di ogni app (config/catalogo.php del backoffice, `nomi`, #1204): per codice e per lingua. Il nome del
     * prodotto è lo stesso nelle tre lingue.
     */
    private const NOMI = [
        'automations' => ['it' => 'Automations', 'en' => 'Automations', 'es' => 'Automations'],
        'bookings' => ['it' => 'Bookings', 'en' => 'Bookings', 'es' => 'Bookings'],
        'content' => ['it' => 'Content', 'en' => 'Content', 'es' => 'Content'],
        'crm' => ['it' => 'CRM', 'en' => 'CRM', 'es' => 'CRM'],
        'pm' => ['it' => 'Project Management', 'en' => 'Project Management', 'es' => 'Project Management'],
        'reports' => ['it' => 'Reports', 'en' => 'Reports', 'es' => 'Reports'],
    ];

    /** Gli elementi di una pagina di una lista, se `limite` manca, e al più (ListaRequest). */
    private const LIMITE_PREDEFINITO = 50;

    private const LIMITE_MASSIMO = 100;

    /** Lo slug di un workspace (Workspace::nuovoSlug, D13): quanti caratteri del nome, poi quanti casuali, e da dove. */
    private const SLUG_DAL_NOME = 40;

    private const SLUG_CASUALI = 6;

    private const ALFABETO = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** Quante ore valgono i gettoni di un accesso, dalla sua nascita (Gettoni::ORE). */
    private const ORE = 12;

    /**
     * I freni del backoffice (config zeiras.freni): per email, richieste in un minuto ai metodi senza gettone; `gettone`,
     * chiamate in un minuto per gettone (FrenoPerGettone); `gettoni`, gettoni di gettoni.crea in un'ora per persona.
     */
    private const FRENI = ['accessi' => 5, 'codici' => 5, 'registrazioni' => 5, 'verifiche' => 5, 'recuperi' => 5, 'reimpostazioni' => 5, 'gettone' => 600, 'gettoni' => 60];

    private const ORA = 3600;

    private const MINUTO = 60;

    /** I freni degli invii dei codici di una persona, contando quello della nascita: invii, secondi (VerificaEmail). */
    private const INVII = ['invio' => [1, 60], 'ora' => [5, 3600], 'giorno' => [10, 86400]];

    /** Quanto vale un codice, e quanti errori ammette (VerificaEmail::MINUTI, TENTATIVI, ERRORI_AL_GIORNO). */
    private const MINUTI_DEL_CODICE = 10;

    private const TENTATIVI = 5;

    /** Quanto vale il codice di un ingresso, in secondi (Ingresso::TTL), e quanti scambi ammette in un minuto (IngressiController). */
    private const SECONDI_DELL_INGRESSO = 60;

    private const SCAMBI = 5;

    private const ERRORI_AL_GIORNO = 10;

    private const RUOLI = ['proprietario', 'amministratore', 'membro'];

    /** Utente::REGOLE_EMAIL del backoffice. */
    private const REGOLE_EMAIL = ['bail', 'required', 'string', 'max:254', 'email:rfc,filter'];

    /** Quanti caratteri può avere la risposta del widget (Turnstile::LUNGHEZZA). */
    private const LUNGHEZZA_TURNSTILE = 2048;

    /** @var array<string, array{nome: string, email: string, email_verificata_il: ?CarbonImmutable, lingua: string, fuso_orario: string, password: string}> per id */
    private array $persone = [];

    /** @var array<string, array{utente: string, creato_il: CarbonImmutable, chiuso: bool}> per id */
    private array $accessi = [];

    /** @var array<string, array{accesso: string, workspace: ?string}> per gettone, in chiaro: il finto vive nella memoria del test */
    private array $gettoni = [];

    /** @var array<string, array{id: string, nome: string, slug: string, azienda_id: string}> per id */
    private array $workspace = [];

    /** @var array<string, array<string, string>> il ruolo, per workspace e per persona */
    private array $membri = [];

    /** @var array<string, array{codice: string, tentativi: int, scade: int}> il codice che vale, per persona */
    private array $codici = [];

    /** @var array<string, array{codice: string, tentativi: int, scade: int}> il codice di recupero che vale, per persona */
    private array $recuperi = [];

    /** @var array<string, string> l'ultimo codice partito, per email */
    private array $posta = [];

    /** @var array<string, array<string, true>> le app che il workspace ha attivato (app.modifica), per workspace e per codice */
    private array $attive = [];

    /** @var array<string, string> dove un'app riceve il codice di un ingresso (ZR_RITORNO_<CODICE>), per codice dell'app */
    private array $ritorni = [];

    /** @var array<string, array{accesso: string, workspace: string, app: string, sfida: string, scade: int}> gli ingressi che valgono, per codice */
    private array $ingressi = [];

    /** @var list<string> la lista dei consentiti (zeiras.registrazione.consentiti): email intere o «@dominio» */
    private array $consentiti = [];

    /** La registrazione aperta a tutti (RegistrazioneConsentita::aperta()). */
    private bool $aperta = false;

    /** Turnstile in utenti.crea: spento (null), `acceso`, o `guasto` (Cloudflare non risponde). */
    private ?string $turnstile = null;

    /** I freni, con RateLimiter come nel backoffice: in memoria, e col tempo di now(). */
    private readonly RateLimiter $freni;

    private readonly Testi $testi;

    /** La chiave con cui il finto firma i suoi cursori, come Cursori con APP_KEY: nasce col finto, e vale solo per lui. */
    private readonly string $chiaveDeiCursori;

    /** Il caso dei caratteri casuali degli slug; non è readonly perché un test del finto lo fissa, per provare l'unicità. */
    private Randomizer $caso;

    private function __construct()
    {
        $this->freni = new RateLimiter(new Repository(new ArrayStore));
        $this->testi = new Testi;
        $this->chiaveDeiCursori = random_bytes(32);
        $this->caso = new Randomizer;
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
     * Fa nascere un workspace, con la persona come proprietaria, e lo slug del backoffice (D13).
     *
     * @param  array<string, mixed>  $proprietaria  una persona di persona()
     * @return array{id: string, nome: string, slug: string, azienda_id: string} il workspace, come lo dà il backoffice (lo schema Workspace)
     */
    public function workspace(string $nome, array $proprietaria): array
    {
        $id = self::id();
        // Un'azienda sua, come fa il backoffice vero senza azienda_id passato (#1259, decisione 5612 del #76): il
        // finto non modella aziende condivise fra workspace, nessun test gliene ha ancora chiesta una.
        $this->workspace[$id] = ['id' => $id, 'nome' => $nome, 'slug' => $this->nuovoSlug($nome), 'azienda_id' => self::id()];
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

    /**
     * Mette indirizzi nella lista dei consentiti di utenti.crea (ZR_REGISTRAZIONE_CONSENTITI del backoffice): un'email
     * intera o «@dominio», per uguaglianza. Di norma la lista è vuota, e ogni registrazione è 403 registrazione_non_aperta.
     */
    public function consenti(string ...$voci): self
    {
        foreach ($voci as $voce) {
            $this->consentiti[] = self::normalizza($voce);
        }

        return $this;
    }

    /**
     * Apre la registrazione a tutti, come l'interruttore del backoffice: vale solo con Turnstile acceso (accendiTurnstile()
     * o guastaTurnstile()), perché il backoffice senza il segreto resta alla lista.
     */
    public function apri(): self
    {
        $this->aperta = true;

        return $this;
    }

    /**
     * Accende Turnstile in utenti.crea, come il backoffice col segreto: una registrazione vuole `turnstile` uguale a
     * TURNSTILE_VALIDO, e senza o con un altro valore è 422 turnstile_non_valido.
     */
    public function accendiTurnstile(): self
    {
        $this->turnstile = 'acceso';

        return $this;
    }

    /**
     * Turnstile acceso, e Cloudflare che non risponde: una risposta del widget ben formata è 503 turnstile_non_disponibile,
     * una mancante o malformata resta 422 turnstile_non_valido.
     */
    public function guastaTurnstile(): self
    {
        $this->turnstile = 'guasto';

        return $this;
    }

    /**
     * Attiva delle app in un workspace, come app.modifica del proprietario: solo un'app che il catalogo dà disponibile.
     *
     * @param  array<string, mixed>  $workspace  un workspace di workspace()
     */
    public function attivaApp(array $workspace, string ...$app): self
    {
        $id = $workspace['id'] ?? null;

        if (! is_string($id) || ! isset($this->workspace[$id])) {
            throw new InvalidArgumentException('Il workspace non è del finto: nasce con workspace().');
        }

        foreach ($app as $codice) {
            if ((self::CATALOGO[$codice] ?? null) !== 'disponibile') {
                throw new InvalidArgumentException("L'app «{$codice}» non c'è fra quelle disponibili del catalogo: ".implode(', ', array_keys(array_filter(self::CATALOGO, fn (string $stato) => $stato === 'disponibile'))).'.');
            }

            $this->attive[$id][$codice] = true;
        }

        return $this;
    }

    /**
     * Dove un'app riceve il codice di un ingresso (ZR_RITORNO_<CODICE> del backoffice): ingressi.crea lo dà in `ritorno`.
     * Senza, l'app non riceve ingressi e ingressi.crea è 503 servizio_non_disponibile.
     */
    public function ritorno(string $app, string $indirizzo): self
    {
        if (! isset(self::CATALOGO[$app])) {
            throw new InvalidArgumentException("L'app «{$app}» non c'è nel catalogo: ".implode(', ', array_keys(self::CATALOGO)).'.');
        }

        $this->ritorni[$app] = $indirizzo;

        return $this;
    }

    /**
     * L'ultimo codice partito per l'email, null se nessuno: la casella di posta del finto. È di verifica o di recupero
     * della password, quello partito per ultimo.
     */
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
                'app.elenca' => $this->elencaApp($richiesta),
                'app.modifica' => $this->modificaApp($richiesta, $corpo, $parametri[0]),
                'gettoni.crea' => $this->creaGettone($richiesta, $corpo),
                'ingressi.crea' => $this->creaIngresso($richiesta, $corpo),
                'ingressi.scambio.crea' => $this->scambiaIngresso($corpo),
                'io.mostra' => $this->mostraIo($richiesta),
                'io.email.codice.crea' => $this->creaCodice($corpo),
                'io.email.verifica.crea' => $this->verificaEmail($corpo),
                'io.workspace.elenca' => $this->elencaWorkspace($richiesta),
                'password.recupero.crea' => $this->creaRecupero($corpo),
                'password.reimpostazione.crea' => $this->reimposta($corpo),
                'utenti.crea' => $this->creaUtente($corpo),
                'workspace.membri.elenca' => $this->elencaMembri($richiesta),
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
        // Al millesimo, come il backoffice (datetime(3)): la scadenza scritta nella risposta è quella vera.
        $this->accessi[$accesso] = ['utente' => $persona, 'creato_il' => now()->toImmutable()->startOfMillisecond(), 'chiuso' => false];

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

        // Il freno è della persona, con qualunque suo gettone, e si conta dopo il controllo del membro.
        $this->frena('gettoni:'.$chi['persona'], self::FRENI['gettoni'], self::ORA);

        return [201, ['data' => $this->emetti($chi['accesso'], $workspace)]];
    }

    /**
     * utenti.crea (UtentiController::crea): 202 con l'email, la stessa risposta per un'email nuova e per una che ha già un
     * account, che non cambia. Nell'ordine del backoffice: l'email, il suo freno, Turnstile, la lista dei consentiti, poi
     * il resto. La persona nuova nasce con l'email da verificare, i valori predefiniti del backoffice e il primo codice.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaUtente(array $corpo): array
    {
        $email = self::normalizza($this->testi->valida($corpo, ['email' => self::REGOLE_EMAIL])['email']);
        $this->frena('registrazioni:'.$email, self::FRENI['registrazioni'], self::MINUTO);
        // Dal corpo pulito come lo legge il backoffice, dopo TrimStrings e ConvertEmptyStringsToNull.
        $this->controllaTurnstile(Testi::pulisci($corpo)['turnstile'] ?? null);

        if (! $this->consente($email)) {
            throw new Problema('registrazione_non_aperta');
        }

        // Password::min(12)->uncompromised() del backoffice: la lunghezza, e la password trapelata al posto di HIBP.
        $dati = $this->testi->valida($corpo, [
            'password' => ['required', 'string', new SenzaCarattereNullo, 'min:12', function (string $campo, mixed $valore, Closure $fail) {
                if ($valore === self::PASSWORD_TRAPELATA) {
                    $fail('validation.password.uncompromised')->translate();
                }
            }],
            'nome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lingua' => ['sometimes', 'nullable', 'string', Rule::in(Testi::LINGUE)],
            'fuso_orario' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'termini_accettati' => ['required', 'boolean', 'accepted'],
            // La risposta del widget come la dichiara il contratto, anche a Turnstile spento; per ultima, come nel backoffice.
            'turnstile' => ['sometimes', 'nullable', 'string', 'max:'.self::LUNGHEZZA_TURNSTILE],
        ]);

        if ($this->conEmail($email) === null) {
            $id = self::id();
            $this->persone[$id] = [
                'nome' => $dati['nome'] ?? Str::before($email, '@'),
                'email' => $email,
                'email_verificata_il' => null,
                // La predefinita di config/lingue.php del backoffice, e il fuso di chi non lo sceglie.
                'lingua' => $dati['lingua'] ?? 'it',
                'fuso_orario' => $dati['fuso_orario'] ?? 'Europe/Rome',
                'password' => $dati['password'],
            ];
            $this->chiediCodice($id);
        }

        return [202, ['data' => ['email' => $email]]];
    }

    /**
     * Turnstile come il backoffice (Turnstile::controlla): spento, niente. Acceso, una risposta assente, vuota, che non è
     * una stringa o è troppo lunga è 422 turnstile_non_valido, e Cloudflare passa solo TURNSTILE_VALIDO; guasto, la
     * risposta ben formata arriva a un Cloudflare che non risponde, 503 turnstile_non_disponibile.
     */
    private function controllaTurnstile(mixed $risposta): void
    {
        if ($this->turnstile === null) {
            return;
        }

        if (! is_string($risposta) || $risposta === '' || mb_strlen($risposta) > self::LUNGHEZZA_TURNSTILE) {
            throw new Problema('turnstile_non_valido');
        }

        if ($this->turnstile === 'guasto') {
            throw new Problema('turnstile_non_disponibile');
        }

        if ($risposta !== self::TURNSTILE_VALIDO) {
            throw new Problema('turnstile_non_valido');
        }
    }

    /**
     * La lista dei consentiti (RegistrazioneConsentita::consente): aperta e con Turnstile acceso, ogni email; altrimenti
     * un'email intera della lista o il suo dominio dopo «@», per uguaglianza.
     */
    private function consente(string $email): bool
    {
        if ($this->aperta && $this->turnstile !== null) {
            return true;
        }

        $dominio = Str::afterLast($email, '@');

        foreach ($this->consentiti as $voce) {
            if ($voce !== '' && (str_starts_with($voce, '@') ? $dominio === substr($voce, 1) : $email === $voce)) {
                return true;
            }
        }

        return false;
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
     * io.mostra (IoController::mostra): la persona del gettone e, col gettone di un workspace, quel workspace, il
     * ruolo della persona lì, letto a questa chiamata, e le sue notifiche non lette (#1260): il finto non ha
     * notifiche, quindi è sempre 0 con un workspace, null senza, come `workspace` e `ruolo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraIo(Request $richiesta): array
    {
        $chi = $this->autentica($richiesta);
        $workspace = $chi['workspace'];

        return [200, ['data' => [
            'utente' => $this->utente($chi['persona']),
            'workspace' => $workspace === null ? null : $this->workspace[$workspace],
            'ruolo' => $workspace === null ? null : $this->membri[$workspace][$chi['persona']],
            'notifiche_non_lette' => $workspace === null ? null : 0,
        ]]];
    }

    /**
     * io.workspace.elenca (IoWorkspaceController::elenca): i workspace di cui la persona del gettone è membro, col ruolo che
     * ha lì, in ordine di nome e poi di id. Vale ogni gettone della persona. Il cursore porta l'id, e la sua posizione si
     * rilegge fra tutti i workspace del finto.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaWorkspace(Request $richiesta): array
    {
        $persona = $this->autentica($richiesta)['persona'];
        $voci = [];

        foreach ($this->membri as $workspace => $ruoli) {
            if (isset($ruoli[$persona])) {
                $voci[] = [...$this->workspace[$workspace], 'ruolo' => $ruoli[$persona]];
            }
        }

        return $this->pagina($richiesta, 'io.workspace.elenca', 'id', $voci, self::perNomeEId(...),
            fn (string $id) => isset($this->workspace[$id]) ? self::perNomeEId($this->workspace[$id]) : null);
    }

    /**
     * app.elenca (AppController::elenca): le app del catalogo in ordine di codice, ognuna col suo stato nel workspace del
     * gettone: `attivo` se il catalogo la dà disponibile e il workspace l'ha attivata (app.modifica, attivaApp()), se no
     * quello del catalogo. Il cursore porta il codice, e la pagina dopo parte dal
     * codice dopo.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaApp(Request $richiesta): array
    {
        $workspace = $this->delWorkspace($richiesta);
        $voci = [];

        foreach (self::CATALOGO as $codice => $stato) {
            $voci[] = $this->voceApp($codice, $this->appAttiva($workspace, $codice) ? 'attivo' : $stato);
        }

        return $this->pagina($richiesta, 'app.elenca', 'codice', $voci, fn (array $app) => [$app['codice']], fn (string $codice) => [$codice]);
    }

    /**
     * app.modifica (AppController::modifica): attiva o disattiva un'app nel workspace del gettone. Nell'ordine del
     * backoffice: il ruolo (il middleware `workspace:proprietario,amministratore`, prima dell'app e del corpo), l'app del
     * catalogo (404), il corpo (422), e un'app in arrivo non cambia (409). Lo stato che ha già non scrive niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaApp(Request $richiesta, array $corpo, string $app): array
    {
        $workspace = $this->conWorkspace($richiesta, ['proprietario', 'amministratore'])['workspace'];

        if (! isset(self::CATALOGO[$app])) {
            throw new Problema('non_trovato');
        }

        $stato = (string) $this->testi->validaStretta($corpo, ['stato' => ['required', 'string', Rule::in(['attivo', 'disponibile'])]])['stato'];

        if (self::CATALOGO[$app] !== 'disponibile') {
            throw new Problema('app_in_arrivo');
        }

        if ($stato === 'attivo') {
            $this->attive[$workspace][$app] = true;
        } else {
            unset($this->attive[$workspace][$app]);
        }

        return [200, ['data' => $this->voceApp($app, $stato)]];
    }

    /**
     * ingressi.crea (IngressiController::crea): il codice monouso per un'app attiva nel workspace del gettone, per la
     * persona del gettone. L'indirizzo di ritorno è quello dell'app (ritorno()), mai della richiesta: un campo `ritorno`
     * nel corpo è 422 come ogni altro. Il corpo si guarda prima, poi l'app attiva (403), poi l'indirizzo (503).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaIngresso(Request $richiesta, array $corpo): array
    {
        $chi = $this->conWorkspace($richiesta);
        $workspace = $chi['workspace'];
        $campi = $this->testi->validaStretta($corpo, [
            'app' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', Rule::in(array_keys(self::CATALOGO))],
            'sfida' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ]);
        $app = (string) $campi['app'];

        if (! $this->appAttiva($workspace, $app)) {
            throw new Problema('app_non_attiva');
        }

        $ritorno = $this->ritorni[$app] ?? '';

        if ($ritorno === '') {
            throw new Problema('servizio_non_disponibile');
        }

        $codice = self::base64url(random_bytes(32));
        $scade = now()->getTimestamp() + self::SECONDI_DELL_INGRESSO;
        $this->ingressi[$codice] = ['accesso' => $chi['accesso'], 'workspace' => $workspace, 'app' => $app, 'sfida' => (string) $campi['sfida'], 'scade' => $scade];

        return [201, ['data' => ['codice' => $codice, 'ritorno' => $ritorno, 'scade_il' => self::iso(CarbonImmutable::createFromTimestampUTC($scade))]]];
    }

    /**
     * ingressi.scambio.crea (IngressiController::scambio), senza gettone: il codice vale una volta e per 60 secondi, e il
     * verificatore deve avere la sfida data (SHA-256, base64url). Il freno è per codice, mai per IP. Ogni scambio che non
     * riesce è la stessa 422 verifica_non_riuscita: un verificatore sbagliato consuma il codice, come uno scaduto.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function scambiaIngresso(array $corpo): array
    {
        $campi = $this->testi->validaStretta($corpo, [
            'codice' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'verificatore' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
        ]);
        $codice = (string) $campi['codice'];
        $this->frena('ingresso:'.$codice, self::SCAMBI, self::MINUTO);

        $ingresso = $this->ingressi[$codice] ?? null;
        unset($this->ingressi[$codice]);

        if ($ingresso === null || $ingresso['scade'] <= now()->getTimestamp()
            || ! hash_equals($ingresso['sfida'], self::base64url(hash('sha256', (string) $campi['verificatore'], true)))) {
            throw new Problema('verifica_non_riuscita');
        }

        $accesso = $this->accessi[$ingresso['accesso']] ?? null;

        if ($accesso === null || $accesso['chiuso'] || ! $this->scadenza($accesso)->gt(now())
            || ! isset($this->membri[$ingresso['workspace']][$accesso['utente']]) || ! $this->appAttiva($ingresso['workspace'], $ingresso['app'])) {
            throw new Problema('verifica_non_riuscita');
        }

        return [201, ['data' => $this->emetti($ingresso['accesso'], $ingresso['workspace'])]];
    }

    /**
     * password.recupero.crea (PasswordController::recupero), senza gettone: 202 con la sola email, la stessa risposta per
     * un'email con un account e per una senza. Nell'ordine del backoffice: l'email, il suo freno, Turnstile prima di
     * cercare l'account, poi il codice, che parte solo a un account e se i freni degli invii lo ammettono.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaRecupero(array $corpo): array
    {
        $dati = $this->testi->validaStretta($corpo, [
            'email' => self::REGOLE_EMAIL,
            'turnstile' => ['sometimes', 'nullable', 'string', 'max:'.self::LUNGHEZZA_TURNSTILE],
        ]);
        $email = self::normalizza($dati['email']);
        $this->frena('recuperi:'.$email, self::FRENI['recuperi'], self::MINUTO);
        $this->controllaTurnstile($dati['turnstile'] ?? null);

        // I freni contano ogni email, con o senza account (RecuperoPassword::chiedi).
        foreach (self::INVII as $freno => [$invii]) {
            if ($this->freni->tooManyAttempts("recupero-{$freno}:{$email}", $invii)) {
                return [202, ['data' => ['email' => $email]]];
            }
        }

        foreach (self::INVII as $freno => [, $secondi]) {
            $this->freni->hit("recupero-{$freno}:{$email}", $secondi);
        }

        $persona = $this->conEmail($email);

        if ($persona !== null) {
            $this->recuperi[$persona] = ['codice' => $this->codiceNuovo($email), 'tentativi' => 0, 'scade' => now()->getTimestamp() + self::MINUTI_DEL_CODICE * 60];
            $this->posta[$email] = $this->recuperi[$persona]['codice'];
        }

        return [202, ['data' => ['email' => $email]]];
    }

    /**
     * password.reimpostazione.crea (PasswordController::reimpostazione), senza gettone: 204 se il codice è giusto. La
     * password nuova vale, la vecchia no, e tutti gli accessi della persona si chiudono coi loro gettoni. Ogni altro esito
     * (un'email senza account, un codice sbagliato, scaduto, già usato o esaurito) è la stessa 422 verifica_non_riuscita.
     * Una password che non va è 422 sul campo prima di toccare il codice, che resta buono; il freno si conta prima.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, null}
     */
    private function reimposta(array $corpo): array
    {
        $dati = $this->testi->validaStretta($corpo, [
            'email' => self::REGOLE_EMAIL,
            'codice' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', new SenzaCarattereNullo, 'min:12'],
        ]);
        $email = self::normalizza($dati['email']);
        $this->frena('reimpostazioni:'.$email, self::FRENI['reimpostazioni'], self::MINUTO);

        // Have I Been Pwned del backoffice (PasswordNuova::controlla), dopo il freno: la password trapelata è del finto.
        $this->testi->valida(['password' => $dati['password']], ['password' => [function (string $campo, mixed $valore, Closure $fail) {
            if ($valore === self::PASSWORD_TRAPELATA) {
                $fail('validation.password.uncompromised')->translate();
            }
        }]]);

        $persona = $this->conEmail($email);

        if ($persona === null || ! $this->provaRecupero($persona, $dati['codice'])) {
            throw new Problema('verifica_non_riuscita');
        }

        $this->persone[$persona]['password'] = $dati['password'];

        if ($this->persone[$persona]['email_verificata_il'] === null) {
            $this->persone[$persona]['email_verificata_il'] = now()->toImmutable();
        }

        foreach ($this->accessi as $id => $accesso) {
            if ($accesso['utente'] === $persona) {
                $this->accessi[$id]['chiuso'] = true;
            }
        }

        unset($this->recuperi[$persona]);

        return [204, null];
    }

    /**
     * Prova il codice di recupero (RecuperoPassword::reimposta): true se è giusto; false se è sbagliato, scaduto, già
     * usato, esaurito, o se la persona ha già sbagliato 10 codici nel giorno. Il tentativo si conta prima del confronto,
     * e il codice si consuma solo dalla reimpostazione che riesce.
     */
    private function provaRecupero(string $persona, #[SensitiveParameter] string $codice): bool
    {
        $stato = $this->recuperi[$persona] ?? null;
        $errori = "errori-recupero:{$persona}";

        if ($stato === null || $stato['scade'] - now()->getTimestamp() <= 0 || $stato['tentativi'] >= self::TENTATIVI
            || $this->freni->tooManyAttempts($errori, self::ERRORI_AL_GIORNO)) {
            return false;
        }

        $this->recuperi[$persona]['tentativi']++;

        if (! hash_equals($stato['codice'], $codice)) {
            $this->freni->hit($errori, 86400);

            return false;
        }

        return true;
    }

    /** Un codice di 6 cifre, mai uguale all'ultimo partito per l'email: un test che ne chiede uno nuovo lo vede da ultimoCodice(). */
    private function codiceNuovo(string $email): string
    {
        do {
            $codice = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        } while ($codice === ($this->posta[$email] ?? null));

        return $codice;
    }

    /**
     * Il workspace del gettone della richiesta, e col ruolo il middleware `workspace:<ruoli>` del backoffice: al gettone
     * dell'accesso 403 gettone_senza_workspace, a chi ha un altro ruolo 403 permesso_negato, prima del corpo e delle risorse.
     *
     * @param  list<string>  $ruoli  vuoto: tutti
     * @return array{persona: string, accesso: string, workspace: string}
     */
    private function conWorkspace(Request $richiesta, array $ruoli = []): array
    {
        $chi = $this->autentica($richiesta);
        $workspace = $chi['workspace'] ?? throw new Problema('gettone_senza_workspace');

        if ($ruoli !== [] && ! in_array($this->membri[$workspace][$chi['persona']], $ruoli, true)) {
            throw new Problema('permesso_negato');
        }

        return ['persona' => $chi['persona'], 'accesso' => $chi['accesso'], 'workspace' => $workspace];
    }

    /** Se il workspace ha l'app attiva (AppNelWorkspace::attiva): disponibile nel catalogo e attivata da lui. */
    private function appAttiva(string $workspace, string $app): bool
    {
        return (self::CATALOGO[$app] ?? null) === 'disponibile' && isset($this->attive[$workspace][$app]);
    }

    /** @return array{codice: string, stato: string, nome: array<string, string>} un'app come la dà il backoffice (Forme::app) */
    private function voceApp(string $codice, string $stato): array
    {
        $nome = [];

        foreach (Testi::LINGUE as $lingua) {
            $nome[$lingua] = self::NOMI[$codice][$lingua] ?? self::NOMI[$codice]['en'] ?? $codice;
        }

        return ['codice' => $codice, 'stato' => $stato, 'nome' => $nome];
    }

    /**
     * workspace.membri.elenca (MembriController::elenca): le persone del workspace del gettone, ognuna con l'id della
     * persona, il nome, l'email e il ruolo, in ordine di nome e poi di id. Il cursore porta l'id della persona, e la sua
     * posizione si rilegge fra tutte le persone del finto.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaMembri(Request $richiesta): array
    {
        $workspace = $this->delWorkspace($richiesta);
        $voci = [];

        foreach ($this->membri[$workspace] as $persona => $ruolo) {
            $voci[] = ['id' => $persona, 'nome' => $this->persone[$persona]['nome'], 'email' => $this->persone[$persona]['email'], 'ruolo' => $ruolo];
        }

        return $this->pagina($richiesta, 'workspace.membri.elenca', 'id', $voci, self::perNomeEId(...),
            fn (string $id) => isset($this->persone[$id]) ? self::perNomeEId(['id' => $id, 'nome' => $this->persone[$id]['nome']]) : null);
    }

    /**
     * Il workspace del gettone della richiesta, per un metodo che lavora sui dati di un workspace (il middleware
     * `workspace` del backoffice): al gettone dell'accesso 403 gettone_senza_workspace, prima di guardare la query.
     */
    private function delWorkspace(Request $richiesta): string
    {
        return $this->autentica($richiesta)['workspace'] ?? throw new Problema('gettone_senza_workspace');
    }

    /**
     * Una pagina di una lista (ListaRequest del backoffice): `limite` da 1 a 100, 50 se manca; `cursore` il `successivo`
     * della pagina prima, firmato per questa lista, che porta la sola chiave dell'ultima voce. Le voci vanno in ordine di
     * posizione, e la pagina parte dalla prima voce dopo quella del cursore. Un valore che non va è 422 sul parametro; un
     * cursore la cui posizione non si trova più non vale. Nel finto quel ramo non si raggiunge (nessuna voce sparisce, e la
     * chiave dei cursori è del finto): resta per rispondere come il backoffice, dove la voce del cursore si può archiviare.
     *
     * @param  list<array<string, mixed>>  $voci
     * @param  Closure(array<string, mixed>): list<string>  $posizione  la posizione di una voce nell'ordine della lista
     * @param  Closure(string): (list<string>|null)  $posizioneDelCursore  la posizione dalla chiave di un cursore
     * @return array{int, array<string, mixed>}
     */
    private function pagina(Request $richiesta, string $lista, string $chiave, array $voci, Closure $posizione, Closure $posizioneDelCursore): array
    {
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO],
            'cursore' => ['sometimes', 'string', function (string $attributo, mixed $valore, Closure $fail) use ($lista, $chiave) {
                if ($this->leggiCursore($lista, $chiave, $valore) === null) {
                    $fail('regole.cursore')->translate();
                }
            }],
        ]);
        $limite = (int) ($query['limite'] ?? self::LIMITE_PREDEFINITO);
        usort($voci, fn (array $una, array $altra) => self::confronta($posizione($una), $posizione($altra)));

        if (isset($query['cursore'])) {
            $dopo = $posizioneDelCursore((string) $this->leggiCursore($lista, $chiave, $query['cursore']))
                ?? throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.cursore', ['attribute' => 'cursore']), 'parameter' => 'cursore']]);
            $voci = array_values(array_filter($voci, fn (array $voce) => self::confronta($posizione($voce), $dopo) > 0));
        }

        $pagina = array_slice($voci, 0, $limite);

        return [200, [
            'data' => $pagina,
            'successivo' => count($voci) > $limite ? $this->firmaCursore($lista, [$chiave => $pagina[$limite - 1][$chiave]]) : null,
        ]];
    }

    /**
     * Un `successivo` (Cursori::firma del backoffice): la posizione in JSON e in base64url, un punto, la firma per la
     * lista.
     *
     * @param  array<string, string>  $posizione
     */
    private function firmaCursore(string $lista, array $posizione): string
    {
        $dati = self::base64url(json_encode($posizione, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $dati.'.'.$this->impronta($lista, $dati);
    }

    /** Il valore della chiave in un cursore che questa lista ha dato (Cursori::leggi), o null: inventato, ritoccato, di un'altra lista. */
    private function leggiCursore(string $lista, string $chiave, mixed $valore): ?string
    {
        if (! is_string($valore) || substr_count($valore, '.') !== 1) {
            return null;
        }

        [$dati, $firma] = explode('.', $valore);

        if (! hash_equals($this->impronta($lista, $dati), $firma)) {
            return null;
        }

        $posizione = json_decode((string) base64_decode(strtr($dati, '-_', '+/'), true), true);

        return is_array($posizione) && array_keys($posizione) === [$chiave] && is_string($posizione[$chiave]) ? $posizione[$chiave] : null;
    }

    private function impronta(string $lista, string $dati): string
    {
        return self::base64url(hash_hmac('sha256', "{$lista}\n{$dati}", $this->chiaveDeiCursori, true));
    }

    /**
     * Lo slug di un workspace nuovo (Workspace::nuovoSlug, D13): il nome in slug, al più 40 caratteri e senza un trattino
     * in fondo, o `workspace` se del nome non resta niente; poi un trattino e 6 caratteri [a-z0-9] casuali, unico nel finto.
     */
    private function nuovoSlug(string $nome): string
    {
        $dalNome = rtrim(substr(Str::slug($nome), 0, self::SLUG_DAL_NOME), '-');
        $dalNome = $dalNome === '' ? 'workspace' : $dalNome;
        $presi = array_column($this->workspace, 'slug');

        do {
            $slug = $dalNome.'-'.$this->caso->getBytesFromString(self::ALFABETO, self::SLUG_CASUALI);
        } while (in_array($slug, $presi, true));

        return $slug;
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

        if ($riga === null || $accesso === null || $accesso['chiuso'] || ! $this->scadenza($accesso)->gt(now()->startOfMillisecond())
            || ($riga['workspace'] !== null && ! isset($this->membri[$riga['workspace']][$accesso['utente']]))) {
            throw new Problema('gettone_non_valido', header: ['WWW-Authenticate' => 'Bearer realm="zeiras", error="invalid_token"']);
        }

        $this->testi->usa($this->persone[$accesso['utente']]['lingua']);

        // Il freno del gettone (FrenoPerGettone), dopo la guardia: oltre il tetto in un minuto 429, e la chiamata frenata
        // non conta.
        $chiave = 'gettone:'.$gettone;

        if ($this->freni->tooManyAttempts($chiave, self::FRENI['gettone'])) {
            throw new Problema('troppe_richieste', header: ['Retry-After' => (string) $this->freni->availableIn($chiave)]);
        }

        $this->freni->hit($chiave, self::MINUTO);

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

    /**
     * La posizione di una voce in una lista in ordine di nome e poi di id: il nome senza maiuscole e senza accenti, che non
     * contano, poi l'id. Per i nomi in lettere latine è l'ordine del backoffice; segni, emoji e scritture non latine possono
     * andare in un altro ordine (il backoffice ordina con la collazione del database).
     *
     * @param  array<string, mixed>  $voce  con `id` e `nome`
     * @return list<string>
     */
    private static function perNomeEId(array $voce): array
    {
        return [Str::lower(Str::ascii($voce['nome'])), $voce['id']];
    }

    /**
     * Due posizioni, un campo alla volta e come testi: decide il primo che differisce.
     *
     * @param  list<string>  $una
     * @param  list<string>  $altra
     */
    private static function confronta(array $una, array $altra): int
    {
        foreach ($una as $i => $valore) {
            $ordine = strcmp($valore, $altra[$i]);

            if ($ordine !== 0) {
                return $ordine;
            }
        }

        return 0;
    }

    /**
     * I parametri della query di una richiesta, come `$request->query()` nel backoffice.
     *
     * @return array<mixed>
     */
    private static function query(Request $richiesta): array
    {
        parse_str((string) parse_url($richiesta->url(), PHP_URL_QUERY), $parametri);

        return $parametri;
    }

    private static function base64url(string $testo): string
    {
        return rtrim(strtr(base64_encode($testo), '+/', '-_'), '=');
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
