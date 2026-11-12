<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3574: il parametro `prova` (escludi|includi) e il campo `di_prova` negli elenchi dello staff del finto, per zr-superadmin («Mostra anche i dati di prova»). Che forme ed errori siano quelli
// del backoffice lo prova il suo FintoTest; qui si prova che il finto tiene le regole: il filtro prima della paginazione e dei conteggi, il segno dall'email, il 422 sul parametro (query) o su `#/prova` (corpo).

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** Lo stato, il codice e il punto dell'errore di /v1, o ['ok', null, null] se riesce. */
function esitoProvaStaff(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice, $errore->errori[0]['pointer'] ?? $errore->errori[0]['parameter'] ?? null];
    } catch (GettoneRifiutato $rifiutato) {
        return [$rifiutato->errore?->stato, $rifiutato->errore?->codice, null];
    }

    return ['ok', null, null];
}

/** Una persona dello staff già entrata: il suo gettone. */
function gettoneProvaStaff(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin'): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));

    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, true));
}

/**
 * Il mondo di prova: Sara (superadmin vera), una persona dello staff di prova (`solo_uat`), due persone vere e tre di prova (una per ogni forma d'email), due workspace (uno vero, uno di prova)
 * e due assistenze (su Giulia, vera, e su `uat+uno`, di prova).
 *
 * @return array<string, mixed>
 */
function mondoDiProva(BackofficeFinto $finto): array
{
    $sara = gettoneProvaStaff($finto, 'sara@lucianocastro.com');
    $giulia = $finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');
    $marco = $finto->persona('marco@example.com', PASSWORD, nome: 'Marco');
    $uat = $finto->persona('uat+uno@castroandpartners.com', PASSWORD, nome: 'Uno');
    $invalid = $finto->persona('due@prova.invalid', PASSWORD, nome: 'Due');
    $prova = $finto->persona('prova@lucianocastro.com', PASSWORD, nome: 'Tre');
    $staffDiProva = $finto->staffDi($finto->persona('uat+staff@castroandpartners.com', PASSWORD, nome: 'Staff'), 'supporto', true, true);
    $vero = $finto->workspace('Studio vero', $giulia);
    $diProva = $finto->workspace('Studio di prova', $uat);
    $finto->attivaApp($vero, 'pm');
    $finto->attivaApp($diProva, 'pm');
    entraNelFinto('giulia@example.com');
    entraNelFinto('uat+uno@castroandpartners.com');
    Carbon::setTestNow('2026-11-10 10:00:05');
    $assistenzaVera = Api::staff($sara)->post("/v1/staff/utenti/{$giulia['id']}/impersonificazioni", ['app' => 'pm'])['data']['impersonificazione_id'];
    $assistenzaDiProva = Api::staff($sara)->post("/v1/staff/utenti/{$uat['id']}/impersonificazioni", ['app' => 'pm'])['data']['impersonificazione_id'];

    return compact('sara', 'giulia', 'marco', 'uat', 'invalid', 'prova', 'staffDiProva', 'vero', 'diProva', 'assistenzaVera', 'assistenzaDiProva');
}

it('utenti: escludi toglie le persone di prova prima della paginazione; senza parametro e con includi come prima; ogni riga e la scheda portano di_prova', function () {
    $m = mondoDiProva($this->finto);

    $tutti = Api::staff($m['sara'])->get('/v1/staff/utenti')['data'];
    $righe = array_column($tutti, null, 'id');
    $senza = Api::staff($m['sara'])->get('/v1/staff/utenti', ['prova' => 'escludi'])['data'];

    expect(array_column(Api::staff($m['sara'])->get('/v1/staff/utenti', ['prova' => 'includi'])['data'], 'id'))->toBe(array_column($tutti, 'id'))
        ->and($righe[$m['giulia']['id']]['di_prova'])->toBeFalse()
        ->and($righe[$m['marco']['id']]['di_prova'])->toBeFalse()
        ->and($righe[$m['uat']['id']]['di_prova'])->toBeTrue()
        ->and($righe[$m['invalid']['id']]['di_prova'])->toBeTrue()
        ->and($righe[$m['prova']['id']]['di_prova'])->toBeTrue()
        ->and(array_column($senza, 'id'))->toEqualCanonicalizing(array_column(array_filter($tutti, fn (array $riga) => ! $riga['di_prova']), 'id'))
        ->and(array_column($senza, 'id'))->toContain($m['giulia']['id'], $m['marco']['id'])
        ->and(array_filter($senza, fn (array $riga) => $riga['di_prova']))->toBe([]);

    // Il filtro sta prima della paginazione: pagine da uno, mai una persona di prova, e la somma è tutto ciò che resta.
    $viste = [];
    $cursore = null;
    do {
        $pagina = Api::staff($m['sara'])->get('/v1/staff/utenti', ['prova' => 'escludi', 'limite' => 1] + ($cursore === null ? [] : ['cursore' => $cursore]));
        expect($pagina['data'])->toHaveCount(1);
        $viste[] = $pagina['data'][0]['id'];
        $cursore = $pagina['successivo'];
    } while ($cursore !== null);

    expect($viste)->toBe(array_column($senza, 'id'))
        ->and(Api::staff($m['sara'])->get("/v1/staff/utenti/{$m['uat']['id']}")['data']['di_prova'])->toBeTrue()
        ->and(Api::staff($m['sara'])->get("/v1/staff/utenti/{$m['giulia']['id']}")['data']['di_prova'])->toBeFalse();
});

