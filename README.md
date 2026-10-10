# zr-auth

Il pacchetto dei frontend di Zeiras (`zr-home`, `zr-board`, …): la sessione col **gettone** del backoffice, il client
delle API `/v1` di `zr-backoffice` (`https://api.zeiras.com`), la guardia sulle rotte e il suo test, e un backoffice
finto per i test. **Nessun database**: un frontend non ha tabelle sue, e ogni dato lo chiede alle API col gettone.

Lo scrive l'agente `zr-backoffice`: è l'altra metà del suo contratto (`openapi/v1.yaml`, la documentazione su
`docs.zeiras.com`).

**Repo pubblico di proposito**: i frontend lo installano da Composer senza credenziali. Quindi qui dentro **nessun
segreto, mai**, e nessun indirizzo interno. La guardia è `.github/nessun-segreto.sh`: la lancia la CI a ogni push, su ogni
ramo, e a ogni pull request; si lancia anche in locale dalla radice del repo, ed esce 1 dicendo file, riga e nome della
variabile, o la forma trovata (mai il valore); esce 2, e lo dice, quando non ha letto tutto (fuori da un repo git, git che
fallisce, un file che non si apre o che si apre e non si legge, come un sottomodulo). Le sue prove, un repo git temporaneo
per caso, sono `.github/prova-nessun-segreto.sh`, e le lancia la CI. La CI vede un segreto quando è già pubblicato: prima
di un push, la guardia si lancia in locale.

### Cosa vede la guardia, e cosa no

Un nome è segreto se contiene SECRET, KEY, TOKEN, PASSWORD, PASSWD, PWD, SEGRETO, SEGRETI, CHIAVE, CHIAVI, GETTONE,
GETTONI, WEBHOOK, DSN, CREDENTIAL o CREDENZIAL, o PASS come parola intera del nome (`DB_PASS` sì, `BYPASS` e `PASSO` no),
in maiuscolo o in minuscolo; fra virgolette può avere anche `-` e `.` (`X-Api-Key`, `zr-auth.chiave`). La guardia legge i
file che git conosce, e vede:

- un file sensibile, dal nome: `.env` e `.env.*`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`, `*.ppk`,
  `auth.json`, e un archivio (`*.zip`, `*.tar`, `*.gz`, `*.tgz`, `*.bz2`, `*.xz`, `*.7z`, `*.rar`, `*.jar`, `*.phar`),
  che non sa leggere;
- una chiave privata (`BEGIN … PRIVATE KEY`, PEM e OpenSSH, o una chiave PuTTY), in qualunque file di testo;
- nel PHP, un valore di riserva per un nome segreto: `env('…', valore)`, `env('…') ?: valore`, `env('…') ?? valore`, lo
  stesso con `getenv()` e `Env::get()`, e `$_ENV['…']` o `$_SERVER['…']` seguiti da `?:` o `??`;
- nella configurazione di PHPUnit, un valore non vuoto per un nome segreto (`<env>`, `<server>`, `<var>`, `<const>`,
  `<ini>`);
- in ogni file di testo, un valore per un nome segreto scritto:
  - come in un `.env` o in una riga di comando, `NOME=valore` senza spazi intorno all'uguale;
  - in un file YAML, `NOME: valore` a inizio riga, anche con la chiave fra virgolette o uno spazio prima dei due punti
    (anche `key:`: la chiave di `actions/cache` si scrive come un'espressione sola, `${{ steps.x.outputs.chiave }}`);
  - con la chiave fra virgolette, come in un JSON (`"nome": "valore"`) o in un array PHP (`'nome' => 'valore'`), e in
    `config('nome', 'valore')`, `Config::set()`, `define()` e `->withHeader()`, anche col valore a capo;
  - con la chiave senza virgolette e il valore fra virgolette, come negli argomenti con nome del PHP e negli oggetti JS
    (`nome: 'valore'`);
- in ogni file di testo, dalla forma e senza un nome accanto: un gettone di Zeiras (`zr_` e 48 lettere o cifre), un
  webhook di Slack, un gettone dopo `Bearer` (almeno 8 segni, con una cifra o un `_`), un indirizzo interno (la rete 10/8,
  il loopback che non è `127.0.0.1`, i nomi ssh dei server, i domini interni di Management Academy).

Passano i segnaposto, ma solo come valore intero: vuoto, `""`, `''`, `<valore>`, `$VAR`, `${VAR}`, `${{ secrets.X }}`,
`{{ x }}`, `…`, `...`, `null`, `~`, anche fra virgolette. Dopo un segnaposto la riga finisce o viene uno spazio (in YAML,
un commento); dopo il vuoto, la riga finisce o viene un commento (uno spazio e `#`); e passa la virgoletta, o il backtick,
che apriva l'assegnazione (`"NOME=$VAR"`, `` `NOME=` ``). Tutto il resto è il valore: `~…`, `...…`, `null-…`, `null,…`,
`''…`, `" …`, `".…`, un backtick, `#…`, `)…`, `,…`, uno spazio e un testo, `$` seguito da minuscole, `{…}` scattano.

Non vede:

- un file con un carattere NUL (un file binario), e quello che c'è dentro un archivio (che però scatta dal nome);
- un valore con uno spazio prima dell'uguale (`NOME = valore`), quindi un'assegnazione nel codice (`$password = '…'`);
- con la chiave fra virgolette, un valore che non è fra virgolette (una costante, una variabile, un numero), un valore
  costruito che comincia con un segnaposto (`''.'…'`), e la stessa coppia in una chiamata diversa da `config()`,
  `Config::set()`, `define()` e `->withHeader()` (`Arr::set($a, 'nome', '…')`);
- `NOME: valore` senza virgolette fuori da un file YAML, e in un YAML se non sta a inizio riga (`{nome: valore}`);
- `nome: 'valore'` dopo un `$`, un `->`, un `::`, un `.` o una virgoletta (una variabile, una proprietà, una stringa);
- le traduzioni (`resources/lang/`) con la chiave fra virgolette: le loro chiavi sono i nomi dei messaggi
  (`current_password`), e sono le copie di quelle del backoffice;
- un segreto senza un nome segreto accanto e senza una delle forme di sopra (un gettone di un altro servizio), un
  `Bearer` con meno di 8 segni o di sole lettere, un indirizzo di un'altra rete privata (192.168/16, 172.16/12);
- la storia di git: solo i file di adesso. In locale legge i file come sono sul disco, non come sono nell'indice; nella
  CI sono la stessa cosa.

## Installazione

Da GitHub, a un tag (le versioni sono semver; prima della 1.0 un minore nuovo può rompere):

