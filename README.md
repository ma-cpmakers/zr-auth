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
"require": {"zeiras/zr-auth": "^0.4"}
```

Un minore esce quando il backoffice ha i suoi metodi. La 0.3 porta le letture (`io.mostra`, `io.workspace.elenca`,
`app.elenca`, `workspace.membri.elenca`) e lo `slug` del workspace: il backoffice le ha da quando le loro righe sono in
`https://docs.zeiras.com/v1/novita`, che esce col deploy. Con la 0.2 il finto non le conosce, e lancia
`RichiestaSconosciuta`. La 0.4 porta la registrazione (`utenti.crea`) con Turnstile, e i testi dei suoi due codici
nuovi, `turnstile_non_valido` e `turnstile_non_disponibile`.

| Variabile | Default | Cosa |
|---|---|---|
| `ZR_API_URL` | `https://api.zeiras.com` | le API del backoffice; solo `https://` (sulla porta 80 il server risponde 301, e un 301 trasforma un POST in GET) |
| `ZR_AUTH_INGRESSO` | `https://app.zeiras.com/accedi` | la pagina d'accesso, dove la guardia rimanda chi non ha una sessione |

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

// L'uscita: accessi.elimina chiude l'accesso e ogni gettone che ne discende. La sessione si chiude comunque, anche se
// il backoffice non risponde; una sessione aperta solo col gettone di un workspace non ha l'id dell'accesso.
try {
    if (Sessione::accesso() !== null) {
        Api::persona()->delete('/v1/accessi/'.Sessione::accesso());
    }
} finally {
    Sessione::chiudi();
}
```

Una pagina che vuole il workspace guarda prima `Sessione::workspace()`: senza, la persona è entrata ma non ha ancora
scelto un workspace, e `Api::workspace()` lancia `LogicException`.

Il gettone non esce mai dalla sessione: nessun metodo lo restituisce. Non va nell'HTML, nelle props di Inertia, né in
un cookie (spec S01, prova 8).

## Il client delle API

`Api::senzaGettone()`, `Api::persona()` (il gettone dell'accesso; se il frontend ha solo quello di un workspace, quello) e
`Api::workspace()` (il gettone del workspace) danno `get`, `post`, `patch`, `delete` e `tutti`: tornano il JSON della
risposta (`[]` per un 204). `tutti($percorso)` scorre le pagine di una lista a cursore (`successivo`), fino a 100.

- Ogni chiamata porta `Authorization: Bearer <gettone>`, `Accept: application/json` e `Accept-Language` = la lingua
  dell'app: senza gettone i testi degli errori arrivano in quella lingua. Timeout 5 secondi, connessione 2.
- Il percorso è sempre di `/v1` (`'/v1/io'`): un indirizzo intero non parte, perché il gettone va solo al backoffice.
- Un errore di `/v1` (RFC 9457, `application/problem+json`) diventa `ErroreApi`: si decide su `$e->codice`, e
  `$e->dettaglio` si mostra.
- Un **401** (gettone scaduto o revocato) diventa `GettoneRifiutato`: zr-auth chiude la sessione e rimanda all'ingresso,
  senza ripetere la chiamata.
- Un backoffice che non risponde, un trasporto che cade (anche dopo lo stato: con Guzzle 8 è una `RequestException`), un
  3xx, un 5xx o una risposta senza JSON diventano `BackofficeNonRisponde`, col guasto del trasporto in `getPrevious()`: mai
  una lista vuota, e mai un'eccezione del client HTTP che esce.
- Un redirect non si segue: un 307 rimanderebbe il corpo, una password compresa, al `Location`.

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

## Il backoffice finto, per i test

`Zeiras\Auth\Testing\BackofficeFinto` risponde alle chiamate di `/v1` come il backoffice, senza rete e senza database: le
forme del contratto, i codici d'errore del catalogo, i testi del backoffice (it, en, es: le copie di `resources/lang/`) e
i suoi freni. Con lui un frontend prova nella sua CI la registrazione, l'ingresso, l'uscita e la verifica dell'email.

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
- Fa `utenti.crea`, `accessi.crea`, `accessi.elimina`, `gettoni.crea`, `io.email.codice.crea` e
  `io.email.verifica.crea`, e le letture: `io.mostra`, `io.workspace.elenca`, `app.elenca` e `workspace.membri.elenca`. Una chiamata di `/v1` che non
  conosce lancia `RichiestaSconosciuta`: il finto non inventa una risposta che il backoffice non darebbe.
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
  ordine di codice, tutte `in_arrivo`; a pagine con `limite` (da 1 a 100, 50 se manca) e `cursore`, il `successivo`
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
- Che risponda come il contratto lo prova la CI del backoffice: ogni sua risposta passa la validazione del contratto vero,
  e le copie dei testi sono uguali byte per byte a quelle del backoffice.
