<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;

/**
 * Il modulo Contatti di zeiras.com del finto (#3439): `contatti.crea`, senza gettone, come ContattiController del backoffice. Il finto non manda email: i messaggi
 * che il backoffice recapiterebbe alla casella dei contatti li tiene in memoria, e un test li legge con `messaggiDeiContatti()`. Stessa risposta (`202` con
 * `{data: {accettato: true}}`) per un invio riuscito e per uno scartato (trappola `sito_web` valorizzata, o `compilato_in_ms` sotto 3000, che non conta nei freni); freno per email (3 in
 * un'ora, mai per IP) e tetto in tutto al giorno (200).
 */
trait Contatti
{
    /** @var list<array{nome: string, email: string, azienda: ?string, argomento: ?int, messaggio: string, lingua: ?string}> */
    private array $messaggiDeiContatti = [];

    /**
     * I messaggi che il finto ha «recapitato» alla casella dei contatti, dal primo.
     *
     * @return list<array{nome: string, email: string, azienda: ?string, argomento: ?int, messaggio: string, lingua: ?string}>
     */
    public function messaggiDeiContatti(): array
    {
        return $this->messaggiDeiContatti;
    }

    /**
     * contatti.crea: valida il corpo come il backoffice, scarta in silenzio la trappola e il compilato in fretta, poi conta i freni e «recapita».
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array{data: array{accettato: true}}}
     */
    private function mandaUnMessaggioDeiContatti(array $corpo): array
    {
        $regole = [
            'nome' => ['bail', 'required', 'string', 'max:200'],
            'email' => ['bail', 'required', 'string', 'email', 'max:254'],
            'azienda' => ['bail', 'sometimes', 'nullable', 'string', 'max:200'],
            'argomento' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:0,4'],
            'messaggio' => ['bail', 'required', 'string', 'max:5000'],
            'lingua' => ['bail', 'sometimes', 'nullable', 'string', 'in:it,en,es'],
            'sito_web' => ['bail', 'sometimes', 'nullable', 'string', 'max:1000'],
            'compilato_in_ms' => ['bail', 'required', 'integer:strict', 'min:0'],
        ];
        // Come Corpo::valida del backoffice: un campo che il contratto non dichiara è 422 `campo_estraneo` sul suo pointer.
        $estranei = array_diff(array_map('strval', array_keys($corpo)), array_keys($regole));
        $campi = $this->testi->valida($corpo, $regole + array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $attributo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $attributo]);
        }]));

        if (($campi['sito_web'] ?? null) !== null || $campi['compilato_in_ms'] < 3000) {
            return [202, ['data' => ['accettato' => true]]];
        }

        $this->frena('contatti:email:'.hash('sha256', mb_strtolower((string) $campi['email'])), 3, 3600);
        $this->frena('contatti:totale', 200, 86400);

        $this->messaggiDeiContatti[] = [
            'nome' => (string) $campi['nome'],
            'email' => mb_strtolower((string) $campi['email']),
            'azienda' => $campi['azienda'] ?? null,
            'argomento' => $campi['argomento'] ?? null,
            'messaggio' => (string) $campi['messaggio'],
            'lingua' => $campi['lingua'] ?? null,
        ];

        return [202, ['data' => ['accettato' => true]]];
    }
}
