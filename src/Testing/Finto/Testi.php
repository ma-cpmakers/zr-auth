<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * I testi del backoffice finto (D20): le copie byte per byte di lang/ del backoffice, in resources/lang/, lette da un
 * traduttore e da una fabbrica di validatori suoi, staccati da quelli del frontend. Le stesse regole e gli stessi testi
 * danno gli stessi `errors`. La lingua di una richiesta si sceglie come App\Lingue, e il corpo di un errore si scrive come
 * RendeProblemi.
 */
final class Testi
{
    /** Le lingue di Zeiras (config/lingue.php del backoffice): quelle che parla, la predefinita e il ripiego. */
    public const LINGUE = ['it', 'en', 'es'];

    private const PREDEFINITA = 'it';

    private const RIPIEGO = 'en';

    /** La pagina di un codice d'errore: il `type` di un problema. */
    private const ERRORI = 'https://docs.zeiras.com/v1/errori/';

    /** I campi che TrimStrings di Laravel non tocca. */
    private const NON_SI_TAGLIANO = ['current_password', 'password', 'password_confirmation'];

    private readonly Translator $traduttore;

    private readonly Factory $validatori;

    public function __construct()
    {
        $this->traduttore = new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/resources/lang'), self::PREDEFINITA);
        // Un testo che una lingua non ha esce nel ripiego (fallback_locale del backoffice): in spagnolo, i messaggi della
        // validazione.
        $this->traduttore->setFallback(self::RIPIEGO);
        $this->validatori = new Factory($this->traduttore);
    }

    /** Da qui i testi sono in questa lingua: quella di Accept-Language, o della persona del gettone. */
    public function usa(string $lingua): void
    {
        $this->traduttore->setLocale($lingua);
    }

    /**
     * La lingua che una richiesta chiede con Accept-Language (App\Lingue::dellaRichiesta): la prima, nell'ordine di
     * preferenza, fra quelle di Zeiras; una lingua con q=0 mai; `*` vale la prima che l'header non nomina. Senza l'header
     * la predefinita; con un header che non ne chiede nessuna, il ripiego.
     */
    public static function dellaRichiesta(?string $acceptLanguage): string
    {
        $voci = [];
        foreach (AcceptHeader::fromString($acceptLanguage)->all() as $voce) {
            $voci[] = ['lingua' => strtolower(explode('-', $voce->getValue())[0]), 'q' => $voce->getQuality()];
        }

        if ($voci === []) {
            return self::PREDEFINITA;
        }

        $nominate = array_column($voci, 'lingua');

        foreach ($voci as ['lingua' => $lingua, 'q' => $q]) {
            if ($q <= 0) {
                continue;
            }

            if ($lingua !== '*') {
                if (in_array($lingua, self::LINGUE, true)) {
                    return $lingua;
                }

                continue;
            }

            $nonNominate = array_values(array_diff([self::PREDEFINITA, ...self::LINGUE], $nominate));

            if ($nonNominate !== []) {
                return $nonNominate[0];
            }
        }

        return self::RIPIEGO;
    }

    /**
     * Valida il corpo come il backoffice: prima TrimStrings (salvo le password) e ConvertEmptyStringsToNull, che lì girano
     * anche sui corpi JSON, poi le regole. Un valore rifiutato è dati_non_validi, col primo messaggio di ogni campo e il
     * suo pointer.
     *
     * @param  array<mixed>  $corpo
     * @param  array<string, mixed>  $regole
     * @return array<string, mixed> i valori validati
     */
    public function valida(array $corpo, array $regole): array
    {
        $validatore = $this->validatori->make(self::pulisci($corpo), $regole);

        if (! $validatore->fails()) {
            return $validatore->validated();
        }

        $errori = [];
        foreach ($validatore->errors()->messages() as $chiave => $messaggi) {
            $errori[] = ['detail' => $messaggi[0], 'pointer' => self::puntatore(explode('.', $chiave))];
        }

        throw new Problema('dati_non_validi', $errori);
    }

    /** Un testo di lang/ nella lingua di adesso, come __() nel backoffice. */
    public function testo(string $chiave): string
    {
        return (string) $this->traduttore->get($chiave);
    }

    /**
     * Il corpo di un errore di /v1 (RendeProblemi): type, title, status, detail, codice, e `errors` per dati_non_validi.
     * Il detail di troppe_richieste dice i secondi di Retry-After (D19).
     *
     * @return array<string, mixed>
     */
    public function problema(Problema $problema): array
    {
        $codice = $problema->codice;
        $secondi = (int) ($problema->header['Retry-After'] ?? 0);

        $corpo = [
            'type' => self::ERRORI.$codice,
            'title' => $this->testo("errori.{$codice}.title"),
            'status' => $problema->stato(),
            'detail' => $codice === 'troppe_richieste'
                ? $this->traduttore->choice("errori.{$codice}.detail", $secondi, ['secondi' => $secondi])
                : $this->testo("errori.{$codice}.detail"),
            'codice' => $codice,
        ];

        if ($problema->errori !== []) {
            $corpo['errors'] = $problema->errori;
        }

        return $corpo;
    }

    /**
     * TrimStrings e ConvertEmptyStringsToNull di Laravel: le chiavi sono a punti, e solo le password in cima non si
     * tagliano.
     *
     * @param  array<mixed>  $dati
     * @return array<mixed>
     */
    private static function pulisci(array $dati, string $prefisso = ''): array
    {
        foreach ($dati as $chiave => $valore) {
            if (is_array($valore)) {
                $dati[$chiave] = self::pulisci($valore, $prefisso.$chiave.'.');

                continue;
            }

            if (is_string($valore) && ! in_array($prefisso.$chiave, self::NON_SI_TAGLIANO, true)) {
                $valore = Str::trim($valore);
            }

            $dati[$chiave] = $valore === '' ? null : $valore;
        }

        return $dati;
    }

    /**
     * Il pointer di un valore del corpo (ErroreDiCampo::pointer): ogni segmento come in RFC 6901, poi codificato per un
     * frammento di URI.
     *
     * @param  list<string>  $segmenti
     */
    private static function puntatore(array $segmenti): string
    {
        return '#/'.implode('/', array_map(fn (string $segmento) => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segmento)), $segmenti));
    }
}
