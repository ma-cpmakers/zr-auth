# zr-auth

Il pacchetto dei frontend di Zeiras (`zr-home`, `zr-board`, …): la sessione col **gettone** del backoffice, il client
delle API `/v1` di `zr-backoffice` (`https://api.zeiras.com`), la guardia sulle rotte e il suo test, e un backoffice
finto per i test. **Nessun database**: un frontend non ha tabelle sue, e ogni dato lo chiede alle API col gettone.

Lo scrive l'agente `zr-backoffice`: è l'altra metà del suo contratto (`openapi/v1.yaml`, la documentazione su
`docs.zeiras.com`).

**Repo pubblico di proposito**: i frontend lo installano da Composer senza credenziali. Quindi qui dentro **nessun
segreto, mai**, e nessun indirizzo interno. La CI fallisce su un file sensibile, su una chiave privata, su un valore di
riserva per una variabile segreta e su un valore segreto scritto come in un `.env` o in un file YAML: lo script è
`.github/nessun-segreto.sh`, si lancia anche in locale.

## Installazione

Da GitHub, a un tag (le versioni sono semver; prima della 1.0 un minore nuovo può rompere):

```json
"repositories": [{"type": "vcs", "url": "https://github.com/ma-cpmakers/zr-auth"}],
"require": {"zeiras/zr-auth": "^0.2"}
```

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
Sessione::workspace();  // {id, nome, slug}
Sessione::ruolo();      // proprietario, amministratore o membro

// L'uscita: accessi.elimina chiude l'accesso e ogni gettone che ne discende.
Api::persona()->delete('/v1/accessi/'.Sessione::accesso());
Sessione::chiudi();
```

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
- Un backoffice che non risponde, un 5xx o una risposta senza JSON diventano `BackofficeNonRisponde`: mai una lista vuota.

## La guardia, e il suo test

`ConGettone` entra da sé nei gruppi `web` e `api`: senza una sessione col gettone, una pagina rimanda all'ingresso e
ricorda la pagina chiesta (`url.intended`, da usare con `redirect()->intended()` dopo l'accesso); una visita di Inertia
riceve 409 con `X-Inertia-Location`; una richiesta JSON 401.

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
i suoi freni. Con lui un frontend prova nella sua CI l'ingresso, l'uscita e la verifica dell'email.

```php
use Zeiras\Auth\Testing\BackofficeFinto;

$finto = BackofficeFinto::attiva();
$anna = $finto->persona('anna@example.com', 'una password lunga e sicura');  // la persona, come la dà il backoffice
$studio = $finto->workspace('Studio Anna', $anna);                          // {id, nome}: Anna è la proprietaria
$finto->membro($studio, $finto->persona('bruno@example.com', '…', nome: 'Bruno'), 'membro');

$this->post('/accedi', ['email' => 'anna@example.com', 'password' => 'una password lunga e sicura']);
```

- `persona($email, $password, $nome = 'Anna', $lingua = 'it', $verificata = true)`. Con `verificata: false` l'email è da
  verificare, e alla persona parte il primo codice, come alla registrazione. `ultimoCodice($email)` fa da casella di
  posta: l'ultimo codice partito per quell'email, `null` se nessuno; un codice nuovo è sempre diverso da quello prima.
- Fa `accessi.crea`, `accessi.elimina`, `gettoni.crea`, `io.email.codice.crea` e `io.email.verifica.crea`. Una chiamata di
  `/v1` che non conosce lancia `RichiestaSconosciuta`: il finto non inventa una risposta che il backoffice non darebbe.
- Acceso il finto, alle API risponde solo lui: niente altri `Http::fake` che rispondano a `api.zeiras.com`, né un
  `Http::fake()` senza indirizzo, che risponderebbe a tutto. Le chiamate verso altri indirizzi restano agli altri fake.
- Il tempo è `now()`, e un test lo sposta con `travel()`: i gettoni valgono 12 ore dall'accesso, un codice 10 minuti, un
  freno fino alla fine della sua finestra.
- I freni sono quelli del backoffice: 5 richieste al minuto per email in `accessi.crea` (un accesso riuscito azzera il
  conto), `io.email.codice.crea` e `io.email.verifica.crea`, poi `429` con `Retry-After`; fra un codice e l'altro 60
  secondi, al più 5 codici in un'ora e 10 in un giorno; un codice vale 5 tentativi, e una persona ha 10 codici sbagliati
  al giorno. Non fa, per ora, il freno di `gettoni.crea` (60 gettoni in un'ora) né quello del gettone.
- Che risponda come il contratto lo prova la CI del backoffice: ogni sua risposta passa la validazione del contratto vero,
  e le copie dei testi sono uguali byte per byte a quelle del backoffice.