```json
"repositories": [{"type": "vcs", "url": "https://github.com/ma-cpmakers/zr-auth"}],
"require": {"zeiras/zr-auth": "^0.10"}
```

Un minore esce quando il backoffice ha i suoi metodi. La 0.3 porta le letture (`io.mostra`, `io.workspace.elenca`,
`app.elenca`, `workspace.membri.elenca`) e lo `slug` del workspace: il backoffice le ha da quando le loro righe sono in
`https://docs.zeiras.com/v1/novita`, che esce col deploy. Con la 0.2 il finto non le conosce, e lancia
`RichiestaSconosciuta`. La 0.4 porta la registrazione (`utenti.crea`) con Turnstile, e i testi dei suoi due codici
nuovi, `turnstile_non_valido` e `turnstile_non_disponibile`. La 0.5 porta `Sessione::ritorno()`, il ritorno dopo l'ingresso solo da un
GET e dallo stesso sito; un 3xx del backoffice, che non si segue, diventa `BackofficeNonRisponde`; nel finto `pm` è
`disponibile`, e ci sono i testi dei due codici nuovi, `app_non_attiva` e `app_in_arrivo`. La 0.6 porta l'ingresso nei moduli
dal lato del modulo (`Ingresso::verso()` e il ricevitore, sotto), e ha il finto di password e ingressi. La 0.6.1 porta nel
finto le lingue (`lingue.elenca`), la persona (`io.modifica`, `io.password.modifica`) e il workspace (`workspace.modifica`,
i membri e gli inviti, con `ultimoInvito()`); un testo cambia: `verifica_non_riuscita` dice anche gli inviti. La 0.10 porta
nel finto l'accesso con un provider (`accessi.provider.elenca`, `accessi.provider.autorizzazioni.crea`,
`accessi.provider.crea`); il client non cambia, perché sono tre chiamate di `Api::senzaGettone()` (sotto).

| Variabile | Default | Cosa |
|---|---|---|
| `ZR_API_URL` | `https://api.zeiras.com` | le API del backoffice; solo `https://` (sulla porta 80 il server risponde 301, e un 301 trasforma un POST in GET) |
| `ZR_AUTH_INGRESSO` | `https://app.zeiras.com/accedi` | la pagina d'accesso, dove la guardia rimanda chi non ha una sessione |
| `ZR_HOME_URL` | `https://app.zeiras.com` | zr-home, a cui il modulo manda la persona (`/ingresso`); solo `https://` |
| `ZR_APP` | — | il codice dell'app del modulo nel catalogo (`pm`, …): obbligatorio per `Ingresso::verso()` |
| `ZR_AUTH_ERRORE` | la pagina d'accesso | la pagina per ogni ritorno che non vale (config `zr-auth.errore`) |

Nella config pubblicata (`zr-auth-config`) altre due chiavi: `ricevitore` (`/ingresso/ritorno`) e `dopo` (`/`, dove si va
se la guardia non ricordava una pagina).

La sessione del frontend sta **lato server** (Redis, con un TTL): con `SESSION_DRIVER=cookie` zr-auth si rifiuta di
salvare il gettone (`SessioneNelBrowser`).

## La sessione col gettone

```php
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Sessione;

// L'accesso (la pagina pubblica del frontend): accessi.crea, senza gettone.
try {
    $accesso = Api::senzaGettone()->post('/v1/accessi', ['email' => $email, 'password' => $password]);
} catch (ErroreApi $e) {
    // $e->codice: credenziali_non_valide, troppe_richieste (con $e->riprovaFra), dati_non_validi ($e->errori)
    return back()->withErrors(['accesso' => $e->dettaglio]);
}
Sessione::apri($accesso['data']);            // il gettone dell'accesso; l'id della sessione è nuovo

// L'ingresso in un workspace: gettoni.crea, col gettone dell'accesso.
$gettone = Api::persona()->post('/v1/gettoni', ['workspace_id' => $id]);
Sessione::entra($gettone['data']);           // da qui Api::workspace() manda il gettone di quel workspace

Sessione::utente();     // la persona (lo schema Utente), mai il gettone
Sessione::workspace();  // {id, nome, slug}; null prima di entra()
Sessione::ruolo();      // proprietario, amministratore o membro
Sessione::aggiorna($io);  // $io = i `data` di io.mostra che la pagina ha già letto (non una chiamata in più); lingua e nome, se diversi (true se ha cambiato); il resto resta

// L'uscita: accessi.corrente.elimina chiude l'accesso da cui discende il gettone della sessione, e ogni gettone che ne
// discende, senza l'id dell'accesso: vale anche per una sessione aperta solo col gettone di un workspace. La sessione si
// chiude comunque, anche se il backoffice non risponde.
try {
    Api::persona()->delete('/v1/accessi/corrente');
} finally {
    Sessione::chiudi();
}
```

`Sessione::aggiorna()` non chiama il backoffice: riceve i `data` di `io.mostra` che la pagina ha già letto (la cornice di zr-core
li legge a ogni pagina, con `Api::workspace()`), così non c'è una seconda lettura per pagina. I guasti di quella lettura sono
della pagina: `BackofficeNonRisponde` ed `ErroreApi` si possono prendere per non rompere la pagina e lasciare la lingua com'è;
`GettoneRifiutato` mai, perché è lui a chiudere la sessione (un 401 è un gettone revocato, non un guasto).

Una pagina che vuole il workspace guarda prima `Sessione::workspace()`: senza, la persona è entrata ma non ha ancora
scelto un workspace, e `Api::workspace()` lancia `LogicException`.

Il gettone non esce mai dalla sessione: nessun metodo lo restituisce. Non va nell'HTML, nelle props di Inertia, né in
un cookie (spec S01, prova 8).

## Il blocco della sessione: una richiesta lenta non rimette la sessione di prima

Laravel carica la sessione all'inizio di una richiesta e la riscrive alla fine. Se una scheda ha una richiesta lenta in corso e
un'altra scheda esce o cambia workspace, la lenta, finendo dopo, rimette la sessione di prima. Il rimedio è il blocco della
sessione di Laravel (`Route::block`): zr-auth lo mette sul suo ricevitore e dà ai moduli una riga sola per le loro rotte.

```php
Route::post('esci', EsciController::class)->bloccaSessione();                  // chiama Sessione::chiudi()
Route::post('workspace/{id}/entra', EntraController::class)->bloccaSessione(); // chiama Sessione::entra()
Route::get('report', ReportController::class)->bloccaSessione();               // una rotta lenta, che riscrive la sessione a fine corsa
```

