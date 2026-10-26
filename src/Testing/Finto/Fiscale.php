<?php

namespace Zeiras\Auth\Testing\Finto;

/**
 * I dati fiscali di un profilo di fatturazione nel finto (copia di App\Crm\Fiscale del backoffice, #2751): normalizzazione e controllo del codice fiscale e della partita IVA per paese. Sono
 * funzioni pure, senza database né lingua: `null` vuol dire «scritto male». Il codice fiscale italiano ha 16 caratteri con la cifra di
 * controllo (anche con l'omocodia) o 11 cifre con quella della partita IVA; lo spagnolo è un NIF, un NIE o un CIF con la lettera o la
 * cifra giusta; di un altro paese è di 4-20 lettere maiuscole e cifre. La partita IVA di un paese dell'UE ha il prefisso del paese (`EL` per
 * la Grecia; `IT` può mancare) e il formato di quel paese, e quella italiana e quella spagnola anche la cifra di controllo; di un paese
 * fuori dall'UE è di 4-20 lettere maiuscole e cifre. Il numero non si verifica presso il VIES (#2754).
 */
final class Fiscale
{
    /** I paesi dell'UE (ISO 3166-1 alpha-2). */
    public const UE = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    /** Il formato del numero di un paese dell'UE, dopo il prefisso. */
    private const FORMATI = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^[01]\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'HR' => '/^\d{11}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'DE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-Z]{1,2}|\d[A-Z+*]\d{5}[A-Z])$/',
        'LV' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SK' => '/^\d{10}$/',
        'SI' => '/^\d{8}$/',
        'SE' => '/^\d{12}$/',
    ];

    /** Un paese dell'UE? */
    public static function ue(string $paese): bool
    {
        return in_array($paese, self::UE, true);
    }

    /** Il prefisso della partita IVA di un paese dell'UE: il suo codice, tranne la Grecia (`EL`). */
    public static function prefisso(string $paese): string
    {
        return $paese === 'GR' ? 'EL' : $paese;
    }

    /** Il valore senza spazi né trattini e in maiuscole. */
    public static function compatta(string $valore): string
    {
        return mb_strtoupper((string) preg_replace('/[\s-]+/u', '', $valore));
    }

    /** Il codice fiscale normalizzato come si salva, o `null` se non regge per il paese. */
    public static function codiceFiscale(string $paese, string $valore): ?string
    {
        $codice = self::compatta($valore);

        $valido = match ($paese) {
            'IT' => self::codiceFiscaleItaliano($codice),
            'ES' => self::documentoSpagnolo($codice),
            default => preg_match('/^[A-Z0-9]{4,20}$/', $codice) === 1,
        };

        return $valido ? $codice : null;
    }

    /** La partita IVA normalizzata come si salva (col prefisso del paese per l'UE), o `null` se non regge per il paese. */
    public static function partitaIva(string $paese, string $valore): ?string
    {
        $numero = self::compatta($valore);

        if (! self::ue($paese)) {
            return preg_match('/^[A-Z0-9]{4,20}$/', $numero) === 1 ? $numero : null;
        }

        $prefisso = self::prefisso($paese);

        if (str_starts_with($numero, $prefisso)) {
            $corpo = substr($numero, strlen($prefisso));
        } elseif ($paese === 'IT') {
            $corpo = $numero;
        } else {
            return null;
        }

        $valido = match ($paese) {
            'IT' => self::cifreDellaPartitaIva($corpo),
            'ES' => self::documentoSpagnolo($corpo),
            default => preg_match(self::FORMATI[$prefisso], $corpo) === 1,
        };

        return $valido ? $prefisso.$corpo : null;
    }

    /**
     * Il codice fiscale da cercare: normalizzato come alla creazione, senza controllarne la forma (uno scritto male non trova niente).
     */
    public static function codiceFiscaleDaCercare(string $valore): string
    {
        return self::compatta($valore);
    }

    /** La partita IVA da cercare: normalizzata, e di 11 cifre senza prefisso vale come italiana (`IT` davanti). */
    public static function partitaIvaDaCercare(string $valore): string
    {
        $numero = self::compatta($valore);

        return preg_match('/^\d{11}$/', $numero) === 1 ? 'IT'.$numero : $numero;
    }

    /** 11 cifre con la cifra di controllo della partita IVA italiana, e un progressivo che non sia tutto zeri. */
    private static function cifreDellaPartitaIva(string $numero): bool
    {
        if (preg_match('/^\d{11}$/', $numero) !== 1 || substr($numero, 0, 7) === '0000000') {
            return false;
        }

        $somma = 0;

        for ($i = 0; $i < 10; $i++) {
            $cifra = (int) $numero[$i];

            if ($i % 2 === 1) {
                $cifra *= 2;
                $cifra = $cifra > 9 ? $cifra - 9 : $cifra;
            }

            $somma += $cifra;
        }

        return (10 - $somma % 10) % 10 === (int) $numero[10];
    }

    /** Il codice fiscale italiano: 16 caratteri con la cifra di controllo (l'omocodia vale), oppure 11 cifre di una partita IVA. */
    private static function codiceFiscaleItaliano(string $codice): bool
    {
        if (preg_match('/^\d{11}$/', $codice) === 1) {
            return self::cifreDellaPartitaIva($codice);
        }

        if (preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $codice) !== 1) {
            return false;
        }

        $dispari = [1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23];
        $somma = 0;

        for ($i = 0; $i < 15; $i++) {
            $carattere = $codice[$i];
            $valore = ctype_digit($carattere) ? (int) $carattere : ord($carattere) - 65;
            $somma += $i % 2 === 0 ? $dispari[$valore] : $valore;
        }

        return $codice[15] === chr(65 + $somma % 26);
    }

    /** Un NIF (DNI, K/L/M), un NIE o un CIF spagnolo di nove caratteri, con la lettera o la cifra di controllo giusta. */
    private static function documentoSpagnolo(string $codice): bool
    {
        $lettere = 'TRWAGMYFPDXBNJZSQVHLCKE';

        if (preg_match('/^\d{8}[A-Z]$/', $codice) === 1) {
            return $codice[8] === $lettere[(int) substr($codice, 0, 8) % 23];
        }

        if (preg_match('/^[KLM]\d{7}[A-Z]$/', $codice) === 1) {
            return $codice[8] === $lettere[(int) substr($codice, 1, 7) % 23];
        }

        if (preg_match('/^[XYZ]\d{7}[A-Z]$/', $codice) === 1) {
            $cifra = (string) strpos('XYZ', $codice[0]);

            return $codice[8] === $lettere[(int) ($cifra.substr($codice, 1, 7)) % 23];
        }

        if (preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $codice) !== 1) {
            return false;
        }

        $pari = (int) $codice[2] + (int) $codice[4] + (int) $codice[6];
        $dispari = 0;

        foreach ([1, 3, 5, 7] as $posto) {
            $doppio = (int) $codice[$posto] * 2;
            $dispari += intdiv($doppio, 10) + $doppio % 10;
        }

        $controllo = (10 - ($pari + $dispari) % 10) % 10;
        $ultimo = $codice[8];

        if (str_contains('NPQRSW', $codice[0])) {
            return $ultimo === 'JABCDEFGHI'[$controllo];
        }

        if (str_contains('ABEH', $codice[0])) {
            return $ultimo === (string) $controllo;
        }

        return $ultimo === (string) $controllo || $ultimo === 'JABCDEFGHI'[$controllo];
    }
}
