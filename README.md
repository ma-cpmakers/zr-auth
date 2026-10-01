# zr-auth

La parte comune dei moduli Zeiras (`zr-crm`, `zr-board`, …): ingresso tramite `zr-home` (OpenID Connect), verifica dei
token, sessione nel workspace, ricezione degli avvisi di `zr-home`, test che scorre le rotte del modulo, componente
React della barra comune.

**Repo pubblico di proposito**: i moduli lo installano da Composer senza credenziali sul server. Quindi qui dentro
**nessun segreto, mai** — niente `.env`, niente id o segreti di client, niente URL interni. La CI fallisce su un file
sensibile (`.env`, chiavi e certificati, `.p12` e `.pfx`, l'`auth.json` di Composer), su una chiave privata, su un valore
di riserva per una variabile segreta (`env()`, `getenv()`, `?:`, `??`) e su un valore segreto nella configurazione di
PHPUnit (`.github/nessun-segreto.sh`, che si lancia anche in locale).

Lo scrive l'agente `zr-home` (è l'altra metà del contratto coi moduli); il contratto sta nella spec di `zr-home`.

## Cosa fa nel modulo

- **Ogni pagina e ogni API vuole la sessione.** Il middleware `Zeiras\Auth\Http\Middleware\Sessione` entra da sé nei
  gruppi `web` e `api`. Senza sessione una pagina rimanda all'ingresso di zr-home (una visita di Inertia riceve il 409
  con `X-Inertia-Location`), una richiesta JSON o un'API rispondono 401. Il gruppo `api` di Laravel 13 non ha la
  sessione di Laravel: le API del modulo la hanno con `statefulApi()` di Sanctum, o stando nel gruppo `web`; altrimenti
  rispondono sempre 401.