- **Cosa fa.** `->bloccaSessione()` è un `block(10, 3)` (`Sessione::BLOCCO_TENUTA`, `Sessione::BLOCCO_ATTESA`): la richiesta tiene il
  lock della sessione fino a 10 secondi e ne aspetta un altro per al più **3 secondi**. Un processo PHP che aspetta manca a tutti
  i siti del server (il pool è uno solo): per questo l'attesa è corta.
- **Dove lo mette zr-auth.** Sul ricevitore del codice (`zr-auth.ricevitore`). Le rotte dei moduli lo mettono i moduli: quelle
  che chiamano `Sessione::apri()`, `Sessione::entra()` o `Sessione::chiudi()` **e** le loro rotte lente. Non va su tutto: due
  pagine della stessa persona caricate insieme si metterebbero in fila.
- **Oltre l'attesa** la risposta è **503** con `Retry-After: 1`, mai un 500 e mai «prosegui senza il blocco» (riaprirebbe la
  gara). Il corpo non dice di chi è il blocco. Vale solo per una rotta con il blocco: il timeout di un altro lock del modulo
  resta com'è.
- **Chi aspetta dietro un ingresso.** `Sessione::apri()` e `Sessione::entra()` rigenerano l'id della sessione. Una richiesta di
  un'altra scheda che aspettava il lock dell'id di prima riparte, dopo l'attesa, da una sessione vuota: risponde come a una
  persona non entrata e può rimandare il cookie con l'id di prima. Il blocco evita che la sessione di prima torni, non che
  quella richiesta sia inutile: la pagina dopo un ingresso si ricarica dalla scheda in cui si è entrati.
- **Dove sta il lock.** In `session.block_store` (`SESSION_BLOCK_STORE`), cioè nello store di cache del modulo se non lo
  cambi; deve poter fare i lock (Redis, database, file, array nei test). Il lock è perso se lo store lo butta: il rischio è la
  gara di prima, non un errore.

## L'accesso con un provider (Google, LinkedIn, Facebook)

Tre metodi senza gettone, e nessun codice nuovo nel client: la pagina d'ingresso chiama `Api::senzaGettone()`.

```php
// 1. Quali bottoni mostrare: i provider accesi. Dice solo lo slug (google, linkedin-openid, facebook); il nome e il
//    bottone sono della pagina. Quando Zeiras ne accende uno, compare qui da solo.
$provider = Api::senzaGettone()->get('/v1/accessi/provider')['data'];            // [['provider' => 'google'], …]

// 2. La partenza: l'indirizzo a cui mandare la persona. Lo `stato` si lega al browser (un cookie di sessione) prima di
//    mandarla: al ritorno si controlla che sia lo stesso, PRIMA di chiamare il passo 3.
$partenza = Api::senzaGettone()->post("/v1/accessi/provider/{$slug}/autorizzazioni")['data'];   // url, stato, scade_il
session()->put('provider.stato', $partenza['stato']);
return redirect()->away($partenza['url']);

// 3. L'arrivo: il provider rimanda la persona a https://app.zeiras.com/auth/<provider>/callback con `code` e `state`.
//    Se lo `state` non è quello della sessione, la pagina non chiama nulla. Altrimenti:
try {
    $accesso = Api::senzaGettone()->post("/v1/accessi/provider/{$slug}", [
        'codice' => $request->query('code'),
        'stato' => $request->query('state'),
        'termini_accettati' => $request->boolean('termini'),     // solo se la persona è nuova
    ]);
} catch (ErroreApi $e) {
    // verifica_non_riuscita (ogni rifiuto: ripartire dal passo 2), registrazione_non_aperta, dati_non_validi su
    // #/termini_accettati (la persona nuova deve accettarli), servizio_non_disponibile (il provider non risponde),
    // non_trovato (provider spento), troppe_richieste
}
Sessione::apri($accesso['data']);                                 // come dopo accessi.crea: un accesso, senza workspace
```

Lo `stato` vale 10 minuti e una volta sola, anche se l'arrivo non riesce. Un'email che il provider non garantisce non
entra. Una persona che ha già un account con quell'email lo collega (e se l'email era da verificare diventa verificata, e la
sua vecchia password non vale più); una nuova nasce con l'email verificata, se la registrazione la ammette.

## Il client delle API

`Api::senzaGettone()`, `Api::persona()` (il gettone dell'accesso; se il frontend ha solo quello di un workspace, quello) e
`Api::workspace()` (il gettone del workspace) danno `get`, `post`, `patch`, `delete` e `tutti`: tornano il JSON della
risposta (`[]` per un 204). `tutti($percorso)` scorre le pagine di una lista a cursore (`successivo`), fino a 100.

- Ogni chiamata porta `Authorization: Bearer <gettone>`, `Accept: application/json` e `Accept-Language` = la lingua
  dell'app: senza gettone i testi degli errori arrivano in quella lingua. Timeout 5 secondi, connessione 2.
- `condizionale($percorso, $versione = null, $query = [])` è la GET del polling: manda `If-None-Match` con la `versione`
  (l'`etag` della risposta precedente, com'è) e torna `['stato' => 200|304, 'etag' => ?string, 'corpo' => ?array]`.
  Un 304 non è un errore: `corpo` è `null` e quello che hai è ancora valido. Gli errori sono quelli di `get()`; una
  `versione` che non è un entity-tag (`"abc"`, `W/"abc"`) lancia `InvalidArgumentException` senza chiamare.

  ```php
  // zr-board: il polling di una board. Il Redis di Zeiras è uno solo e senza evizione: poco, e sempre con un TTL
  $ultimo = Cache::get("board.$id");                       // ['etag' => …, 'corpo' => …] o null
  $r = Api::workspace()->condizionale("/v1/board/board/$id", $ultimo['etag'] ?? null);

  if ($r['stato'] === 200) {
      Cache::put("board.$id", $r, 300);                    // con un TTL, sempre
      $ultimo = $r;
  }

  return $ultimo['corpo'];                                 // 304: si serve ciò che si aveva
  ```

- **L'IP vero della persona (client registrato).** Le rotte senza gettone (`accessi.crea`, `utenti.crea`, le verifiche
  dell'email, il recupero della password, lo scambio dell'ingresso e i tre metodi dei provider) hanno i loro freni per IP: se il
  frontend non dice chi chiama, il backoffice vede il suo server (`127.0.0.1`) e tutte le persone di Zeiras dividono lo stesso
  secchio. Per dirlo, il frontend ha un nome e un segreto: `ZR_AUTH_CLIENTE` (`home`, `board`) e `ZR_BACKOFFICE_SEGRETO`, uno
  per frontend, che chi gestisce il server genera e che il backoffice ha uguale in `ZR_CLIENTE_<NOME>_SEGRETO`. Con i due, ogni
  chiamata senza gettone porta quattro header firmati (`Zr-Cliente`, `Zr-Ip`, `Zr-Istante`, `Zr-Firma`: l'HMAC-SHA256 di `zr1`,
  client, istante, metodo, percorso e IP, valida 30 secondi); senza uno dei due, o da un comando artisan o da un job in coda (Laravel lega lì una richiesta finta, `127.0.0.1`), non parte niente
  e la richiesta conta fra le anonime. Una firma che non torna è `401` `cliente_non_riconosciuto`, e il client lo lancia come `ErroreApi`
  (finisce nel log, senza il segreto), non come `GettoneRifiutato`: un frontend configurato male si vede, non rimanda in silenzio all'ingresso.
  ⚠️ L'IP firmato è `request()->ip()` di Laravel: giusto solo se il frontend si fida dei soli proxy che lo precedono
  (Cloudflare, con `trustProxies` sui suoi indirizzi o `real_ip_header CF-Connecting-IP` in nginx). Con `trustProxies('*')` l'IP è
  quello che il browser scrive in `X-Forwarded-For`, e chi attacca sceglie il suo secchio; senza nessuna fiducia è l'IP del proxy,
  e molte persone dividono un secchio. Nei test, il finto riconosce il client `BackofficeFinto::CLIENTE` col segreto
  `BackofficeFinto::SEGRETO_DEL_CLIENTE` (`ZR_AUTH_CLIENTE=finto`, `ZR_BACKOFFICE_SEGRETO=<quello>`), e `ipVisti()` dice gli IP
  che il client ha dichiarato con una firma valida, per provare che passa quello della persona.
