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
use Zeiras\Auth\Eventi\Firma;
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
 * della persona e del workspace: io.mostra, io.workspace.elenca, app.elenca, workspace.membri.elenca, e la nascita di un workspace, io.workspace.crea. La registrazione è
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
        ['DELETE', '#^/v1/accessi/corrente$#', 'accessi.corrente.elimina'],
        ['DELETE', '#^/v1/accessi/([^/]+)$#', 'accessi.elimina'],
        ['GET', '#^/v1/accessi/provider$#', 'accessi.provider.elenca'],
        ['POST', '#^/v1/accessi/provider/([^/]+)/autorizzazioni$#', 'accessi.provider.autorizzazioni.crea'],
        ['POST', '#^/v1/accessi/provider/([^/]+)$#', 'accessi.provider.crea'],
        ['GET', '#^/v1/app$#', 'app.elenca'],
        ['PATCH', '#^/v1/app/([^/]+)$#', 'app.modifica'],
        ['POST', '#^/v1/gettoni$#', 'gettoni.crea'],
        ['POST', '#^/v1/ingressi$#', 'ingressi.crea'],
        ['POST', '#^/v1/ingressi/scambio$#', 'ingressi.scambio.crea'],
        ['POST', '#^/v1/inviti/accettazione$#', 'inviti.accettazione.crea'],
        ['GET', '#^/v1/io$#', 'io.mostra'],
        ['GET', '#^/v1/io/aziende$#', 'io.aziende.elenca'],
        ['PATCH', '#^/v1/io$#', 'io.modifica'],
        ['POST', '#^/v1/io/email/codice$#', 'io.email.codice.crea'],
        ['POST', '#^/v1/io/email/verifica$#', 'io.email.verifica.crea'],
        ['PATCH', '#^/v1/io/password$#', 'io.password.modifica'],
        ['GET', '#^/v1/io/workspace$#', 'io.workspace.elenca'],
        ['POST', '#^/v1/io/workspace$#', 'io.workspace.crea'],
        ['GET', '#^/v1/lingue$#', 'lingue.elenca'],
        ['POST', '#^/v1/password/recupero$#', 'password.recupero.crea'],
        ['POST', '#^/v1/password/reimpostazione$#', 'password.reimpostazione.crea'],
        ['POST', '#^/v1/utenti$#', 'utenti.crea'],
        ['PATCH', '#^/v1/workspace$#', 'workspace.modifica'],
        ['GET', '#^/v1/workspace/inviti$#', 'workspace.inviti.elenca'],
        ['POST', '#^/v1/workspace/inviti$#', 'workspace.inviti.crea'],
        ['DELETE', '#^/v1/workspace/inviti/([^/]+)$#', 'workspace.inviti.elimina'],
        ['GET', '#^/v1/workspace/membri$#', 'workspace.membri.elenca'],
        ['PATCH', '#^/v1/workspace/membri/([^/]+)$#', 'workspace.membri.modifica'],
        ['DELETE', '#^/v1/workspace/membri/([^/]+)$#', 'workspace.membri.elimina'],
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

    /**
     * Il nome di ogni lingua scritto in quella lingua (config/lingue.php del backoffice, `nomi`, #1204): lingue.elenca le
     * dà in ordine di codice.
     */
    private const NOMI_LINGUE = ['it' => 'Italiano', 'en' => 'English', 'es' => 'Español'];

    /**
     * I campi della risposta di io.mostra che io.modifica non cambia, col metodo che li cambia (null: nessuno, li dà il
     * sistema): un campo di questi nel corpo è un errore sul suo pointer che dice dove andare (IoController::ALTRI_CAMPI).
     */
    private const ALTRI_CAMPI_DI_IO = [
        'utente.id' => null,
        'utente.email' => null,
        'utente.email_verificata_il' => null,
        'workspace' => 'workspace.modifica',
        'ruolo' => 'workspace.membri.modifica',
    ];

    /** Gli errori sulla password attuale che una persona può fare in un'ora (IoPasswordController::ERRORI). */
    private const ERRORI_DELLA_PASSWORD = 5;

    /**
     * I provider con cui si entra (App\Provider del backoffice, #1429): per slug, lo scope e l'indirizzo a cui la persona
     * autorizza. Quali sono accesi lo dice il test (provider()), come in produzione lo dicono le credenziali.
     */
    private const PROVIDER = [
        'facebook' => ['scope' => 'email public_profile', 'indirizzo' => 'https://www.facebook.com/dialog/oauth'],
        'google' => ['scope' => 'openid email profile', 'indirizzo' => 'https://accounts.google.com/o/oauth2/v2/auth'],
        'linkedin-openid' => ['scope' => 'openid email profile', 'indirizzo' => 'https://www.linkedin.com/oauth/v2/authorization'],
    ];

    /** Quanto vale lo stato di una partenza, in secondi (AccessiProviderController::VALIDITA_STATO): una volta sola. */
    private const SECONDI_DELLO_STATO = 600;

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
    private const FRENI = ['accessi' => 5, 'codici' => 5, 'registrazioni' => 5, 'verifiche' => 5, 'recuperi' => 5, 'reimpostazioni' => 5, 'gettone' => 600, 'gettoni' => 60, 'inviti_per_email' => 5, 'inviti_per_workspace' => 50, 'workspace' => 10, 'provider_elenco' => 120, 'provider_globale' => 120, 'provider_partenza' => 60, 'provider_arrivo_globale' => 120, 'provider_arrivo' => 60, 'provider_stato' => 5];

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

    /** Quanti giorni vale un invito (Invito::GIORNI). */
    private const GIORNI_DELL_INVITO = 7;

    /** I membri di un workspace, con gli inviti vivi che li diventerebbero, al più (Tetti::MEMBRI, #1411): il 50º posto è l'ultimo. */
    private const MEMBRI = 50;

    /** Per quanto il backoffice ricorda la risposta di una Idempotency-Key (Idempotenza::VALIDITA): 24 ore. */
    private const SECONDI_DELL_IDEMPOTENZA = 86400;

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

    /** @var array<string, string> il nome di ogni azienda, per id: quello del primo workspace che l'ha fatta nascere, mai aggiornato */
    private array $aziende = [];

    /** @var array<string, array<string, string>> il ruolo, per workspace e per persona */
    private array $membri = [];

    /** @var array<string, array{codice: string, tentativi: int, scade: int}> il codice che vale, per persona */
    private array $codici = [];

    /** @var array<string, array{codice: string, tentativi: int, scade: int}> il codice di recupero che vale, per persona */
    private array $recuperi = [];

    /** @var array<string, string> l'ultimo codice partito, per email */
    private array $posta = [];

    /** @var array<string, array{id: string, workspace: string, email: string, ruolo: string, codice: string, scade: CarbonImmutable, creato_il: CarbonImmutable}> gli inviti che non sono né revocati né accettati, per id */
    private array $inviti = [];

    /** @var array<string, string> l'ultimo codice d'invito partito, per email */
    private array $postaInviti = [];

    /** @var array<string, array{impronta: string, stato: int, dati: array<string, mixed>, header: array<string, string>, scade: int}> le risposte che l'Idempotency-Key ricorda, per chiave */
    private array $ricordate = [];

    /** @var array<string, array<string, true>> le app che il workspace ha attivato (app.modifica), per workspace e per codice */
    private array $attive = [];

    /** @var array<string, string> dove un'app riceve il codice di un ingresso (ZR_RITORNO_<CODICE>), per codice dell'app */
    private array $ritorni = [];

    /** @var array<string, array{accesso: string, workspace: string, app: string, sfida: string, scade: int}> gli ingressi che valgono, per codice */
    private array $ingressi = [];

    /** @var array<string, true> i provider accesi (provider()), per slug */
    private array $provider = [];

    /** @var array<string, true> i provider che non rispondono (guastaProvider()), per slug */
    private array $providerGuasti = [];

    /** @var array<string, array{id: string, email: string, verificata: bool, nome: ?string}> il profilo che il provider dà per un codice (identitaDelProvider()), per slug e codice */
    private array $profili = [];

    /** @var array<string, array{provider: string, scade: CarbonImmutable, verificatore: string}> le partenze che valgono, per stato */
    private array $partenze = [];

    /** @var array<string, string> la persona di ogni identità del provider, per slug e id del provider */
    private array $identita = [];

    /** Il numero d'ordine dell'ultimo evento che consegna() ha fatto: cresce di uno a ogni consegna (`sequence`). */
    private int $sequenza = 0;

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
     * Accende dei provider (Google, LinkedIn, Facebook: `google`, `linkedin-openid`, `facebook`), come in produzione li
     * accendono le credenziali: finché non sono accesi, `accessi.provider.elenca` non li dà e gli altri due metodi li
     * trattano come sconosciuti (404).
     */
    public function provider(string ...$provider): self
    {
        foreach ($provider as $slug) {
            if (! isset(self::PROVIDER[$slug])) {
                throw new InvalidArgumentException("Il finto non conosce il provider «{$slug}»: i suoi sono ".implode(', ', array_keys(self::PROVIDER)).'.');
            }

            $this->provider[$slug] = true;
        }

        return $this;
    }

    /**
     * Dice che cosa risponde il provider quando la pagina gli porta il `codice` del ritorno: la persona che ha autorizzato.
     * Un codice che il test non ha detto è un codice che il provider rifiuta. `$verificata: false` è un'email che il
     * provider non garantisce: l'accesso non riesce. Senza `$id` l'id opaco del provider lo fa il finto, uguale per la
     * stessa email.
     */
    public function identitaDelProvider(string $provider, string $codice, string $email, ?string $nome = null, bool $verificata = true, ?string $id = null): self
    {
        if (! isset(self::PROVIDER[$provider])) {
            throw new InvalidArgumentException("Il finto non conosce il provider «{$provider}»: i suoi sono ".implode(', ', array_keys(self::PROVIDER)).'.');
        }

        $this->profili[$provider.'|'.$codice] = [
            'id' => $id ?? 'finto-'.substr(hash('sha256', $provider.'|'.self::normalizza($email)), 0, 21),
            'email' => trim($email),
            'verificata' => $verificata,
            'nome' => $nome,
        ];

        return $this;
    }

    /** Il provider non risponde: l'arrivo (`accessi.provider.crea`) è `503 servizio_non_disponibile`. */
    public function guastaProvider(string $provider): self
    {
        $this->provider_guasti[$provider] = true;

        return $this;
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
        $this->workspace[$id] = ['id' => $id, 'nome' => $nome, 'slug' => $this->nuovoSlug($nome), 'azienda_id' => $this->nuovaAzienda($nome)];
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

    /**
     * L'ultimo codice d'invito partito per l'email, null se nessuno: la casella di posta degli inviti. Il codice non esce
     * mai dalle risposte (workspace.inviti.crea, workspace.inviti.elenca): è nella mail, e il test lo legge da qui.
     */
    public function ultimoInvito(string $email): ?string
    {
        return $this->postaInviti[self::normalizza($email)] ?? null;
    }

    /**
     * La consegna di un evento del backoffice a un modulo, come la fa Consegna::manda: i tre header di Standard Webhooks e
     * il corpo, nella forma di Forme::evento (stesse chiavi, stesso ordine), firmati col segreto di `zr-auth.eventi.segreto`.
     * Il test la manda alla rotta del ricevitore (`zr-auth.eventi.percorso`) e prova il suo ascoltatore. L'`id` è un ULID
     * nuovo a ogni chiamata e `sequence` cresce di uno, a 12 cifre; il `timestamp` è adesso, se il test non lo dice.
     *
     * @param  array<string, mixed>  $data  il `data` dell'evento: un oggetto anche se vuoto
     * @return array{intestazioni: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, corpo: string}
     */
    public function consegna(string $tipo, string $subject, array $data = [], ?int $timestamp = null): array
    {
        $chiave = Firma::chiave(config('zr-auth.eventi.segreto'));

        if ($chiave === null) {
            throw new LogicException('Per consegnare un evento il finto firma col segreto di zr-auth.eventi.segreto (ZR_EVENTI_SEGRETO): "whsec_" e da 24 a 64 byte in base64.');
        }

        $timestamp ??= now()->getTimestamp();
        $id = self::id();
        $workspace = array_key_last($this->workspace) ?? self::id();

        // L'ordine delle chiavi è quello di Forme::evento; `data` resta un oggetto anche se è vuoto (in JSON un array vuoto è una lista).
        $corpo = json_encode([
            'specversion' => '1.0',
            'id' => $id,
            'source' => 'https://api.zeiras.com/workspace/'.$workspace,
            'type' => $tipo,
            'subject' => $subject,
            'time' => self::iso(CarbonImmutable::createFromTimestampUTC($timestamp)),
            'sequence' => str_pad((string) ++$this->sequenza, 12, '0', STR_PAD_LEFT),
            'autore' => null,
            'causa' => null,
            'datacontenttype' => 'application/json',
            'data' => (object) $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'intestazioni' => [
                'webhook-id' => $id,
                'webhook-timestamp' => (string) $timestamp,
                'webhook-signature' => Firma::calcola($chiave, $id, $timestamp, $corpo),
            ],
            'corpo' => $corpo,
        ];
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
            $esito = match ($operazione) {
                'accessi.crea' => $this->creaAccesso($corpo),
                'accessi.corrente.elimina' => $this->eliminaAccessoCorrente($richiesta),
                'accessi.elimina' => $this->eliminaAccesso($richiesta, $parametri[0]),
                'accessi.provider.elenca' => $this->elencaProvider($richiesta),
                'accessi.provider.autorizzazioni.crea' => $this->creaAutorizzazione($corpo, $parametri[0]),
                'accessi.provider.crea' => $this->creaAccessoDalProvider($corpo, $parametri[0]),
                'app.elenca' => $this->elencaApp($richiesta),
                'app.modifica' => $this->modificaApp($richiesta, $corpo, $parametri[0]),
                'gettoni.crea' => $this->creaGettone($richiesta, $corpo),
                'ingressi.crea' => $this->creaIngresso($richiesta, $corpo),
                'ingressi.scambio.crea' => $this->scambiaIngresso($corpo),
                'inviti.accettazione.crea' => $this->accettaInvito($richiesta, $corpo),
                'io.mostra' => $this->mostraIo($richiesta),
                'io.modifica' => $this->modificaIo($richiesta, $corpo),
                'io.password.modifica' => $this->modificaPassword($richiesta, $corpo),
                'io.email.codice.crea' => $this->creaCodice($corpo),
                'io.email.verifica.crea' => $this->verificaEmail($corpo),
                'io.aziende.elenca' => $this->elencaAziende($richiesta),
                'io.workspace.elenca' => $this->elencaWorkspace($richiesta),
                'io.workspace.crea' => $this->creaWorkspaceDellaPersona($richiesta, $corpo),
                'lingue.elenca' => $this->elencaLingue($richiesta),
                'password.recupero.crea' => $this->creaRecupero($corpo),
                'password.reimpostazione.crea' => $this->reimposta($corpo),
                'utenti.crea' => $this->creaUtente($corpo),
                'workspace.modifica' => $this->modificaWorkspace($richiesta, $corpo),
                'workspace.inviti.elenca' => $this->elencaInviti($richiesta),
                'workspace.inviti.crea' => $this->creaInvito($richiesta, $corpo),
                'workspace.inviti.elimina' => $this->eliminaInvito($richiesta, $parametri[0]),
                'workspace.membri.elenca' => $this->elencaMembri($richiesta),
                'workspace.membri.modifica' => $this->modificaMembro($richiesta, $corpo, $parametri[0]),
                'workspace.membri.elimina' => $this->eliminaMembro($richiesta, $parametri[0]),
            };
            // Le intestazioni di una risposta riuscita (la Location di una creazione) sono il terzo elemento, se c'è.
            [$stato, $dati] = $esito;
            $header = ($dati === null ? [] : ['Content-Type' => 'application/json']) + ($esito[2] ?? []);
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
     * accessi.provider.elenca (AccessiProviderController::elenca), senza gettone: i provider accesi, in ordine di slug, a
     * pagine. Il freno è uno solo per tutti e si conta prima del resto.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaProvider(Request $richiesta): array
    {
        $this->frena('provider.elenca', self::FRENI['provider_elenco'], self::MINUTO);

        return $this->pagina($richiesta, 'accessi.provider.elenca', 'provider', array_map(fn (string $slug) => ['provider' => $slug], array_keys($this->provider)), fn (array $voce) => [$voce['provider']], fn (string $slug) => [$slug]);
    }

    /**
     * accessi.provider.autorizzazioni.crea (AccessiProviderController::autorizzazioniCrea), senza gettone e senza corpo: la
     * partenza. I freni (in tutto, poi del provider) contano prima di tutto, anche per uno slug sconosciuto, che non apre un
     * conto per slug; un provider spento o sconosciuto è 404. Dà l'indirizzo del provider con lo stato e la sfida PKCE
     * (S256); il verificatore resta nel finto. Il `client_id` è `finto-<slug>`: nel backoffice lo mette l'.env.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaAutorizzazione(array $corpo, string $provider): array
    {
        $this->frena('provider.autorizzazioni:globale', self::FRENI['provider_globale'], self::MINUTO);

        if (! isset($this->provider[$provider])) {
            throw new Problema('non_trovato');
        }

        $this->frena('provider.autorizzazioni:'.$provider, self::FRENI['provider_partenza'], self::MINUTO);
        $this->testi->validaStretta($corpo, []);

        $stato = self::base64url(random_bytes(32));
        $verificatore = self::base64url(random_bytes(32));
        // Al millesimo, come il backoffice: la scadenza scritta nella risposta è quella vera.
        $scade = now()->toImmutable()->addSeconds(self::SECONDI_DELLO_STATO);
        $this->partenze[$stato] = ['provider' => $provider, 'scade' => $scade, 'verificatore' => $verificatore];

        $url = self::PROVIDER[$provider]['indirizzo'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'finto-'.$provider,
            'redirect_uri' => 'https://app.zeiras.com/auth/'.$provider.'/callback',
            'scope' => self::PROVIDER[$provider]['scope'],
            'state' => $stato,
            'code_challenge' => self::base64url(hash('sha256', $verificatore, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return [201, ['data' => ['url' => $url, 'stato' => $stato, 'scade_il' => self::iso($scade)]]];
    }

    /**
     * accessi.provider.crea (AccessiProviderController::crea, AccessoConProvider::entra), senza gettone: l'arrivo. Nell'ordine
     * del backoffice: i freni (in tutto, del provider, dello stato), il corpo, lo stato, che si consuma una volta sola e
     * anche se il resto non riesce (sconosciuto, scaduto, già usato o di un altro provider: 422 verifica_non_riuscita); poi
     * il provider, che non risponde (503) o rifiuta il codice (422), e un profilo senza email verificata (422). Un'identità
     * già collegata entra; un'email che ha un account lo collega (se l'email non era verificata lo diventa, e la password
     * di prima non vale più); un'email nuova fa nascere la persona, se la registrazione la ammette (403) e ha accettato i
     * termini (422 su `#/termini_accettati`). Risponde come accessi.crea: un accesso e il suo gettone, senza workspace.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaAccessoDalProvider(array $corpo, string $provider): array
    {
        $this->frena('provider.crea:globale', self::FRENI['provider_arrivo_globale'], self::MINUTO);

        if (! isset($this->provider[$provider])) {
            throw new Problema('non_trovato');
        }

        $this->frena('provider.crea:'.$provider, self::FRENI['provider_arrivo'], self::MINUTO);
        $dati = $this->testi->validaStretta($corpo, [
            'codice' => ['required', 'string', 'max:2048'],
            'stato' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'termini_accettati' => ['sometimes', 'nullable', 'boolean'],
        ]);
        $this->frena('provider.crea:stato:'.hash('sha256', (string) $dati['stato']), self::FRENI['provider_stato'], self::MINUTO);

        $partenza = $this->partenze[$dati['stato']] ?? null;
        unset($this->partenze[$dati['stato']]);

        if ($partenza === null || $partenza['scade']->lte(now()) || $partenza['provider'] !== $provider) {
            throw new Problema('verifica_non_riuscita');
        }

        if (isset($this->provider_guasti[$provider])) {
            throw new Problema('servizio_non_disponibile');
        }

        $profilo = $this->profili[$provider.'|'.$dati['codice']] ?? null;

        if ($profilo === null || ! $profilo['verificata'] || ! $this->emailValida($profilo['email'])) {
            throw new Problema('verifica_non_riuscita');
        }

        $email = self::normalizza($profilo['email']);
        $persona = $this->identita[$provider.'|'.$profilo['id']] ?? null;

        if ($persona === null) {
            $persona = $this->conEmail($email);

            if ($persona === null) {
                if (! $this->consente($email)) {
                    throw new Problema('registrazione_non_aperta');
                }

                if (($dati['termini_accettati'] ?? false) !== true) {
                    throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.accepted', ['attribute' => 'termini_accettati']), 'pointer' => '#/termini_accettati']]);
                }

                $persona = self::id();
                $this->persone[$persona] = [
                    'nome' => $profilo['nome'] !== null && trim($profilo['nome']) !== '' ? mb_substr(trim($profilo['nome']), 0, 255) : Str::before($email, '@'),
                    'email' => $email,
                    'email_verificata_il' => now()->toImmutable(),
                    'lingua' => 'it',
                    'fuso_orario' => 'Europe/Rome',
                    'password' => Str::random(64),
                ];
            } elseif ($this->persone[$persona]['email_verificata_il'] === null) {
                // Il provider ha provato che l'email è sua: chi scelse la password prima, magari un altro, non entra più.
                $this->persone[$persona]['email_verificata_il'] = now()->toImmutable();
                $this->persone[$persona]['password'] = Str::random(64);
            }

            $this->identita[$provider.'|'.$profilo['id']] = $persona;
        }

        $accesso = self::id();
        $this->accessi[$accesso] = ['utente' => $persona, 'creato_il' => now()->toImmutable()->startOfMillisecond(), 'chiuso' => false];

        return [201, ['data' => [
            'id' => $accesso,
            'creato_il' => self::iso($this->accessi[$accesso]['creato_il']),
            'gettone' => $this->emetti($accesso, null),
        ]]];
    }

    /** Se l'email passa le regole di Utente::REGOLE_EMAIL del backoffice. */
    private function emailValida(string $email): bool
    {
        try {
            $this->testi->valida(['email' => $email], ['email' => self::REGOLE_EMAIL]);
        } catch (Problema) {
            return false;
        }

        return true;
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
     * accessi.corrente.elimina (AccessiController::eliminaCorrente): chiude l'accesso da cui discende il gettone che chiama,
     * senza id. Da lì ogni suo gettone è 401 gettone_non_valido (la guardia lo guarda a ogni chiamata, anche una seconda
     * uscita); un altro accesso della stessa persona resta.
     *
     * @return array{int, null}
     */
    private function eliminaAccessoCorrente(Request $richiesta): array
    {
        $chi = $this->autentica($richiesta);

        $this->accessi[$chi['accesso']]['chiuso'] = true;

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
     * account, che non cambia. Nell'ordine del backoffice: l'email, il suo freno, l'invito, Turnstile, la lista dei
     * consentiti, poi il resto. La persona nuova nasce con l'email da verificare, i valori predefiniti del backoffice e il
     * primo codice; con un `invito` valido per quell'email (uno per un'altra, scaduto, revocato o sconosciuto è 422
     * verifica_non_riuscita) nasce con l'email già verificata, senza codice, ed entra nel workspace dell'invito. Per
     * un'email che ha già un account l'invito non si accetta: lo accetta la persona, con inviti.accettazione.crea.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function creaUtente(array $corpo): array
    {
        $email = self::normalizza($this->testi->valida($corpo, ['email' => self::REGOLE_EMAIL])['email']);
        $this->frena('registrazioni:'.$email, self::FRENI['registrazioni'], self::MINUTO);
        // Il codice di un invito, se c'è: dopo il freno, prima di ogni costo. Vale per quest'email sola; uno per un'altra,
        // scaduto, revocato o sconosciuto è la stessa 422.
        $invito = $this->testi->valida($corpo, ['invito' => ['sometimes', 'nullable', 'string', 'max:255']])['invito'] ?? null;

        if ($invito !== null && $this->invitoVivo($invito, $email) === null) {
            throw new Problema('verifica_non_riuscita');
        }

        // L'invito sostituisce Turnstile e la lista dei consentiti: chi ha il codice è stato scelto.
        if ($invito === null) {
            // Dal corpo pulito come lo legge il backoffice, dopo TrimStrings e ConvertEmptyStringsToNull.
            $this->controllaTurnstile(Testi::pulisci($corpo)['turnstile'] ?? null);

            if (! $this->consente($email)) {
                throw new Problema('registrazione_non_aperta');
            }
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
            'invito' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($this->conEmail($email) === null) {
            // Nel backoffice è una transazione: un workspace al tetto dà 409 e la persona non nasce.
            if ($invito !== null) {
                $this->tettoDeiMembri($this->inviti[$this->invitoDelCodice($invito) ?? '']['workspace'] ?? '');
            }

            $id = self::id();
            $this->persone[$id] = [
                'nome' => $dati['nome'] ?? Str::before($email, '@'),
                'email' => $email,
                // L'invito è arrivato a quell'indirizzo: la persona che lo accetta ha già verificato l'email.
                'email_verificata_il' => $invito === null ? null : now()->toImmutable(),
                // La predefinita di config/lingue.php del backoffice, e il fuso di chi non lo sceglie.
                'lingua' => $dati['lingua'] ?? 'it',
                'fuso_orario' => $dati['fuso_orario'] ?? 'Europe/Rome',
                'password' => $dati['password'],
            ];

            if ($invito === null) {
                $this->chiediCodice($id);
            } else {
                $this->accettaCodice($invito, $id);
            }
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
        return [200, ['data' => $this->io($this->autentica($richiesta))]];
    }

    /**
     * La forma di io.mostra (Forme::io), per la persona e il workspace del gettone: anche la risposta di io.modifica.
     *
     * @param  array{persona: string, workspace: ?string}  $chi
     * @return array<string, mixed>
     */
    private function io(array $chi): array
    {
        $workspace = $chi['workspace'];

        return [
            'utente' => $this->utente($chi['persona']),
            'workspace' => $workspace === null ? null : $this->workspace[$workspace],
            'ruolo' => $workspace === null ? null : $this->membri[$workspace][$chi['persona']],
            'notifiche_non_lette' => $workspace === null ? null : 0,
        ];
    }

    /**
     * io.modifica (IoController::modifica): il nome, la lingua e il fuso orario della persona del gettone, come JSON Merge
     * Patch sotto `utente`. Vale il solo gettone dell'accesso (#1412): uno di un workspace è 403
     * `gettone_con_workspace` prima del corpo (workspace, ruolo e notifiche null nella risposta); nell'ordine del backoffice: tutti i campi insieme, un valore sbagliato non ne lascia salvato nessuno (422 sul pointer del campo, anche
     * per un campo di altre risposte o che non esiste); un corpo senza campi da cambiare è un errore sul corpo. La persona è
     * sempre quella del gettone. Risponde con la forma di io.mostra, già aggiornata; la lingua nuova vale dalla chiamata dopo.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaIo(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);

        // Dal #1412 la scrittura sulla persona vuole il gettone dell'accesso: con quello di un workspace, 403 prima del corpo.
        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }
        $regole = [
            'utente' => ['sometimes', 'array'],
            'utente.nome' => ['filled', 'string', 'max:255'],
            'utente.lingua' => ['filled', 'string', Rule::in(Testi::LINGUE)],
            'utente.fuso_orario' => ['filled', 'string', 'timezone:all'],
        ];

        foreach (self::ALTRI_CAMPI_DI_IO as $campo => $metodo) {
            $regole[$campo] = [function (string $attributo, mixed $valore, Closure $rifiuta) use ($metodo) {
                $rifiuta($metodo === null ? 'regole.campo_di_nessun_metodo' : 'regole.campo_di_un_altro_metodo')
                    ->translate(['attribute' => $attributo, 'metodo' => (string) $metodo]);
            }];
        }

        $campi = $this->testi->validaCorpo($corpo, $regole);
        $utente = $campi['utente'] ?? [];

        if (! is_array($utente) || $utente === []) {
            throw new Problema('dati_non_validi', [[
                'detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => 'utente.nome, utente.lingua, utente.fuso_orario']),
                'pointer' => array_key_exists('utente', $campi) ? '#/utente' : '#',
            ]]);
        }

        foreach ($utente as $campo => $valore) {
            $this->persone[$chi['persona']][$campo] = $valore;
        }

        return [200, ['data' => $this->io($chi)]];
    }

    /**
     * io.password.modifica (IoPasswordController::modifica): la persona del gettone cambia la sua password, con il solo
     * gettone dell'accesso (#1412: uno di un workspace è 403 `gettone_con_workspace`). Nell'ordine del backoffice: il corpo, poi il freno degli errori sulla password
     * attuale (per persona, al sesto in un'ora 429 anche con quella giusta; una giusta lo azzera), poi la password attuale
     * (422 sul campo), poi la nuova trapelata. 204; i gettoni degli altri accessi della persona non valgono più, quelli
     * dell'accesso che chiama sì.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, null}
     */
    private function modificaPassword(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);

        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }

        $persona = $chi['persona'];
        $dati = $this->testi->validaCorpo($corpo, [
            'password_attuale' => ['required', 'string', new SenzaCarattereNullo],
            'password_nuova' => ['required', 'string', new SenzaCarattereNullo, 'min:12'],
        ]);

        $this->frena('password:'.$persona, self::ERRORI_DELLA_PASSWORD, self::ORA);

        if (! hash_equals($this->persone[$persona]['password'], (string) $dati['password_attuale'])) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.password_attuale'), 'pointer' => '#/password_attuale']]);
        }

        $this->freni->clear('password:'.$persona);

        // Have I Been Pwned del backoffice (PasswordNuova::controlla), dopo il freno: la password trapelata è del finto.
        $this->testi->valida(['password_nuova' => $dati['password_nuova']], ['password_nuova' => [function (string $campo, mixed $valore, Closure $fail) {
            if ($valore === self::PASSWORD_TRAPELATA) {
                $fail('validation.password.uncompromised')->translate();
            }
        }]]);

        $this->persone[$persona]['password'] = $dati['password_nuova'];

        foreach ($this->accessi as $id => $accesso) {
            if ($accesso['utente'] === $persona && $id !== $chi['accesso']) {
                $this->accessi[$id]['chiuso'] = true;
            }
        }

        return [204, null];
    }

    /**
     * lingue.elenca (LingueController::elenca): le lingue di Zeiras in ordine di codice, ognuna col codice e il suo nome
     * scritto in quella lingua. Vale ogni gettone, anche dell'accesso. Il cursore porta il codice dell'ultima lingua.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaLingue(Request $richiesta): array
    {
        $this->autentica($richiesta);
        $voci = [];

        foreach (self::NOMI_LINGUE as $codice => $nome) {
            $voci[] = ['codice' => $codice, 'nome' => $nome];
        }

        return $this->pagina($richiesta, 'lingue.elenca', 'codice', $voci, fn (array $lingua) => [$lingua['codice']], fn (string $codice) => [$codice]);
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
     * io.aziende.elenca (IoAziendeController::elenca): le aziende dei workspace di cui la persona del gettone è membro, una
     * volta sola ciascuna, in ordine di nome (maiuscole e accenti non contano) e poi di id, a pagine col solo id. Vale ogni
     * gettone della persona, anche quello dell'accesso. Il nome è quello che l'azienda ebbe alla nascita.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaAziende(Request $richiesta): array
    {
        $persona = $this->autentica($richiesta)['persona'];
        $voci = [];

        foreach ($this->membri as $workspace => $ruoli) {
            if (isset($ruoli[$persona])) {
                $id = $this->workspace[$workspace]['azienda_id'];
                $voci[$id] = ['id' => $id, 'nome' => $this->aziende[$id]];
            }
        }

        return $this->pagina($richiesta, 'io.aziende.elenca', 'id', array_values($voci), self::perNomeEId(...),
            fn (string $id) => isset($this->aziende[$id]) ? self::perNomeEId(['id' => $id, 'nome' => $this->aziende[$id]]) : null);
    }

    /** Un'azienda nuova, col nome del workspace che la fa nascere (Workspace::booted del backoffice), e il suo id. */
    private function nuovaAzienda(string $nome): string
    {
        $id = self::id();
        $this->aziende[$id] = $nome;

        return $id;
    }

    /**
     * io.workspace.crea (IoWorkspaceController::crea): un workspace nuovo, di cui la persona del gettone è proprietaria.
     * Nell'ordine del backoffice: la Idempotency-Key (il metodo è della persona, non del workspace del gettone: la stessa
     * chiave vale per la persona, ma il metodo vuole il gettone dell'accesso: uno di un workspace è 403 prima di ogni altra cosa), l'email non verificata (403, prima del corpo), il corpo (422 su `nome`; un campo in più
     * si ignora, come Corpo::soloCorpo, perché un corpo con un campo in più non rompe una rotta nata prima della regola), l'azienda che non è della persona (404, come un id altrui), il freno di
     * workspace nuovi all'ora per persona (429; una risposta ripetuta dalla chiave non arriva al freno); poi nasce, con
     * un'azienda sua se non ne dà una. Il workspace non ha app attive. La risposta è lo schema WorkspaceConRuolo, senza
     * `Location` (il contratto ha il solo `Link`).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaWorkspaceDellaPersona(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);

        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }

        $chi['workspace'] = '';

        return $this->conIdempotenza($richiesta, 'io.workspace.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $persona = $chi['persona'];

            if ($this->persone[$persona]['email_verificata_il'] === null) {
                throw new Problema('email_non_verificata');
            }

            $campi = $this->testi->valida($corpo, [
                'nome' => ['required', 'string', 'max:255'],
                'azienda_id' => ['sometimes', 'string'],
            ]);
            $azienda = $campi['azienda_id'] ?? null;

            if ($azienda !== null && ! $this->appartieneAllAzienda($persona, $azienda)) {
                throw new Problema('non_trovato');
            }

            $this->frena('freni:persona:io.workspace.crea:'.$persona, self::FRENI['workspace'], self::ORA);

            $id = self::id();
            $this->workspace[$id] = ['id' => $id, 'nome' => $campi['nome'], 'slug' => $this->nuovoSlug($campi['nome']), 'azienda_id' => $azienda ?? $this->nuovaAzienda($campi['nome'])];
            $this->membri[$id][$persona] = 'proprietario';

            return [201, ['data' => [...$this->workspace[$id], 'ruolo' => 'proprietario']], []];
        });
    }

    /** Se la persona appartiene già all'azienda data: è membro di un workspace con quella azienda_id. */
    private function appartieneAllAzienda(string $persona, string $azienda): bool
    {
        foreach ($this->workspace as $id => $workspace) {
            if ($workspace['azienda_id'] === $azienda && isset($this->membri[$id][$persona])) {
                return true;
            }
        }

        return false;
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
     * workspace.modifica (WorkspaceController::modifica): il nome del workspace del gettone, a proprietario e
     * amministratore (403 prima del corpo). Il nome va da 1 a 255 caratteri, senza gli spazi ai bordi; lo slug nasce col
     * workspace e non cambia; lo stesso nome di prima non scrive niente. Risponde con un elemento di io.workspace.elenca.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaWorkspace(Request $richiesta, array $corpo): array
    {
        $chi = $this->conWorkspace($richiesta, ['proprietario', 'amministratore']);
        $workspace = $chi['workspace'];
        $this->workspace[$workspace]['nome'] = (string) $this->testi->validaStretta($corpo, ['nome' => ['required', 'string', 'max:255']])['nome'];

        return [200, ['data' => [...$this->workspace[$workspace], 'ruolo' => $this->membri[$workspace][$chi['persona']]]]];
    }

    /**
     * workspace.membri.modifica (MembriController::modifica): il ruolo di un membro, amministratore o membro. Nell'ordine del
     * backoffice: il ruolo di chi chiama (403, il middleware), il membro del workspace (404), il corpo (422), il
     * proprietario (409), e un amministratore che non agisce su un membro lasciandolo membro (403). Lo stesso ruolo di
     * prima non scrive niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaMembro(Request $richiesta, array $corpo, string $persona): array
    {
        $chi = $this->conWorkspace($richiesta, ['proprietario', 'amministratore']);
        $workspace = $chi['workspace'];
        $this->membroDi($workspace, $persona);
        $ruolo = (string) $this->testi->validaStretta($corpo, ['ruolo' => ['required', 'string', Rule::in(['amministratore', 'membro'])]])['ruolo'];

        $this->controllaIlBersaglio($this->membri[$workspace][$chi['persona']], $this->membri[$workspace][$persona]);

        if ($this->membri[$workspace][$chi['persona']] === 'amministratore' && $ruolo !== 'membro') {
            throw new Problema('permesso_negato');
        }

        $this->membri[$workspace][$persona] = $ruolo;

        return [200, ['data' => ['id' => $persona, 'nome' => $this->persone[$persona]['nome'], 'email' => $this->persone[$persona]['email'], 'ruolo' => $ruolo]]];
    }

    /**
     * workspace.membri.elimina (MembriController::elimina): toglie un membro dal workspace, con i suoi gettoni di questo
     * workspace; quelli dell'accesso e degli altri workspace restano. Come membri.modifica: il proprietario è 409,
     * l'amministratore su un amministratore 403, e un id che non è di un membro 404.
     *
     * @return array{int, null}
     */
    private function eliminaMembro(Request $richiesta, string $persona): array
    {
        $chi = $this->conWorkspace($richiesta, ['proprietario', 'amministratore']);
        $workspace = $chi['workspace'];
        $this->membroDi($workspace, $persona);
        $this->controllaIlBersaglio($this->membri[$workspace][$chi['persona']], $this->membri[$workspace][$persona]);

        unset($this->membri[$workspace][$persona]);

        // I gettoni di quel workspace non tornano se la persona rientra: nel backoffice sono archiviati.
        foreach ($this->gettoni as $gettone => $riga) {
            if ($riga['workspace'] === $workspace && $this->accessi[$riga['accesso']]['utente'] === $persona) {
                unset($this->gettoni[$gettone]);
            }
        }

        return [204, null];
    }

    /** Il membro del workspace con quell'id di persona (MembriController::membroDi): 404 se non c'è, o è di un altro workspace. */
    private function membroDi(string $workspace, string $persona): void
    {
        if (! isset($this->membri[$workspace][$persona])) {
            throw new Problema('non_trovato');
        }
    }

    /** Il proprietario non si tocca (409); un amministratore tocca solo i membri (403). */
    private function controllaIlBersaglio(string $ruoloDiChiChiama, string $ruoloDelBersaglio): void
    {
        if ($ruoloDelBersaglio === 'proprietario') {
            throw new Problema('proprietario_intoccabile');
        }

        if ($ruoloDiChiChiama === 'amministratore' && $ruoloDelBersaglio !== 'membro') {
            throw new Problema('permesso_negato');
        }
    }

    /**
     * workspace.inviti.elenca (InvitiController::elenca): gli inviti vivi del workspace, dal più recente, a cursore, a
     * proprietario e amministratore. Un invito scaduto non è vivo; revocati e accettati non ci sono più. Mai il codice.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaInviti(Request $richiesta): array
    {
        $workspace = $this->conWorkspace($richiesta, ['proprietario', 'amministratore'])['workspace'];
        $voci = [];

        foreach ($this->inviti as $invito) {
            if ($invito['workspace'] === $workspace && $invito['scade']->gt(now())) {
                $voci[] = $this->voceInvito($invito);
            }
        }

        // Il cursore porta l'id dell'ultimo invito, che nel frattempo può essere stato revocato: la posizione è l'id stesso.
        return $this->pagina($richiesta, 'workspace.inviti.elenca', 'id', $voci, fn (array $invito) => [$invito['id']], fn (string $id) => [$id], dalPiuRecente: true);
    }

    /**
     * workspace.inviti.crea (InvitiController::crea): 201 con l'invito e la Location, e una mail col codice, che si legge da
     * ultimoInvito(). Nell'ordine del backoffice: il ruolo di chi chiama (403), la Idempotency-Key, il corpo (422); un
     * amministratore invita solo come `membro` (403); i freni, per email e per workspace (429); poi il tetto degli inviti
     * vivi (409 limite_raggiunto), chi è già membro (409 gia_membro) e un invito vivo per la stessa email (409
     * invito_esistente), mentre uno scaduto della stessa email si archivia. La risposta è la stessa per un'email con un
     * account e per una senza (G11).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaInvito(Request $richiesta, array $corpo): array
    {
        $chi = $this->conWorkspace($richiesta, ['proprietario', 'amministratore']);
        $workspace = $chi['workspace'];

        return $this->conIdempotenza($richiesta, 'workspace.inviti.crea', $chi, $corpo, function () use ($chi, $workspace, $corpo) {
            $campi = $this->testi->validaStretta($corpo, [
                'email' => self::REGOLE_EMAIL,
                'ruolo' => ['required', 'string', Rule::in(['amministratore', 'membro'])],
            ]);

            if ($this->membri[$workspace][$chi['persona']] === 'amministratore' && $campi['ruolo'] !== 'membro') {
                throw new Problema('permesso_negato');
            }

            $email = self::normalizza($campi['email']);

            // Ogni invito è una mail che parte da Zeiras: si conta prima di ogni lavoro, per email e per workspace, mai per IP.
            $this->frena('inviti:'.$email, self::FRENI['inviti_per_email'], self::ORA);
            $this->frena('inviti-workspace:'.$workspace, self::FRENI['inviti_per_workspace'], self::ORA);

            $vivi = array_filter($this->inviti, fn (array $invito) => $invito['workspace'] === $workspace && $invito['scade']->gt(now()));

            if (count($this->membri[$workspace] ?? []) + count($vivi) >= self::MEMBRI) {
                throw new Problema('limite_raggiunto');
            }

            $persona = $this->conEmail($email);

            if ($persona !== null && isset($this->membri[$workspace][$persona])) {
                throw new Problema('gia_membro');
            }

            $dellEmail = array_filter($this->inviti, fn (array $invito) => $invito['workspace'] === $workspace && $invito['email'] === $email);

            if (array_filter($dellEmail, fn (array $invito) => $invito['scade']->gt(now())) !== []) {
                throw new Problema('invito_esistente');
            }

            // Scaduto e mai accettato: non è più un invito, e il suo codice non vale.
            foreach (array_keys($dellEmail) as $scaduto) {
                unset($this->inviti[$scaduto]);
            }

            $id = self::id();
            $adesso = now()->toImmutable()->startOfMillisecond();
            $this->inviti[$id] = [
                'id' => $id,
                'workspace' => $workspace,
                'email' => $email,
                'ruolo' => $campi['ruolo'],
                'codice' => self::base64url(random_bytes(32)),
                'scade' => $adesso->addDays(self::GIORNI_DELL_INVITO),
                'creato_il' => $adesso,
            ];
            $this->postaInviti[$email] = $this->inviti[$id]['codice'];

            return [201, ['data' => $this->voceInvito($this->inviti[$id])], ['Location' => "/v1/workspace/inviti/{$id}"]];
        });
    }

    /**
     * workspace.inviti.elimina (InvitiController::elimina): revoca un invito del workspace, che da lì risponde 404 e il cui
     * codice non vale più. Il ruolo di chi chiama (403), poi l'invito (404: anche uno di un altro workspace); l'invito a un
     * amministratore lo revoca il proprietario (403 all'amministratore).
     *
     * @return array{int, null}
     */
    private function eliminaInvito(Request $richiesta, string $invito): array
    {
        $chi = $this->conWorkspace($richiesta, ['proprietario', 'amministratore']);
        $workspace = $chi['workspace'];

        if (($this->inviti[$invito]['workspace'] ?? null) !== $workspace) {
            throw new Problema('non_trovato');
        }

        if ($this->membri[$workspace][$chi['persona']] === 'amministratore' && $this->inviti[$invito]['ruolo'] !== 'membro') {
            throw new Problema('permesso_negato');
        }

        unset($this->inviti[$invito]);

        return [204, null];
    }

    /**
     * inviti.accettazione.crea (InvitiAccettazioneController::crea): la persona del gettone dell'accesso entra nel workspace
     * dell'invito col suo ruolo (201, la forma Membro), solo se l'email dell'invito è la sua ed è verificata. Il gettone di
     * un workspace è 403 prima del corpo; ogni fallimento è la stessa 422 verifica_non_riuscita (G11); chi è già membro 409.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function accettaInvito(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);

        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }

        $codice = (string) $this->testi->validaStretta($corpo, ['codice' => ['required', 'string', 'max:255']])['codice'];
        $workspace = $this->accettaCodice($codice, $chi['persona']);
        $persona = $this->persone[$chi['persona']];

        return [201, ['data' => ['id' => $chi['persona'], 'nome' => $persona['nome'], 'email' => $persona['email'], 'ruolo' => $this->membri[$workspace][$chi['persona']]]]];
    }

    /** Un membro in più nel workspace dell'invito che si accetta (Tetti::membri): con 50 membri, 409 limite_raggiunto. */
    private function tettoDeiMembri(string $workspace): void
    {
        if (count($this->membri[$workspace] ?? []) >= self::MEMBRI) {
            throw new Problema('limite_raggiunto');
        }
    }

    /** L'id dell'invito che ha questo codice, se è ancora un invito (non revocato né accettato): null se no. */
    private function invitoDelCodice(#[SensitiveParameter] string $codice): ?string
    {
        foreach ($this->inviti as $id => $invito) {
            if (hash_equals($invito['codice'], $codice)) {
                return $id;
            }
        }

        return null;
    }

    /** Dice se il codice è di un invito vivo per questa email, senza consumarlo (Inviti::valido): l'id dell'invito, o null. */
    private function invitoVivo(#[SensitiveParameter] string $codice, string $email): ?string
    {
        $id = $this->invitoDelCodice($codice);

        return $id !== null && $this->inviti[$id]['scade']->gt(now()) && $this->inviti[$id]['email'] === $email ? $id : null;
    }

    /**
     * Fa entrare la persona nel workspace dell'invito e consuma l'invito (Inviti::accetta). Il codice sconosciuto, scaduto,
     * revocato, già usato, di un'altra email o per una persona con l'email non verificata dà la stessa 422
     * verifica_non_riuscita; chi è già membro, 409 gia_membro.
     *
     * @return string il workspace dell'invito
     */
    private function accettaCodice(#[SensitiveParameter] string $codice, string $persona): string
    {
        $id = $this->invitoDelCodice($codice);

        if ($id === null || ! $this->inviti[$id]['scade']->gt(now()) || $this->inviti[$id]['email'] !== $this->persone[$persona]['email']
            || $this->persone[$persona]['email_verificata_il'] === null) {
            throw new Problema('verifica_non_riuscita');
        }

        $invito = $this->inviti[$id];

        if (isset($this->membri[$invito['workspace']][$persona])) {
            throw new Problema('gia_membro');
        }

        $this->tettoDeiMembri($invito['workspace']);

        $this->membri[$invito['workspace']][$persona] = $invito['ruolo'];
        unset($this->inviti[$id]);

        return $invito['workspace'];
    }

    /**
     * Un invito come lo dà il backoffice (Forme::invito): mai il codice.
     *
     * @param  array{id: string, workspace: string, email: string, ruolo: string, codice: string, scade: CarbonImmutable, creato_il: CarbonImmutable}  $invito
     * @return array<string, mixed>
     */
    private function voceInvito(array $invito): array
    {
        return ['id' => $invito['id'], 'email' => $invito['email'], 'ruolo' => $invito['ruolo'], 'scade_il' => self::iso($invito['scade']), 'creato_il' => self::iso($invito['creato_il'])];
    }

    /**
     * L'header Idempotency-Key di un metodo che lo accetta (Idempotenza): senza, il metodo gira com'è. La chiave è della
     * persona, del metodo e del workspace; per 24 ore la stessa chiave con lo stesso corpo dà la risposta della prima
     * richiesta senza rifarla, con la sua Location, e con un altro corpo è 422 chiave_idempotenza_riusata. Si ricordano solo
     * le risposte riuscite. Una chiave che non è da 1 a 255 caratteri ASCII visibili è 422 sull'header.
     *
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     * @param  array<mixed>  $corpo
     * @param  Closure(): array{int, array<string, mixed>, array<string, string>}  $esegue
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function conIdempotenza(Request $richiesta, string $operazione, array $chi, array $corpo, Closure $esegue): array
    {
        $psr = $richiesta->toPsrRequest();

        if (! $psr->hasHeader('Idempotency-Key')) {
            return $esegue();
        }

        $chiave = $psr->getHeaderLine('Idempotency-Key');

        if (preg_match('/^[!-~]{1,255}$/', $chiave) !== 1) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.chiave_idempotenza'), 'header' => 'Idempotency-Key']]);
        }

        $nome = implode(':', [$chi['persona'], $operazione, $chi['workspace'], hash('sha256', $chiave)]);
        // Il corpo come lo legge il backoffice: lo stesso corpo scritto in un altro modo (l'ordine delle chiavi, gli spazi ai bordi) è lo stesso.
        $impronta = hash('sha256', json_encode(self::ordinato(Testi::pulisci($corpo)), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $ricordata = $this->ricordate[$nome] ?? null;

        if ($ricordata !== null && $ricordata['scade'] > now()->getTimestamp()) {
            if (! hash_equals($ricordata['impronta'], $impronta)) {
                throw new Problema('chiave_idempotenza_riusata');
            }

            return [$ricordata['stato'], $ricordata['dati'], $ricordata['header']];
        }

        $esito = $esegue();
        $this->ricordate[$nome] = ['impronta' => $impronta, 'stato' => $esito[0], 'dati' => $esito[1], 'header' => $esito[2], 'scade' => now()->getTimestamp() + self::SECONDI_DELL_IDEMPOTENZA];

        return $esito;
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
     * @param  bool  $dalPiuRecente  l'ordine al contrario, dalla posizione più alta (gli inviti: gli id sono ULID, in ordine di nascita)
     * @return array{int, array<string, mixed>}
     */
    private function pagina(Request $richiesta, string $lista, string $chiave, array $voci, Closure $posizione, Closure $posizioneDelCursore, bool $dalPiuRecente = false): array
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
        $verso = $dalPiuRecente ? -1 : 1;
        usort($voci, fn (array $una, array $altra) => $verso * self::confronta($posizione($una), $posizione($altra)));

        if (isset($query['cursore'])) {
            $dopo = $posizioneDelCursore((string) $this->leggiCursore($lista, $chiave, $query['cursore']))
                ?? throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.cursore', ['attribute' => 'cursore']), 'parameter' => 'cursore']]);
            $voci = array_values(array_filter($voci, fn (array $voce) => $verso * self::confronta($posizione($voce), $dopo) > 0));
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

    /** Un valore con le chiavi di ogni oggetto in ordine (Idempotenza::ordinato): le liste restano come sono. */
    private static function ordinato(mixed $valore): mixed
    {
        if (! is_array($valore)) {
            return $valore;
        }

        if (! array_is_list($valore)) {
            ksort($valore, SORT_STRING);
        }

        return array_map(self::ordinato(...), $valore);
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
