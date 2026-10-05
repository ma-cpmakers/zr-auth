<?php

namespace Zeiras\Auth\Testing;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * La prova 8 della spec S01, per i test del frontend: il gettone non arriva mai al browser. Cerca un gettone di Zeiras
 * (`zr_` più 48 caratteri) nel corpo della risposta (l'HTML, le props di Inertia, il JSON), negli header e nei cookie,
 * anche cifrati.
 *
 *     Gettone::assenteDa($this->get('/dashboard'));
 */
final class Gettone
{
    private const FORMA = '/zr_[A-Za-z0-9]{48}/';

    public static function assenteDa(TestResponse $risposta): void
    {
        $dove = [];

        if (preg_match(self::FORMA, (string) $risposta->baseResponse->getContent()) === 1) {
            $dove[] = 'il corpo';
        }

        foreach ($risposta->baseResponse->headers->all() as $nome => $valori) {
            if ($nome !== 'set-cookie' && preg_match(self::FORMA, implode("\n", $valori)) === 1) {
                $dove[] = "l'header {$nome}";
            }
        }

        foreach ($risposta->baseResponse->headers->getCookies() as $cookie) {
            $valore = (string) $cookie->getValue();
            if (preg_match(self::FORMA, $valore.self::inChiaro($valore)) === 1) {
                $dove[] = "il cookie {$cookie->getName()}";
            }
        }

        Assert::assertSame([], $dove, 'Il gettone arriva al browser: '.implode(', ', $dove).'.');
    }

    /** Il valore di un cookie cifrato da Laravel, senza il prefisso; vuoto se non si decifra. */
    private static function inChiaro(string $valore): string
    {
        try {
            $testo = app('encrypter')->decrypt($valore, false);
        } catch (Throwable) {
            return '';
        }

        return is_string($testo) ? CookieValuePrefix::remove($testo) : '';
    }
}