- Il percorso è sempre di `/v1` (`'/v1/io'`): un indirizzo intero non parte, perché il gettone va solo al backoffice.
- Un errore di `/v1` (RFC 9457, `application/problem+json`) diventa `ErroreApi`: si decide su `$e->codice`, e
  `$e->dettaglio` si mostra.
- Un **401** (gettone scaduto o revocato) diventa `GettoneRifiutato`: zr-auth chiude la sessione e rimanda all'ingresso,
  senza ripetere la chiamata.
- Un backoffice che non risponde, un trasporto che cade (anche dopo lo stato), un 3xx, un 5xx o una risposta senza JSON
  diventano `BackofficeNonRisponde`: mai una lista vuota, e mai un'eccezione del client HTTP che esce. Quando il client
  HTTP ha lanciato, la sua eccezione sta in `getPrevious()`: una `ConnectionException` se il trasporto è caduto prima
  dello stato o dopo un 2xx o un 3xx; dopo un 4xx o un 5xx, la `RequestException` che Laravel fa dalla risposta
  troncata, che porta quello stato e non il guasto. Un 3xx, un 5xx o una risposta senza JSON arrivati per intero non
  hanno un `getPrevious()`.
- Un redirect non si segue: un 307 rimanderebbe il corpo, una password compresa, al `Location`.

## L'ingresso da zr-home: la partenza e il ricevitore

Un modulo non ha una pagina di accesso sua: la persona entra da zr-home e torna con un codice monouso. zr-auth fa le due
metà del modulo. Il protocollo (codice con PKCE, S256) lo fanno `ingressi.crea` (zr-home) e `ingressi.scambio.crea` (il
ricevitore); il modulo non lo scrive.

```php
// La partenza: una pagina del modulo (pubblica o no) che manda la persona a entrare nel workspace, per slug.
Route::get('entra/{workspace}', fn (string $workspace) => Ingresso::verso($workspace))->withoutMiddleware(ConGettone::class);
```

`Ingresso::verso($slug)` risponde 302 a `ZR_HOME_URL/ingresso?app=<ZR_APP>&workspace=<slug>&state=<state>&sfida=<sfida>`.
`state` (43 caratteri) e verificatore (64, `[A-Za-z0-9._~-]`) nascono lì e stanno **solo** nella sessione del modulo;
`sfida` è `base64url(SHA-256(verificatore))`, 43 caratteri. Il verificatore non esce mai: né nell'indirizzo, né in un
header, né in un log. La risposta ha `Cache-Control: no-store` e `Referrer-Policy: no-referrer`. Una partenza nuova prende
il posto della precedente.

Il ricevitore è la rotta `GET /ingresso/ritorno` (config `zr-auth.ricevitore`), che zr-auth registra da sé, **senza la
guardia**: è il valore che chi gestisce il backoffice mette in `ZR_RITORNO_<CODICE>` (`https://<modulo>/ingresso/ritorno`).
Riceve `codice` (43 caratteri `[A-Za-z0-9_-]`) e `state` (al più 512, `[A-Za-z0-9._~-]`):

- è un GET e basta (gli altri metodi sono 405) e solo dallo stesso sito: un `Sec-Fetch-Site` diverso da `same-site`,
  `same-origin` o `none`, o un `Origin` che non è né il modulo né zr-home, non apre la sessione né tocca la partenza della
  persona;
- lo `state` deve essere quello della partenza (`hash_equals`) e vale una volta; `state` e verificatore escono dalla
  sessione a ogni ritorno che li tocca, anche se lo scambio fallisce;
- **un ritorno rifiutato brucia il codice.** Per ogni motivo del rifiuto (altro sito, `state` assente, diverso o fuori forma,
  nessuna partenza in sessione) il ricevitore, se il `codice` ha la forma giusta, fa uno scambio a vuoto
  (`ingressi.scambio.crea`) con un verificatore casuale di 64 caratteri: il backoffice lo tratta come «verificatore
  sbagliato» e consuma il codice, così chi ha mandato la persona qui con una sua sfida (e ha il suo verificatore) non
  può scambiarlo se l'indirizzo trapela, nei 60 secondi in cui varrebbe. Un `codice` che non ha la forma non parte nemmeno
  verso il backoffice; un 429, un 5xx o il trasporto che cade nello scambio a vuoto non cambiano la risposta alla persona
  (la stessa pagina d'errore, gli stessi header) e non sono mai `BackofficeNonRisponde`;
- lo scambio (`ingressi.scambio.crea`) dà il gettone del workspace: la sessione si apre con `Sessione::entra()` e la
  persona torna a `Sessione::ritorno(url(config('zr-auth.dopo')))`: la pagina che la guardia ricordava, se è del modulo,
  senza `codice` né `state`; mai un indirizzo che viene dalla richiesta;
- ogni ritorno che non vale (stato diverso, codice rifiutato `verifica_non_riuscita`, freno `429`, forma sbagliata) va alla
  **stessa** pagina d'errore (`ZR_AUTH_ERRORE`, senza: la pagina d'accesso), che a chi arriva da fuori non dice il perché;
