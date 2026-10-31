<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;

/**
 * Le funzioni di Board del finto (#2815, board.funzioni.*): edilizia, digitale e lean, nell'ordine fisso del backoffice, con lo stato del
 * workspace. Senza una scelta `digitale` è accesa per tutti, `edilizia` solo con una cartella viva di quel settore, `lean` mai; dopo la
 * scelta vale il valore scritto. Spegnere non tocca cartelle né board. `funzione_attiva` delle board è sempre `true`: il finto non modella
 * board.board.modifica.
 */
trait BoardFunzioni
{
    /** @var array<string, array{metodi: list<string>, disponibile: bool}> */
    private const FUNZIONI_BOARD = [
        'edilizia' => ['metodi' => ['waterfall'], 'disponibile' => true],
        'digitale' => ['metodi' => ['scrum', 'scrumban'], 'disponibile' => true],
        'lean' => ['metodi' => [], 'disponibile' => false],
    ];

    /** @var array<string, array<string, bool>> le funzioni accese o spente da ogni workspace, per chiave */
    private array $boardFunzioniScelte = [];

    /**
     * board.funzioni.elenca (BoardFunzioniController::elenca): le funzioni nell'ordine fisso, ai tre ruoli.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaFunzioniBoard(Request $richiesta): array
    {
        $chi = $this->conPm($richiesta);

        return [200, ['data' => $this->funzioniBoard($chi['workspace'])]];
    }

    /**
     * board.funzioni.modifica (BoardFunzioniController::modifica): accende o spegne una funzione. Una che non c'è è 404 prima del corpo; lo stesso
     * valore di adesso è 200 senza scrivere; una non `disponibile` (lean) non si accende: 409 `funzione_vincolata`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaFunzioneBoard(Request $richiesta, array $corpo, string $funzione): array
    {
        // I ruoli prima dell'app, come nelle rotte del backoffice (`workspace:proprietario,amministratore`, poi `app:pm`).
        $chi = $this->conWorkspace($richiesta, ['proprietario', 'amministratore']);

        if (! $this->appAttiva($chi['workspace'], 'pm')) {
            throw new Problema('app_non_attiva');
        }

        if (! array_key_exists($funzione, self::FUNZIONI_BOARD)) {
            throw new Problema('non_trovato');
        }

        $campi = $this->modificaCrm($corpo, ['attivo' => ['required', 'boolean:strict']]);
        $questa = array_column($this->funzioniBoard($chi['workspace']), null, 'chiave')[$funzione];
        $voglio = (bool) $campi['attivo'];

        if ($voglio === $questa['attivo']) {
            return [200, ['data' => $questa]];
        }

        if (! $questa['disponibile']) {
            throw new Problema('funzione_vincolata');
        }

        $this->boardFunzioniScelte[$chi['workspace']][$funzione] = $voglio;

        return [200, ['data' => array_merge($questa, ['attivo' => $voglio])]];
    }

    /** @return list<array{chiave: string, metodi: list<string>, disponibile: bool, attivo: bool, dati_presenti: bool}> */
    private function funzioniBoard(string $workspace): array
    {
        $settori = [];

        foreach ($this->cartelle as $cartella) {
            if ($cartella['workspace'] === $workspace && $cartella['stato'] !== 'cestino' && $cartella['settore'] !== null) {
                $settori[$cartella['settore']] = true;
            }
        }

        $funzioni = [];

        foreach (self::FUNZIONI_BOARD as $chiave => $funzione) {
            $dati = isset($settori[$chiave]);
            $predefinita = match ($chiave) {
                'digitale' => true,
                'edilizia' => $dati,
                default => false,
            };

            $funzioni[] = [
                'chiave' => $chiave,
                'metodi' => $funzione['metodi'],
                'disponibile' => $funzione['disponibile'],
                'attivo' => $funzione['disponibile'] && ($this->boardFunzioniScelte[$workspace][$chiave] ?? $predefinita),
                'dati_presenti' => $dati,
            ];
        }

        return $funzioni;
    }
}
