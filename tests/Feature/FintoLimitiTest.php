<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #3958: il 409 `limite_raggiunto` del finto dice nel `detail` il limite vero del metodo che risponde, come il backoffice
// (App\Http\Problemi\Limite, lang `errori.limite_raggiunto.varianti.<limite>`). Che i testi siano gli stessi lo prova il FintoTest
// del backoffice, che confronta lang/ byte per byte.

/** Il sorgente di ogni sito che lancia `limite_raggiunto` nel finto. */
function sitiDelLimite(): array
{
    $siti = [];
    $cartella = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Testing'));

    foreach ($cartella as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $numero => $riga) {
            if (str_contains($riga, "new Problema('limite_raggiunto'")) {
                $siti[] = [basename($file->getPathname()).':'.($numero + 1), $riga];
            }
        }
    }

    return $siti;
}

it('ogni sito che lancia limite_raggiunto nel finto dice il suo limite, e il limite ha il suo testo in italiano, inglese e spagnolo', function () {
    $siti = sitiDelLimite();
    expect(count($siti))->toBeGreaterThan(40);

    foreach ($siti as [$dove, $riga]) {
        expect(preg_match("/limite: (?:'([a-z_]+)'|\\\$limite|\\[)/", $riga, $trovato))->toBe(1, "{$dove} non dice il suo limite");

        foreach (preg_match_all("/'([a-z0-9_]+_per_[a-z0-9_]+)'/", $riga, $nomi) ? $nomi[1] : [] as $nome) {
            foreach (['it', 'en', 'es'] as $lingua) {
                $catalogo = require dirname(__DIR__, 2)."/resources/lang/{$lingua}/errori.php";
                expect(array_key_exists($nome, $catalogo['limite_raggiunto']['varianti']) ? 'presente' : "{$dove}: manca {$lingua} {$nome}")->toBe('presente');
            }
        }
    }
});

it('i tre cataloghi hanno gli stessi limiti, e ognuno porta :tetto', function () {
    $it = require dirname(__DIR__, 2).'/resources/lang/it/errori.php';
    $chiavi = array_keys($it['limite_raggiunto']['varianti']);

    foreach (['en', 'es'] as $lingua) {
        $catalogo = require dirname(__DIR__, 2)."/resources/lang/{$lingua}/errori.php";
        expect(array_keys($catalogo['limite_raggiunto']['varianti']))->toBe($chiavi);
    }

    foreach (['it', 'en', 'es'] as $lingua) {
        $catalogo = require dirname(__DIR__, 2)."/resources/lang/{$lingua}/errori.php";

        foreach ($catalogo['limite_raggiunto']['varianti'] as $nome => $testo) {
            expect(str_contains($testo, ':tetto') ? 'con :tetto' : "{$lingua} {$nome} senza :tetto")->toBe('con :tetto');
        }
    }
});

it('il detail del 409 dice il limite e il suo numero, nella lingua della persona (moduli.interesse.crea: 50 per persona)', function () {
    $finto = BackofficeFinto::attiva();
    $detail = [];

    foreach (['it', 'en', 'es'] as $lingua) {
        $finto->persona("anna-{$lingua}@example.com", PASSWORD, nome: 'Anna', lingua: $lingua);
        $gettone = entraNelFinto("anna-{$lingua}@example.com")['gettone']['gettone'];

        foreach (range(1, 50) as $n) {
            alFinto('POST', '/v1/moduli/modulo-'.$n.'/interesse', gettone: $gettone)->assertCreated();
        }

        $oltre = alFinto('POST', '/v1/moduli/modulo-51/interesse', gettone: $gettone);
        expect([$oltre->status(), $oltre->json('codice'), $oltre->json('limite')])->toBe([409, 'limite_raggiunto', 50]);
        $detail[$lingua] = $oltre->json('detail');
    }

    expect($detail['it'])->toContain('50')->and($detail['it'])->not->toContain('board')
        ->and($detail['en'])->toContain('50')->and($detail['es'])->toContain('50')
        ->and(count(array_unique($detail)))->toBe(3);
});
