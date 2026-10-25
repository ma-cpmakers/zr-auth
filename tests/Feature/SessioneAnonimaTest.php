<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Ritorno;

// Un GET anonimo con una query lunga non deve riempire Redis (misura di zr-devops-af, 25/10): la sessione di chi non è entrato
// resta di qualche centinaio di byte. url.intended è il solo percorso (Ritorno::dallaRichiesta), `_previous.url` senza query
// (PrecedenteSenzaQuery).

it('cento GET anonimi con una query lunga lasciano una sessione piccola, con e senza la guardia', function (string $pagina) {
    $massimo = 0;
    foreach (range(1, 100) as $i) {
        $this->get(FRONTEND.$pagina.'?'.http_build_query(['q' => str_repeat('a', 6000), 'n' => $i]));

        $byte = strlen(serialize(session()->all()));
        $massimo = max($massimo, $byte);
        expect(session('_previous.url'))->toBe(FRONTEND.$pagina)
            ->and(session('url.intended', FRONTEND.$pagina))->toBe(FRONTEND.$pagina);
    }

    expect($massimo)->toBeLessThan(800);
})->with(['/pagina', '/pubblica']);

it('chi è entrato tiene la query in _previous.url: le sue sessioni sono poche e back() le serve', function () {
    apriSessione();

    $this->get(FRONTEND.'/pagina?vista=2');

    expect(session('_previous.url'))->toBe(FRONTEND.'/pagina?vista=2');
});

it('la query arriva intera alla rotta pubblica: la toglie solo dopo la risposta', function () {
    Route::middleware('web')->get('cerca', fn (Request $richiesta) => ['q' => $richiesta->query('q')])
        ->withoutMiddleware(ConGettone::class);

    $this->get(FRONTEND.'/cerca?q=ciao')->assertOk()->assertJson(['q' => 'ciao']);

    expect(session('_previous.url'))->toBe(FRONTEND.'/cerca');
});

it('il ritorno di un GET è il percorso rimesso sull\'origine, senza query né frammento', function () {
    expect(Ritorno::dallaRichiesta(Request::create(FRONTEND.'/w/studio/b?vista=2&q=ciao')))->toBe(FRONTEND.'/w/studio/b')
        ->and(Ritorno::dallaRichiesta(Request::create(FRONTEND.'/')))->toBe(FRONTEND.'/');
});

it('un percorso che non è una pagina interna pulita non si ricorda', function (string $percorso) {
    expect(Ritorno::dallaRichiesta(Request::create(FRONTEND.$percorso)))->toBeNull();
})->with([
    'segmento vuoto' => '/a//b',
    'punto punto' => '/a/../b',
    'punto punto codificato' => '/a/%2e%2e/b',
    'a capo' => '/a%0Ab',
    'spazio' => '/a%20b',
    'barra rovesciata' => '/a%5Cb',
    'troppo lungo' => '/'.str_repeat('a', 300),
]);

it('un GET con un percorso lungo non ricorda niente, nemmeno quello di prima', function () {
    Route::middleware('web')->get('lungo/{coda}', fn () => 'ok')->where('coda', '.*');
    test()->withSession(['url.intended' => FRONTEND.'/w/studio/ricordata-prima']);

    $this->get(FRONTEND.'/lungo/'.str_repeat('a', 400))->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
});
