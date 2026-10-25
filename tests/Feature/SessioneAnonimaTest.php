<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Ritorno;
use Zeiras\Auth\Sessione;

// Un GET anonimo con una query lunga non deve riempire Redis (misura di zr-devops-af, 25/10): la sessione di chi non è entrato
// resta di qualche centinaio di byte. url.intended è l'indirizzo con la query solo se percorso e query stanno in 512 byte, altrimenti
// il solo percorso (Ritorno::dallaRichiesta); `_previous.url` lo stesso (AvviaLaSessioneSenzaQuery, la sottoclasse di StartSession
// che il provider mette nel gruppo `web`). La query corta serve: `/ingresso?state=&sfida=` dei moduli deve riprendere dopo l'accesso.

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

it('la query arriva intera alla rotta pubblica, e in _previous.url resta se è corta', function () {
    Route::middleware('web')->get('cerca', fn (Request $richiesta) => ['q' => $richiesta->query('q')])
        ->withoutMiddleware(ConGettone::class);

    $this->get(FRONTEND.'/cerca?q=ciao')->assertOk()->assertJson(['q' => 'ciao']);

    expect(session('_previous.url'))->toBe(FRONTEND.'/cerca?q=ciao');
});

it('il ritorno di un GET è l\'indirizzo della richiesta, come fullUrl(): la query corta resta, il frammento no', function () {
    expect(Ritorno::dallaRichiesta(Request::create(FRONTEND.'/w/studio/b?vista=2&q=ciao')))->toBe(FRONTEND.'/w/studio/b?q=ciao&vista=2')
        ->and(Ritorno::dallaRichiesta(Request::create(FRONTEND.'/')))->toBe(FRONTEND)
        ->and(Ritorno::dallaRichiesta(Request::create(FRONTEND.'/w/studio/b/')))->toBe(FRONTEND.'/w/studio/b')
        ->and(Ritorno::dallaRichiesta(Request::create(FRONTEND.'/w/studio/b?vista=2'), false))->toBe(FRONTEND.'/w/studio/b');
});

it('la query resta se percorso e query stanno in 512 byte, oltre si ricorda il solo percorso', function () {
    $percorso = '/w/studio/b';
    $tiene = 512 - strlen($percorso) - strlen('?k=');

    expect(Ritorno::dallaRichiesta(Request::create(FRONTEND.$percorso.'?k='.str_repeat('a', $tiene))))->toBe(FRONTEND.$percorso.'?k='.str_repeat('a', $tiene))
        ->and(Ritorno::dallaRichiesta(Request::create(FRONTEND.$percorso.'?k='.str_repeat('a', $tiene + 1))))->toBe(FRONTEND.$percorso);
});

it('/ingresso con state e sfida riprende dopo l\'accesso: il ritorno li porta tutti e due', function () {
    Route::middleware('web')->get('ingresso', fn () => 'ok');
    $state = str_repeat('s', 43);
    $sfida = str_repeat('f', 43);

    $this->get(FRONTEND.'/ingresso?state='.$state.'&sfida='.$sfida)->assertRedirect(INGRESSO);

    expect(session('_previous.url'))->toBe(FRONTEND.'/ingresso?sfida='.$sfida.'&state='.$state)
        ->and(Sessione::ritorno(FRONTEND.'/predefinita'))->toBe(FRONTEND.'/ingresso?sfida='.$sfida.'&state='.$state);
});

it('6.000 caratteri di query dopo /ingresso non scrivono niente di grosso: il ritorno è il solo percorso', function () {
    Route::middleware('web')->get('ingresso', fn () => 'ok');

    $this->get(FRONTEND.'/ingresso?state='.str_repeat('s', 6000).'&sfida='.str_repeat('f', 43))->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(FRONTEND.'/ingresso')
        ->and(session('_previous.url'))->toBe(FRONTEND.'/ingresso')
        ->and(strlen(serialize(session()->all())))->toBeLessThan(800);
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

it('cento GET anonimi con un percorso lungo non riempiono la sessione: _previous.url non si scrive', function () {
    Route::middleware('web')->get('w/{workspace}/pagina', fn () => 'ok');

    $massimo = 0;
    foreach (range(1, 100) as $i) {
        $this->get(FRONTEND.'/w/'.str_repeat('a', 7000).$i.'/pagina')->assertRedirect(INGRESSO);

        $massimo = max($massimo, strlen(serialize(session()->all())));
        expect(session()->has('_previous.url'))->toBeFalse()
            ->and(session()->has('url.intended'))->toBeFalse();
    }

    expect($massimo)->toBeLessThan(800);
});

it('il ricevitore dell\'ingresso non lascia il suo codice in _previous.url, nemmeno a sessione aperta', function () {
    Route::middleware('web')->get('ricevitore-di-prova', fn () => 'ok')->name('zr-auth.ricevitore')->withoutMiddleware(ConGettone::class);
    apriSessione();

    $this->get(FRONTEND.'/ricevitore-di-prova?codice=segreto&state=altro');

    expect(session('_previous.url'))->toBe(FRONTEND.'/ricevitore-di-prova');
});