- un guasto del backoffice (5xx, trasporto che cade, risposta senza la forma di un gettone) è `BackofficeNonRisponde`:
  mai una sessione a metà.

Il ricevitore non ha la guardia, e il test del frontend lo nomina fra le rotte pubbliche:

```php
expect(Rotte::senzaGuardia(['GET ingresso/ritorno']))->toBe([]);
```

Un frontend che l'ingresso lo fa altrove (zr-home, che lo *dà* ai moduli e non lo riceve) mette `'ricevitore' => null` nella
config pubblicata: zr-auth non registra nessuna rotta, `Rotte::senzaGuardia()` non nomina `GET ingresso/ritorno`, e il
pacchetto si avvia anche senza `ZR_APP` (che serve solo a `Ingresso::verso()`).

Nei test del modulo il giro intero si fa col finto, senza un `Http::fake` per lo scambio:
`BackofficeFinto::attiva()->ritorno('pm', 'https://<modulo>/ingresso/ritorno')`, un workspace con `attivaApp($workspace, 'pm')`,
`Ingresso::verso()`, poi `ingressi.crea` del finto con la `sfida` della partenza (è ciò che fa zr-home) e un GET al `ritorno`
che dà, con `codice` e `state`. Un esempio per intero: `tests/Feature/IngressoTest.php` di questo repo.

## La guardia, e il suo test

