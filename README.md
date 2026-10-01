# zr-auth

La parte comune dei moduli Zeiras (`zr-crm`, `zr-board`, …): ingresso tramite `zr-home` (OpenID Connect), verifica dei
token, sessione nel workspace, ricezione degli avvisi di `zr-home`, test che scorre le rotte del modulo, componente
React della barra comune.

**Repo pubblico di proposito**: i moduli lo installano da Composer senza credenziali sul server. Quindi qui dentro
**nessun segreto, mai** — niente `.env`, niente id o segreti di client, niente URL interni. La CI fallisce su un file
sensibile o su una chiave privata nel repo.

Lo scrive l'agente `zr-home` (è l'altra metà del contratto coi moduli); il contratto sta nella spec di `zr-home`.

## Cosa fa nel modulo

- **Ogni pagina e ogni API vuole la sessione.** Il middleware `Zeiras\Auth\Http\Middleware\Sessione` entra da sé nei
  gruppi `web` e `api`. Senza sessione una pagina rimanda all'ingresso di zr-home (una visita di Inertia riceve il 409
  con `X-Inertia-Location`), una richiesta JSON o un'API rispondono 401.
- **L'ingresso** è il flusso a codice di OpenID Connect con PKCE S256, `state` e `nonce`. Al ritorno (`GET
  /auth/callback`) il modulo scambia il codice, verifica l'`id_token` col JWKS di zr-home (firma, emittente, destinatario,
  scadenza, `nonce`) e apre la sessione; poi torna alla pagina chiesta all'inizio. Lo `state` vale una volta.
- **La sessione è di un workspace solo e vale al massimo 12 ore.** Dopo, l'ingresso si rifà in silenzio (`prompt=none`);
  se zr-home non ha più la sessione, riparte con l'accesso. Un indirizzo con `?workspace=<id>` diverso da quello della
  sessione rifà l'ingresso per quel workspace: è così che entra il link «Apri →» della home di zr-home.
- **La persona** si ricopia a ogni ingresso nella tabella `zr_persone` (l'id è il `sub` di zr-home: email, nome, lingua).
- **Gli avvisi di zr-home** arrivano a `POST /auth/avviso`: all'uscita da zr-home, quando una persona viene tolta da un
  workspace o ne cambia il ruolo, quando un workspace disattiva il modulo. L'avviso è un `logout_token` del Back-Channel
  Logout di OpenID Connect; il modulo lo verifica col JWKS di zr-home (firma, emittente, destinatario, firmato da non più
  di 5 minuti, l'evento del back-channel, niente `nonce`, `typ` `logout+jwt`) e registra una revoca in `zr_revoche`.
  Alla loro richiesta successiva si chiudono le sessioni aperte **prima** dell'avviso — quella di quella sessione di
  zr-home (`sid`), quelle della persona in quel workspace (`sub` e `workspace`), quelle del workspace (`workspace`) — e
  l'ingresso si rifà in silenzio; chi rientra dopo l'avviso resta dentro. Un avviso non valido risponde 400 e non chiude
  niente. La rotta non ha sessione né CSRF: la chiama il server di zr-home, all'indirizzo del modulo nel suo catalogo.

## Installazione

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/ma-cpmakers/zr-auth" }],
"require": { "zeiras/zr-auth": "dev-main" }
```

Il provider si registra da sé. Poi `php artisan migrate` crea `zr_persone` e `zr_revoche`.

Variabili d'ambiente del modulo (**solo** nell'`.env` del server, mai nel repo):

| Variabile | Cosa |
|-----------|------|
| `APP_URL` | l'indirizzo pubblico `https://` del modulo: il ritorno è `APP_URL/auth/callback`, e dev'essere quello registrato su zr-home |
| `ZR_AUTH_CLIENT_ID` | l'id del client del modulo su zr-home |
| `ZR_AUTH_CLIENT_SECRET` | il segreto del client |
| `ZR_HOME_URL` | facoltativa, default `https://app.zeiras.com` |

Il client lo crea chi gestisce zr-home, sul server di zr-home: `php artisan zeiras:modulo <codice>
--ritorno=https://<modulo>/auth/callback` stampa id e segreto **una volta sola**, e vanno nell'ambiente del modulo.

La sessione di Laravel deve arrivare al ritorno da zr-home: `SESSION_SAME_SITE=lax` (il default), non `strict`.
`SESSION_LIFETIME` sotto i 720 minuti fa rifare l'ingresso prima, dopo l'inattività (in silenzio).

## Nel codice

```php
use Zeiras\Auth\Contesto;

$contesto = app(Contesto::class);
$contesto->workspaceId();    // il workspace della sessione
$contesto->workspaceNome();
$contesto->ruolo();          // owner, admin o member
$contesto->persona();        // Zeiras\Auth\Persona
```

I dati del modulo si separano per workspace col tratto `DelWorkspace` (e una colonna `workspace_id`): il modello trova
solo le righe del workspace della sessione — anche nei binding delle rotte, dove l'id di un altro workspace è un 404 —,
una riga nuova prende quel workspace, e una riga non cambia mai workspace (un aggiornamento con un altro `workspace_id`
lo lascia com'era). Senza sessione (console, coda) non trova niente: un job che lavora per un workspace apre il
`Contesto` da sé. Un `update()` di massa sul builder non passa dagli eventi del modello: non va mai scritto con un
`workspace_id`.

```php
use Zeiras\Auth\Concerns\DelWorkspace;

class Contatto extends Model
{
    use DelWorkspace;
}
```

## Il test delle rotte — ogni modulo lo ha

```php
use Zeiras\Auth\Testing\Rotte;

it('ogni rotta del modulo vuole la sessione di zr-auth', function () {
    expect(Rotte::senzaSessione())->toBe([]);
});
```

`Rotte::senzaSessione()` elenca, come «METODO uri», ogni rotta che risponde senza la sessione: quelle fuori dai gruppi
`web` e `api` e quelle che si tolgono `Sessione` con `withoutMiddleware`. Le eccezioni sono tre: `GET auth/callback` (il
ritorno da zr-home), `POST auth/avviso` (gli avvisi di zr-home), `GET up` (il controllo di salute). Una rotta pubblica
voluta si scrive nel test del modulo, col perché accanto.

## Sviluppo

La CI gira `composer validate`, il controllo dei file sensibili e Pest su Testbench (PHP 8.4, SQLite in memoria). I test
usano uno zr-home finto (`tests/Pest.php`): le chiavi RSA nascono nel test, il JWKS e lo scambio del codice sono risposte
di `Http::fake()`, e nessuna richiesta esce (`preventStrayRequests()`); un avviso è un `logout_token` firmato nel test
(`avvisa()`).