- **L'ingresso** è il flusso a codice di OpenID Connect con PKCE S256, `state` e `nonce`. Al ritorno (`GET
  /auth/callback`) il modulo scambia il codice, verifica l'`id_token` col JWKS di zr-home (firma, emittente, destinatario,
  scadenza, `nonce`, il workspace chiesto) e apre la sessione; poi torna alla pagina chiesta all'inizio. Lo `state` vale
  una volta, e il ritorno ha un freno: 30 al minuto per indirizzo (un IPv6 conta per il suo /64), che dev'essere quello
  vero del visitatore (vedi «L'indirizzo del visitatore»).
- **Il JWKS di zr-home resta in cache 10 minuti** (chiave `zr-auth:jwks` nella cache del modulo), e solo se ha chiavi
  valide. **zr-home firma con una chiave sola, e una chiave nuova arriva con un `kid` nuovo** (`token_headers.kid` in
  `config/openid.php` di zr-home): un token con un `kid` che le chiavi in cache non hanno fa rileggere il JWKS, al più una
  volta al minuto. La chiave vecchia non resta nel JWKS: un token firmato con quella e ancora in viaggio fallisce una
  volta — il ritorno risponde 403 e l'ingresso si rifà, l'avviso risponde 400 e zr-home lo firma di nuovo e lo ripete. Se
  il modulo ha riletto da meno di un minuto, nel caso peggiore per quel minuto i ritorni sono 403 e gli avvisi 400. Una
  chiave nuova con lo **stesso** `kid` resterebbe sconosciuta al modulo fino a 10 minuti. Se zr-home non risponde, o il
  JWKS non vale, il modulo non lo richiede per 30 secondi, e intanto non consuma la rilettura del minuto: il ritorno è
  403 e l'avviso 400, mai un errore del server, con una riga `warning` nel log (`zr-auth: …`).
- **La sessione è di un workspace solo e vale al massimo 12 ore.** Dopo, l'ingresso si rifà in silenzio (`prompt=none`);
  se zr-home vuole la persona davanti (`login_required`, `interaction_required`, `consent_required`,
  `account_selection_required`), riparte con l'accesso. Un indirizzo con `?workspace=<id>` diverso da quello della
  sessione rifà l'ingresso per quel workspace: è così che entra il link «Apri →» della home di zr-home.
- **La persona** si ricopia a ogni ingresso nella tabella `zr_persone` (l'id è il `sub` di zr-home: email, nome, lingua).
  `zr_persone` tiene chi è entrato nel modulo da qualunque workspace, e non dice chi è nel workspace (lo dirà zr-home,
  voce #981): un modulo non lega `{persona}` in una rotta né valida `exists:zr_persone,id` per mostrare una persona.
- **Gli avvisi di zr-home** arrivano a `POST /auth/avviso`: all'uscita da zr-home, quando una persona viene tolta da un
  workspace o ne cambia il ruolo, quando una persona cambia o reimposta la password (un avviso per ogni suo workspace),
  quando un workspace disattiva il modulo. L'avviso è un `logout_token` del Back-Channel
  Logout di OpenID Connect; il modulo lo verifica col JWKS di zr-home (firma, emittente, destinatario, firmato da non più
  di 5 minuti, l'evento del back-channel, niente `nonce`, `typ` `logout+jwt`) e registra una revoca in `zr_revoche`.
  Alla loro richiesta successiva si chiudono le sessioni aperte **prima** dell'avviso — quella di quella sessione di
  zr-home (`sid`), quelle della persona in quel workspace (`sub` e `workspace`), quelle del workspace (`workspace`, da
  solo: un'estensione di Zeiras al Back-Channel Logout, che prevede `sid` o `sub`) — e
  l'ingresso si rifà in silenzio; chi rientra dopo l'avviso resta dentro. Il claim `motivo` dice perché — `uscita` (con
  `sid`); `membro_rimosso`, `ruolo_cambiato`, `password_cambiata` (con `sub` e `workspace`); `app_disattivata` (con
  `workspace`) — e si registra nella revoca: non cambia cosa si chiude, e un motivo che il modulo non conosce vale come
  gli altri. Un avviso non valido risponde 400 e non chiude niente. La rotta non ha sessione né CSRF: la chiama il server
  di zr-home, all'indirizzo del modulo nel suo catalogo.

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
Un segreto sbagliato fa rispondere 403 a ogni ritorno, con una riga `warning` nel log: `zr-auth: zr-home respinge lo
scambio del codice`, con lo stato (`401`, e dopo dieci al minuto `429`: il freno di zr-home) e l'errore di zr-home
(`invalid_client`), mai il segreto né il codice.

La sessione di Laravel deve arrivare al ritorno da zr-home: `SESSION_SAME_SITE=lax` (il default), non `strict`.
`SESSION_LIFETIME` sotto i 720 minuti fa rifare l'ingresso prima, dopo l'inattività (in silenzio).

**L'indirizzo del visitatore.** Il freno del ritorno conta per `$request->ip()`. Dietro Cloudflare, o un altro proxy,
quell'indirizzo è del proxy: chi passa dallo stesso nodo divide con tutti gli altri 30 ritorni al minuto, e un estraneo li
esaurisce con 30 richieste. Il modulo deve vedere l'indirizzo vero: lo ricava il server web (nginx col modulo `real_ip` e
i blocchi di Cloudflare in `set_real_ip_from`), oppure Laravel con `trustProxies(at: [...])` che elenca **solo** i blocchi
di Cloudflare — mai `'*'`, che crede all'`X-Forwarded-For` di chiunque si colleghi direttamente all'origine.

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
una riga nuova prende quel workspace, e `save()` e `update()` del modello non cambiano il workspace di una riga (un
`workspace_id` diverso fra i dati resta com'era). Senza sessione (console, coda) non trova niente: un job che lavora per
un workspace apre il `Contesto` da sé.

**La guardia sta negli eventi del modello.** Le vie che non ci passano scrivono `workspace_id` così com'è, e non si usano
mai con un `workspace_id` fra i dati: `update()`, `insert()` e `upsert()` sul builder, `increment()`, `decrement()` e
`incrementEach()` con le colonne in più, `saveQuietly()`, `updateQuietly()`, `Model::withoutEvents()`. `DB::table()` non
ha nemmeno lo scope: legge e scrive le righe di tutti i workspace.

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
voluta si scrive nel test del modulo, col perché accanto. In un modulo Laravel 13 di serie il disco `local` ha `'serve'
=> true` (`config/filesystems.php`), che apre `GET` e `PUT storage/{path}` fuori dai gruppi: si mette `false`, o si
elencano nel test col perché.

## Sviluppo

La CI gira `composer validate`, il controllo dei segreti (`bash .github/nessun-segreto.sh`) e Pest su Testbench (PHP 8.4, SQLite in memoria). I test
usano uno zr-home finto (`tests/Pest.php`): le chiavi RSA nascono nel test, il JWKS e lo scambio del codice sono risposte
di `Http::fake()`, e nessuna richiesta esce (`preventStrayRequests()`); un avviso è un `logout_token` firmato nel test
(`avvisa()`).