`ConGettone` entra da sé nei gruppi `web` e `api`: senza una sessione col gettone, una pagina rimanda all'ingresso; una
visita di Inertia riceve 409 con `X-Inertia-Location`; una richiesta JSON 401. Solo un GET ricorda la pagina chiesta
(`url.intended`, l'indirizzo della richiesta): ogni altro metodo, HEAD compreso, non ricorda niente, e mai il `Referer`.

Dopo l'accesso si torna lì con `Sessione::ritorno()`, **al posto di `redirect()->intended()`**, che manderebbe la persona
a qualunque indirizzo ci sia in `url.intended`, anche di un altro sito:

```php
// Dopo Sessione::apri() (ed entra()): la pagina ricordata, se ha lo schema, l'host e la porta di questa richiesta; se no
// (un altro host, «//altro.host/», http:// al posto di https://, nessun ritorno) la pagina predefinita del modulo.
// Il ritorno esce dalla sessione: vale una volta.
return redirect(Sessione::ritorno(route('bacheca')));
```

Una pagina pubblica (l'accesso, la registrazione) se la toglie, e il test del frontend la nomina:

```php
Route::get('accedi', …)->withoutMiddleware(ConGettone::class);

// tests/Feature/RotteTest.php del frontend (spec S01, prova 11)
expect(Rotte::senzaGuardia(['GET accedi', 'POST accedi']))->toBe([]);
```

`GET up` è già un'eccezione. E per la prova 8: `Zeiras\Auth\Testing\Gettone::assenteDa($this->get('/dashboard'))`.

## Gli eventi del backoffice: il ricevitore

Il backoffice consegna ogni evento di un workspace al modulo che ha l'app attiva: un `POST` firmato con Standard Webhooks,
su un indirizzo che chi gestisce il backoffice ha in configurazione. zr-auth ne è il ricevitore: verifica la firma, scarta
i doppioni e dà l'evento al modulo come evento di Laravel. **È opt-in**: senza `zr-auth.eventi.percorso` il pacchetto non
registra nessuna rotta.

```php
// config/zr-auth.php del modulo
'eventi' => [
    'percorso' => '/webhook/backoffice',   // POST; senza, nessuna rotta
    'segreto' => env('ZR_EVENTI_SEGRETO'), // whsec_ e da 24 a 64 byte in base64: lo stesso dell'.env del backoffice
    'tolleranza' => 300,                   // secondi di scarto fra l'evento e l'ora del modulo, nei due versi
    'doppioni' => 300,                     // quanto si ricorda un webhook-id già visto
],
```

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Zeiras\Auth\Eventi\EventoDelBackoffice;

final class RiceviEvento implements ShouldQueue
{
    public function handle(EventoDelBackoffice $evento): void
    {
        // $evento->id, ->type, ->subject, ->sequence (la stringa di 12 cifre), ->data (array), ->time, ->corpo (tutto il JSON)
    }
}
```

La rotta si chiama `zr-auth.eventi`, risponde solo a `POST`, sta nel gruppo `api` (nessun CSRF, nessuna sessione) e **non ha
la guardia del gettone**: chi chiama è il backoffice, e la firma fa da guardia. Il test del frontend la nomina fra le
pubbliche: `Rotte::senzaGuardia(['POST webhook/backoffice'])`.

**Cosa fa il ricevitore.** Tutto sul corpo come è arrivato, mai ricodificato: la firma è l'HMAC-SHA256 di
`webhook-id.webhook-timestamp.corpo`, confrontata a tempo costante con ogni firma `v1,…` dell'header; un'altra versione non
conta. Un istante a più di `tolleranza` secondi, nei due versi, non vale. Ogni rifiuto per la firma, l'istante o gli header
mancanti è lo stesso `401` (`{"status":401}` come `application/problem+json`, senza un motivo). Un corpo firmato bene che
non è un evento (un oggetto con `id` e `type` stringhe e `data` oggetto) è un `400` e non brucia l'id. Un evento verificato
è un `204` senza corpo.

**I doveri di chi riceve.**

- **La firma prima di tutto.** Niente si fa su un evento che non l'ha: lo fa il ricevitore, e il modulo non legge mai il corpo
  da un'altra strada.
- **Il `204` subito, il lavoro dopo.** La rotta risponde quando gli ascoltatori sincroni hanno finito, e il backoffice aspetta
  poco: l'ascoltatore va in coda (`ShouldQueue`) e fa il lavoro lì. Se un ascoltatore sincrono lancia, la rotta risponde
  `5xx`, il pacchetto toglie il marcatore del doppione e il backoffice riprova: l'evento non si perde per un guasto del modulo.
- **I doppioni.** Lo stesso `webhook-id` entro `doppioni` secondi è un `204` senza passare l'evento di nuovo (`Cache::add`
  sulla cache del modulo, con un tempo: mai una chiave senza). Il tempo non è mai meno del doppio di `tolleranza`, perché
  una firma vale fino a `tolleranza` secondi dopo un istante che può stare `tolleranza` secondi nel futuro. La cache del
  modulo dev'essere una vera (Redis, database): con `array` o `null` i doppioni non si ricordano. Oltre quel tempo, o con un
  altro `webhook-id`, l'ascoltatore deve reggere un evento già trattato: lo riconosce da `sequence`.
- **L'ordine e i buchi.** Gli eventi di un workspace hanno un `sequence` che cresce, ma arrivano in qualunque ordine e
  qualcuno può mancare. Il modulo tiene l'ultimo `sequence` trattato per workspace, non applica un evento più vecchio e, se
  vede un buco, rilegge.
- **Il recupero.** Si rilegge col gettone del workspace: `GET /v1/eventi?dopo=<ultimo sequence trattato>` (`eventi.elenca`),
  a pagine. Se risponde `410` `cursore_scaduto` (l'evento dopo è più vecchio di 30 giorni, o `dopo` sta oltre l'ultimo), il
  modulo rilegge tutto ciò che gli serve dal suo stato e riparte da `eventi.ultimo.mostra`.
- **Un tipo mai visto, un id sconosciuto.** Un `type` nuovo arriva invariato, e un `eliminata` di un id che il modulo non ha
  non è un errore: il pacchetto non guarda mai se l'id esiste, e l'ascoltatore lo ignora.
- **Nei log, niente.** Il pacchetto non scrive mai nei log il corpo, il segreto o l'header della firma, e l'ascoltatore del
  modulo nemmeno: l'evento dice quale risorsa è cambiata, e i dati personali si rileggono col gettone.

**Come si prova.** `BackofficeFinto::consegna($tipo, $subject, $data = [], $timestamp = null)` fa la consegna come il
backoffice: dà `intestazioni` (`webhook-id`, `webhook-timestamp`, `webhook-signature`) e `corpo`, firmati col segreto di
`zr-auth.eventi.segreto`. Il test la manda alla rotta e prova il suo ascoltatore (vedi «Il backoffice finto»); senza un segreto
in configurazione (`ZR_EVENTI_SEGRETO`) il metodo lancia una `LogicException`.

## Il backoffice finto, per i test

`Zeiras\Auth\Testing\BackofficeFinto` risponde alle chiamate di `/v1` come il backoffice, senza rete e senza database: le
forme del contratto, i codici d'errore del catalogo, i testi del backoffice (it, en, es: le copie di `resources/lang/`) e
i suoi freni. Con lui un frontend prova nella sua CI la registrazione, l'ingresso, l'uscita, la verifica dell'email, il
recupero della password e l'ingresso nei moduli.

```php
use Zeiras\Auth\Testing\BackofficeFinto;

$finto = BackofficeFinto::attiva();
$password = 'una password lunga e sicura';
$anna = $finto->persona('anna@example.com', $password);                     // la persona, come la dà il backoffice
$studio = $finto->workspace('Studio Anna', $anna);                          // {id, nome, slug}: Anna è la proprietaria
$finto->membro($studio, $finto->persona('bruno@example.com', '…', nome: 'Bruno'), 'membro');

$this->post('/accedi', ['email' => 'anna@example.com', 'password' => $password]);
```

- `persona($email, $password, $nome = 'Anna', $lingua = 'it', $verificata = true)`. Con `verificata: false` l'email è da
  verificare, e alla persona parte il primo codice, come alla registrazione. `ultimoCodice($email)` fa da casella di
  posta: l'ultimo codice partito per quell'email, `null` se nessuno; un codice nuovo è sempre diverso da quello prima.
- Fa `utenti.crea`, `accessi.crea`, `accessi.corrente.elimina`, `accessi.elimina`, `accessi.provider.elenca`,
  `accessi.provider.autorizzazioni.crea`, `accessi.provider.crea`, `gettoni.crea`, `io.email.codice.crea`, `io.email.verifica.crea`,
  `io.notifiche.lettura.modifica`, `io.notifiche.letture.crea`, `password.recupero.crea`, `password.reimpostazione.crea`, `ingressi.crea`, `ingressi.scambio.crea`, `app.modifica`,
  `io.modifica`, `io.password.modifica`, `workspace.modifica`, `workspace.membri.modifica`, `workspace.membri.elimina`,
  `workspace.inviti.crea`, `workspace.inviti.elimina` e `inviti.accettazione.crea`, e le letture: `io.mostra`,
  `io.workspace.elenca`, `io.aziende.elenca`, `io.notifiche.elenca`, `lingue.elenca`, `app.elenca`, `workspace.membri.elenca` e `workspace.inviti.elenca`. Fa anche
  `io.workspace.crea` (vedi «Il workspace»). Una chiamata
  di `/v1` che non conosce lancia `RichiestaSconosciuta`: il finto non inventa una risposta che il backoffice non darebbe.
- **I provider.** Spenti di norma, come in produzione senza credenziali: `provider('google', 'linkedin-openid', 'facebook')`
  ne accende. Ciò che il provider risponde al `codice` del ritorno lo dice il test:
  `identitaDelProvider('google', 'un-codice', 'anna@example.com', nome: 'Anna Rossi')` (con `verificata: false` l'email non
  è garantita e l'accesso non riesce con `422` `email_del_provider_non_verificata`, uguale con e senza un account; un codice che il test non ha detto lo rifiuta il provider, `422`);
  `guastaProvider('google')` lo fa non rispondere (`503`). La partenza dà un indirizzo con `client_id=finto-<slug>`; il
  resto dell'indirizzo, lo `stato` di 10 minuti usa-e-getta e la sfida PKCE sono quelli del backoffice. Una persona nuova
  vuole `termini_accettati`; con Google e LinkedIn nasce anche a registrazione chiusa (dal backoffice del 10/10, #1554), con
  Facebook solo se la registrazione la ammette (`consenti()`).
- **Le schede.** Il finto non modella board né liste: `scheda($workspace, $titolo, $board)` fa nascere una scheda con il minimo che
  le attese guardano, e `segnaScheda($scheda, 'completata'|'archiviata')` la completa o la archivia. Con quelle rispondono
  `board.schede.mostra`, `board.schede.completamento.crea`, `board.schede.completamento.elimina`,
  `board.schede.collegamenti.elenca`, `board.schede.collegamenti.crea` e `board.schede.collegamenti.elimina` (col gettone di un
  workspace con l'app `pm`). `collegamenti.elimina` toglie l'attesa anche verso una scheda archiviata; è `409` `scheda_archiviata` solo
  se è archiviata la scheda del percorso.
- **Il widget di `accessi.crea`.** Come nel backoffice (`ZR_ACCESSI_TURNSTILE`, spento di default) il sesto tentativo di un'email in un
  minuto è `429` `troppe_richieste`. `accendiGradinoAccessi()` accende il gradino: dal sesto al trentesimo tentativo serve `turnstile`
  (con `accendiTurnstile()`; senza è `422` `turnstile_non_valido`) e il `429` viene oltre il trentesimo. La coppia (email, IP firmato)
  è `429` al sesto in ogni caso.
- **Le notifiche.** `io.notifiche.elenca`, `io.notifiche.lettura.modifica` e `io.notifiche.letture.crea` rispondono come il backoffice,
  col gettone di un workspace (quello dell'accesso è `403` `gettone_senza_workspace`), e `notifiche_non_lette` di `io.mostra` conta le
  non lette della persona in quel workspace. Il finto non ha gli eventi che le generano: le semina il test con
  `notifica($workspace, $persona, $tipo, creataIl: …, lettaIl: …)`, che dà la notifica come la dà l'elenco. L'app la dà il tipo
  (`pm` per `com.zeiras.board.*`, altrimenti `null`): un'altra è un errore del test. La lettura in blocco segna al più 5000 per chiamata.
- **Le aziende.** `io.aziende.elenca` dà le aziende dei workspace di cui la persona è membro (con ogni suo gettone, anche
  quello dell'accesso), una volta sola ciascuna, in ordine di nome e poi di id, a pagine col solo id. Un'azienda nasce con
  il workspace e prende il suo nome di allora (`workspace()` e `io.workspace.crea` senza `azienda_id`): una rinomina del
  workspace non la cambia, come nel backoffice. Il finto non ha un metodo per cambiare il nome di un'azienda.
- **La persona.** `lingue.elenca` dà le lingue di Zeiras (`it`, `en`, `es`) col nome scritto in ognuna, a ogni gettone.
  `io.modifica` cambia nome, lingua e fuso orario sotto `utente` (JSON Merge Patch) con ogni gettone della persona, anche quello
  dell'accesso, di chi non ha ancora un workspace (nella risposta `workspace`, `ruolo` e `notifiche_non_lette` sono `null`): un campo sbagliato o di un'altra
  risposta è `422` sul suo pointer e non ne lascia salvato nessuno; la lingua nuova vale dalla chiamata dopo.
  `io.password.modifica` è `204`: vale la password nuova, e i gettoni degli altri accessi della persona non valgono più
  (`401`), quelli dell'accesso che chiama sì; la password attuale sbagliata è `422` su `#/password_attuale`, e dopo cinque
  errori in un'ora `429`.
- **Il workspace.** `workspace.modifica` (`{"nome"}`, 1-255 caratteri senza spazi ai bordi) è del proprietario e
  dell'amministratore, `403` `permesso_negato` a un membro prima del corpo; lo slug non cambia, e la risposta è un elemento
  di `io.workspace.elenca`. `workspace.membri.modifica` (`{"ruolo"}`, `amministratore` o `membro`) e
  `workspace.membri.elimina` rispondono nell'ordine del backoffice: il ruolo di chi chiama `403`, il membro `404`, il corpo
  `422`, il proprietario `409` `proprietario_intoccabile`, e un amministratore che tocca un amministratore, o ne fa uno, `403`.
  Togliere un membro toglie i suoi gettoni **di quel workspace** (`401`, anche se rientra); quello dell'accesso e gli altri
  workspace restano.
- **La nascita di un workspace.** `io.workspace.crea` (`{"nome"}`, 1-255 caratteri; `azienda_id` facoltativo) vale con
  ogni gettone della persona, anche quello dell'accesso, e risponde `201` con il workspace e il ruolo `proprietario` (senza
  `Location`: il contratto ha il solo `Link`). Il workspace compare in `io.workspace.elenca`, `gettoni.crea` ne dà il
  gettone, e `app.elenca` non ha app `attivo`. Un `nome` sbagliato è `422` su `#/nome` e non conta nel freno; un campo in
  più nel corpo si ignora; un'`azienda_id` che non è della persona è `404`; dieci workspace all'ora per persona, poi `429`
  con `Retry-After`. Con `Idempotency-Key` la stessa chiave dà la stessa risposta senza un secondo workspace, con qualunque
  gettone della persona.
- **Gli inviti.** `workspace.inviti.crea` (`{"email", "ruolo"}`) è `201` con l'invito (`id`, `email`, `ruolo`, `scade_il`,
  `creato_il`) e la `Location`, uguale per un'email con un account e per una senza, e **mai con il codice**: il codice è
  nella mail, e il test lo legge da `ultimoInvito($email)`, la casella di posta degli inviti (l'ultimo partito, `null` se
  nessuno; non è il codice di `ultimoCodice()`). Un amministratore invita solo `membro` (`403`), il proprietario non si
  invita (`422` su `#/ruolo`), chi è già membro è `409` `gia_membro`, un invito vivo per la stessa email `409`
  `invito_esistente` (uno scaduto si rifà), 50 tra membri e inviti vivi `409` `limite_raggiunto` (e un invito accettato in un workspace con 50 membri, lo stesso); oltre 5 inviti in un'ora verso la
  stessa email, o 50 dal workspace, `429` con `Retry-After`. Un invito vale 7 giorni. Con `Idempotency-Key` la stessa chiave
  e lo stesso corpo danno la stessa risposta per 24 ore, senza un secondo invito (con un altro corpo `422`
  `chiave_idempotenza_riusata`). `workspace.inviti.elenca` dà i vivi dal più recente, a cursore; `workspace.inviti.elimina`
  è `204` e poi `404`, e il codice non vale più (l'invito a un amministratore lo revoca il proprietario).
  `inviti.accettazione.crea` (`{"codice"}`, col gettone dell'accesso: `403` `gettone_con_workspace` a uno di un workspace)
  è `201` nella forma di un membro se l'email dell'invito è la persona del gettone ed è verificata; ogni altro caso è la
  stessa `422` `verifica_non_riuscita`, e chi è già membro `409`. `utenti.crea` accetta `invito`: con un invito vivo per
  quell'email la registrazione si apre anche se è chiusa e senza Turnstile, la persona nasce con l'email verificata (nessun
  codice in `ultimoCodice()`) ed entra nel workspace; un invito che non vale è la stessa `422`. Per un'email che ha già un
  account l'invito non si accetta: lo accetta la persona, con `inviti.accettazione.crea`.
- **La password.** `password.recupero.crea` è `202` con la sola email, uguale per un'email con un account e per una senza;
  il codice di 6 cifre parte solo a un account, e si legge da `ultimoCodice($email)` (l'ultimo partito, di verifica o di
  recupero). Un codice di recupero vale 10 minuti e 5 tentativi, e non è il codice di verifica dell'email: l'uno non fa
  l'altro. `password.reimpostazione.crea` è `204`: la password nuova vale, la vecchia no, e ogni accesso della persona si
  chiude coi suoi gettoni (`401` `gettone_non_valido`); ogni altro esito è la stessa `422` `verifica_non_riuscita`. Una
  password che non va è `422` su `#/password` e non consuma il codice. Turnstile, acceso o guasto, vale per il recupero come
  per `utenti.crea`; il freno è di 5 richieste al minuto per email in tutti e due i metodi.
- **Le app.** Un'app `disponibile` del catalogo (oggi `pm`) è `attivo` in un workspace dopo `app.modifica`
  (`{"stato": "attivo"}`, del proprietario o dell'amministratore) o, nel test, dopo `attivaApp($workspace, 'pm')`;
  `app.elenca` lo dice. Un membro è `403` `permesso_negato`, un'app `in_arrivo` `409` `app_in_arrivo`.
- **L'ingresso nei moduli.** `ritorno($app, $indirizzo)` dice dove `pm` riceve il codice (nel backoffice
  `ZR_RITORNO_PM`); senza, `ingressi.crea` è `503` `servizio_non_disponibile`, e con `''` si toglie. `ingressi.crea`
  (`{"app", "sfida"}`, gettone di un workspace, app attiva: `403` `app_non_attiva` altrimenti) dà `{codice, ritorno,
  scade_il}` con il ritorno detto dal test e mai uno del corpo (un campo `ritorno` è `422`). `ingressi.scambio.crea`
  (`{"codice", "verificatore"}`, senza gettone) dà il gettone del workspace: il codice vale una volta e 60 secondi, la
  `sfida` è `base64url(SHA-256(verificatore))`, un verificatore sbagliato consuma il codice, ogni fallimento è la stessa
  `422` `verifica_non_riuscita`, e il freno è di 5 richieste al minuto per codice.
- **Le consegne.** `consegna($tipo, $subject, $data = [], $timestamp = null)` fa la consegna di un evento a un modulo, come
  la fa il backoffice: `['intestazioni' => [...], 'corpo' => '...']`, con i tre header di Standard Webhooks e il corpo nella
  forma di un evento del backoffice. L'`id` è un ULID nuovo a ogni chiamata, `sequence` cresce di uno a 12 cifre, e la firma
  è quella del ricevitore col segreto di `zr-auth.eventi.segreto`. Vedi «Gli eventi del backoffice: il ricevitore».
- La registrazione è chiusa come nel backoffice: ogni `utenti.crea` è `403` `registrazione_non_aperta`, finché il test
  non dà la lista dei consentiti con `consenti('bruno@altro.it', '@example.com')` (un'email intera o un dominio, per
  uguaglianza) o la apre a tutti con `apri()`, che vale solo con Turnstile acceso, come il backoffice che si apre solo
  col segreto. Una registrazione riuscita è `202` con l'email, la stessa risposta per un'email che ha già un account,
  che non cambia; la persona nuova nasce con l'email da verificare, e il suo primo codice è in `ultimoCodice()`. Al
  posto di Have I Been Pwned, il finto dà per trapelata una password sola, `BackofficeFinto::PASSWORD_TRAPELATA`: `422`
  `dati_non_validi` su `#/password`.
- Turnstile è spento, come nel backoffice senza il segreto: la risposta non si controlla, ma si valida come la dichiara
  il contratto, e una che non è una stringa o supera 2048 caratteri è `422` `dati_non_validi` su `#/turnstile`.
  `accendiTurnstile()` lo accende: una registrazione vuole `turnstile` uguale a `BackofficeFinto::TURNSTILE_VALIDO`,
  `XXXX.DUMMY.TOKEN.XXXX` (la risposta che danno i tasti di prova di Cloudflare), e senza o con un altro valore è `422`
  `turnstile_non_valido`, prima della lista. `guastaTurnstile()` fa il Cloudflare che non risponde: una risposta ben
  formata è `503` `turnstile_non_disponibile`. La registrazione aperta come in produzione è `apri()` con
  `accendiTurnstile()`.
- Via `Api` il `503` `turnstile_non_disponibile` arriva come `BackofficeNonRisponde`, come ogni 5xx: la pagina non lo
  distingue da un backoffice che non risponde, e chiede alla persona di rifare il controllo e riprovare fra poco.
- In produzione una risposta del widget vale una volta: Cloudflare respinge la seconda (`422` `turnstile_non_valido`), e
  dopo ogni invio, riuscito o no, la pagina rifà il controllo. Il finto, come i tasti di prova di Cloudflare, accetta
  `TURNSTILE_VALIDO` ogni volta: una risposta usata due volte, nei test, passa.
- `workspace($nome, $proprietaria)` dà il workspace con lo slug del backoffice: il nome in slug, al più 40 caratteri, poi
  un trattino e sei caratteri casuali (`studio-anna-k3x9q2`). `membro($workspace, $persona, $ruolo)` mette una persona in
  un workspace, o le cambia il ruolo: `io.mostra` lo rilegge a ogni chiamata.
- Le liste sono quelle del backoffice: in ordine di nome (maiuscole e accenti non contano) e poi di `id`, le app in
  ordine di codice (`pm` disponibile o attiva, le altre `in_arrivo`); a pagine con `limite` (da 1 a 100, 50 se manca) e `cursore`, il `successivo`
  della pagina prima, firmato per quella lista. `app.elenca` e `workspace.membri.elenca` vogliono il gettone di un
  workspace: al gettone dell'accesso rispondono `403` `gettone_senza_workspace`.
- Acceso il finto, alle API risponde solo lui: niente altri `Http::fake` che rispondano a `api.zeiras.com`, né un
  `Http::fake()` senza indirizzo, che risponderebbe a tutto. Le chiamate verso altri indirizzi restano agli altri fake.
- Il tempo è `now()`, e un test lo sposta con `travel()`: i gettoni valgono 12 ore dall'accesso, un codice 10 minuti, un
  freno fino alla fine della sua finestra.
- I freni sono quelli del backoffice: 5 richieste al minuto per email in `utenti.crea`, `accessi.crea` (un accesso
  riuscito azzera il conto), `io.email.codice.crea` e `io.email.verifica.crea`, poi `429` con `Retry-After`; fra un codice e l'altro 60
  secondi, al più 5 codici in un'ora e 10 in un giorno; un codice vale 5 tentativi, e una persona ha 10 codici sbagliati
  al giorno; `gettoni.crea` dà al più 60 gettoni in un'ora a una persona, e un gettone fa al più 600 chiamate al minuto.
- Il finto non fa i tetti di Zeiras che una CI di frontend non incontra: 30 codici all'ora per persona in `ingressi.crea`, 50
  gettoni vivi per persona in `gettoni.crea` e `ingressi.scambio.crea`, il tetto di memoria delle `Idempotency-Key`.
- Che risponda come il contratto lo prova la CI del backoffice: ogni sua risposta passa la validazione del contratto vero,
  e le copie dei testi sono uguali byte per byte a quelle del backoffice.