it('la ricerca e le esportazioni degli utenti: prova nel corpo; altro che escludi/includi è 422 su #/prova, e null vale includi', function () {
    $m = mondoDiProva($this->finto);
    $cerca = fn (array $corpo) => Api::staff($m['sara'])->post('/v1/staff/utenti/ricerca', $corpo);

    expect(array_column($cerca(['prova' => 'escludi'])['data'], 'id'))->not->toContain($m['uat']['id'], $m['invalid']['id'], $m['prova']['id'])->toContain($m['giulia']['id'])
        ->and(array_column($cerca(['prova' => 'includi'])['data'], 'id'))->toContain($m['uat']['id'])
        ->and(array_column($cerca(['prova' => null])['data'], 'id'))->toContain($m['uat']['id'])
        ->and($cerca(['q' => 'uno'])['data'][0]['di_prova'])->toBeTrue()
        ->and(esitoProvaStaff(fn () => $cerca(['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', '#/prova'])
        ->and(esitoProvaStaff(fn () => $cerca(['prova' => true])))->toBe([422, 'dati_non_validi', '#/prova']);

    $esportazione = Api::staff($m['sara'])->post('/v1/staff/utenti/ricerca/esportazioni', ['prova' => 'escludi'])['data'];
    $this->finto->preparaLeEsportazioniDelloStaff();
    $csv = implode('', iterator_to_array(Api::staff($m['sara'])->scaricaConPost("/v1/staff/esportazioni/{$esportazione['id']}/scaricamento")->blocchi(), false));

    expect($csv)->toContain('giulia@example.com')->not->toContain('uat+uno@castroandpartners.com')->not->toContain('due@prova.invalid')
        ->and(esitoProvaStaff(fn () => Api::staff($m['sara'])->post('/v1/staff/utenti/ricerca/esportazioni', ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', '#/prova']);
});

it('i conteggi: con prova=escludi contano senza i dati di prova, senza parametro come prima, altro è 422 sul parametro', function () {
    $m = mondoDiProva($this->finto);
    $conta = fn (string $percorso, array $query = []) => Api::staff($m['sara'])->get($percorso, $query)['data'];
    $utenti = $conta('/v1/staff/utenti/conteggi');
    $senza = $conta('/v1/staff/utenti/conteggi', ['prova' => 'escludi']);
    $dellElenco = count(Api::staff($m['sara'])->get('/v1/staff/utenti', ['prova' => 'escludi', 'limite' => 50])['data']);

    expect($conta('/v1/staff/utenti/conteggi', ['prova' => 'includi']))->toBe($utenti)
        ->and($senza['tutti'])->toBe($dellElenco)->and($senza['tutti'])->toBeLessThan($utenti['tutti'])
        ->and($conta('/v1/staff/workspace/conteggi'))->toMatchArray(['tutti' => 2])
        ->and($conta('/v1/staff/workspace/conteggi', ['prova' => 'escludi']))->toBe(['tutti' => 1, 'attivo' => 1, 'in_eliminazione' => 0])
        ->and(esitoProvaStaff(fn () => $conta('/v1/staff/utenti/conteggi', ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', 'prova'])
        ->and(esitoProvaStaff(fn () => $conta('/v1/staff/workspace/conteggi', ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', 'prova']);
});

it('i workspace: di prova se lo è il proprietario; elenco e ricerca con prova; di_prova in riga e scheda; 422 sul parametro e su #/prova', function () {
    $m = mondoDiProva($this->finto);
    $senzaProprietario = $this->finto->workspace('Studio orfano', $this->finto->persona('orfano@example.com', PASSWORD, nome: 'Orfano'));
    $elenco = array_column(Api::staff($m['sara'])->get('/v1/staff/workspace')['data'], null, 'id');
    $cerca = fn (array $corpo) => Api::staff($m['sara'])->post('/v1/staff/workspace/ricerca', $corpo);

    expect($elenco[$m['vero']['id']]['di_prova'])->toBeFalse()
        ->and($elenco[$m['diProva']['id']]['di_prova'])->toBeTrue()
        ->and(array_column(Api::staff($m['sara'])->get('/v1/staff/workspace', ['prova' => 'escludi'])['data'], 'id'))->toEqualCanonicalizing([$m['vero']['id'], $senzaProprietario['id']])
        ->and(array_column(Api::staff($m['sara'])->get('/v1/staff/workspace', ['prova' => 'includi'])['data'], 'id'))->toContain($m['diProva']['id'])
        ->and(array_column($cerca(['prova' => 'escludi'])['data'], 'id'))->not->toContain($m['diProva']['id'])
        ->and(array_column($cerca(['prova' => 'includi'])['data'], 'id'))->toContain($m['diProva']['id'])
        ->and(Api::staff($m['sara'])->get("/v1/staff/workspace/{$m['diProva']['id']}")['data']['di_prova'])->toBeTrue()
        ->and(Api::staff($m['sara'])->get("/v1/staff/workspace/{$m['vero']['id']}")['data']['di_prova'])->toBeFalse()
        ->and(esitoProvaStaff(fn () => Api::staff($m['sara'])->get('/v1/staff/workspace', ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', 'prova'])
        ->and(esitoProvaStaff(fn () => $cerca(['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', '#/prova']);
});

it('staff.elenca: escludi toglie le persone dello staff solo_uat e ogni riga porta di_prova; altro è 422 sul parametro', function () {
    $m = mondoDiProva($this->finto);
    $righe = array_column(Api::staff($m['sara'])->get('/v1/staff')['data'], null, 'id');

    expect($righe[$m['staffDiProva']['id']]['di_prova'])->toBeTrue()
        ->and(array_values(array_unique(array_column(array_filter($righe, fn (array $riga) => $riga['id'] !== $m['staffDiProva']['id']), 'di_prova'))))->toBe([false])
        ->and(array_column(Api::staff($m['sara'])->get('/v1/staff', ['prova' => 'escludi'])['data'], 'id'))->not->toContain($m['staffDiProva']['id'])
        ->and(array_column(Api::staff($m['sara'])->get('/v1/staff', ['prova' => 'includi'])['data'], 'id'))->toContain($m['staffDiProva']['id'])
        ->and(esitoProvaStaff(fn () => Api::staff($m['sara'])->get('/v1/staff', ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', 'prova']);
});

it('impersonificazioni: escludi toglie le assistenze su persone o workspace di prova; altro è 422 sul parametro', function () {
    $m = mondoDiProva($this->finto);

    expect(array_column(Api::staff($m['sara'])->get('/v1/staff/impersonificazioni')['data'], 'id'))->toEqualCanonicalizing([$m['assistenzaVera'], $m['assistenzaDiProva']])
        ->and(array_column(Api::staff($m['sara'])->get('/v1/staff/impersonificazioni', ['prova' => 'escludi'])['data'], 'id'))->toBe([$m['assistenzaVera']])
        ->and(array_column(Api::staff($m['sara'])->get('/v1/staff/impersonificazioni', ['prova' => 'includi'])['data'], 'id'))->toContain($m['assistenzaDiProva'])
        ->and(esitoProvaStaff(fn () => Api::staff($m['sara'])->get('/v1/staff/impersonificazioni', ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', 'prova']);
});

it('registro: escludi toglie le righe il cui oggetto è una persona, un workspace o una persona dello staff di prova, e lascia le altre; esportazione col filtro nel corpo', function () {
    $m = mondoDiProva($this->finto);
    $righe = fn (array $query = []) => Api::staff($m['sara'])->get('/v1/staff/registro', $query)['data'];
    $bersagli = fn (array $lista) => array_map(fn (array $riga) => $riga['bersaglio_tipo'].':'.$riga['bersaglio_id'], $lista);

    // Il registro ha le assistenze aperte: una su Giulia (vera) e una su `uat+uno` (di prova).
    $tutte = $bersagli($righe(['limite' => 50]));
    $senza = $bersagli($righe(['prova' => 'escludi', 'limite' => 50]));

    expect($tutte)->toContain('utente:'.$m['uat']['id'], 'utente:'.$m['giulia']['id'])
        ->and($senza)->toContain('utente:'.$m['giulia']['id'])->not->toContain('utente:'.$m['uat']['id'])
        ->and(count($senza))->toBeLessThan(count($tutte))
        ->and($bersagli($righe(['prova' => 'includi', 'limite' => 50])))->toBe($tutte)
        ->and(esitoProvaStaff(fn () => $righe(['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', 'prova']);

    $csv = implode('', iterator_to_array(Api::staff($m['sara'])->scaricaConPost('/v1/staff/registro/esportazioni', corpo: ['prova' => 'escludi'])->blocchi(), false));

    expect($csv)->toContain($m['giulia']['id'])->not->toContain($m['uat']['id'])
        ->and(esitoProvaStaff(fn () => Api::staff($m['sara'])->scaricaConPost('/v1/staff/registro/esportazioni', corpo: ['prova' => 'tutto'])))->toBe([422, 'dati_non_validi', '#/prova']);
});

it('di_prova non si cambia da nessuna API: staff.utenti.modifica con di_prova è 422', function () {
    $m = mondoDiProva($this->finto);

    expect(esitoProvaStaff(fn () => Api::staff($m['sara'])->patch("/v1/staff/utenti/{$m['giulia']['id']}", ['di_prova' => true])))->toBe([422, 'dati_non_validi', '#/di_prova']);
});
