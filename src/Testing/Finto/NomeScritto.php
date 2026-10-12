<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La regola del backoffice (App\Rules\NomeScritto), perché il finto risponda come lui (#1880): un nome, un cognome o un titolo
 * scritto da un utente non porta caratteri di controllo (U+0000-001F, U+007F-009F, e i separatori U+2028 e U+2029), di
 * direzione (U+202A-202E, U+2066-2069) né a larghezza zero (U+200B, U+2060, U+FEFF), e dev'essere UTF-8 valido; U+200C, U+200D
 * e i segni di direzione deboli (U+200E, U+200F, U+061C) restano. Il nome da provider si pulisce (`pulisci`).
 */
final class NomeScritto implements ValidationRule
{
    /** I caratteri rifiutati, come classe di un'espressione regolare con il modificatore `u`. */
    public const VIETATI = '\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{2060}\x{FEFF}';

    /** I caratteri di direzione e quelli a larghezza zero: in un nome da provider si tolgono. */
    private const INVISIBILI = '\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{2060}\x{FEFF}';

    /**
     * Il nome che arriva da un provider (Google, LinkedIn, Facebook), pulito come dice la Decisione 11676: via i caratteri di
     * direzione e a larghezza zero, ogni carattere di controllo diventa uno spazio, gli spazi si comprimono, poi trim e taglio
     * al tetto. Null se non resta niente: chi lo usa ripiega sulla parte dell'email prima della @. Il nome non lo scrive la
     * persona, e un ingresso non deve fallire per un carattere che non vede.
     */
    public static function pulisci(string $nome, int $massimo): ?string
    {
        $nome = (string) preg_replace('/['.self::INVISIBILI.']/u', '', $nome);
        $nome = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', ' ', $nome);
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        $nome = trim(mb_substr($nome, 0, $massimo));

        return $nome === '' ? null : $nome;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Un testo che non è UTF-8 valido fa tornare false a preg_match: si rifiuta anche lui.
        if (is_string($value) && preg_match('/['.self::VIETATI.']/u', $value) !== 0) {
            $fail('regole.carattere_non_ammesso')->translate();
        }
    }
}
