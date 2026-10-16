<?php

namespace Zeiras\Auth;

use Generator;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Zeiras\Auth\Errori\BackofficeNonRisponde;

/**
 * Un file che il backoffice manda a flusso (`Api::scarica`, #1906): l'esportazione CSV del CRM. Il corpo non è mai tutto in memoria:
 * `blocchi()` lo legge a pezzi, entro `zr-auth.scarico` secondi, e `comeRisposta()` lo passa al browser a pezzi, con le intestazioni
 * che un file da salvare vuole. Il tipo è uno di `TIPI` (un elenco chiuso: `text/csv` e `application/zip`) e il nome del file è ripulito: mai un
 * percorso, un carattere di controllo, un a capo.
 *
 *     return Api::workspace()->scarica('/v1/crm/persone/esportazione', ['stato' => 'lead'])->comeRisposta();
 */
final class Scaricamento
{
    /** I tipi che si scaricano. Crescere questo elenco è una decisione: ogni tipo è un file che il browser potrebbe eseguire. */
    public const TIPI = ['text/csv', 'application/zip'];

    /** Il nome che resta se il backoffice non ne dà uno che vale (per un CSV; vedi `NOMI_PREDEFINITI` per gli altri tipi). */
    public const NOME_PREDEFINITO = 'scaricamento.csv';

    /** Il nome che resta, per tipo, se il backoffice non ne dà uno che vale. */
    public const NOMI_PREDEFINITI = ['text/csv' => 'scaricamento.csv', 'application/zip' => 'zeiras-i-miei-dati.zip'];

    /** L'estensione che un nome di file ha sempre, per tipo. */
    private const ESTENSIONI = ['text/csv' => 'csv', 'application/zip' => 'zip'];

    /** Le lettere di un nome di file: il resto diventa un trattino. */
    private const NOME_MASSIMO = 100;

    private function __construct(
        public readonly string $tipo,
        public readonly string $nome,
        private readonly StreamInterface $flusso,
        private readonly int $secondi,
    ) {}

    /** Lo scaricamento di una risposta 200, o BackofficeNonRisponde se il suo tipo non è un file ammesso. */
    public static function da(Response $risposta): self
    {
        $tipo = strtolower(trim(explode(';', (string) $risposta->header('Content-Type'))[0]));

        if (! in_array($tipo, self::TIPI, true)) {
            throw new BackofficeNonRisponde('Il backoffice ha risposto con un tipo che non è un file ammesso ('.implode(', ', self::TIPI).').');
        }

        return new self($tipo, self::nomeDi((string) $risposta->header('Content-Disposition'), $tipo), $risposta->toPsrResponse()->getBody(), max(1, (int) config('zr-auth.scarico')));
    }

    /**
     * Il nome del file da `Content-Disposition`, ripulito: senza cartelle, solo lettere, cifre, punto, trattino e trattino basso, mai
     * più di 100 caratteri, sempre con l'estensione del tipo (`.csv`, `.zip`). Senza un nome che valga, quello predefinito del tipo.
     */
    public static function nomeDi(string $intestazione, string $tipo = 'text/csv'): string
    {
        $predefinito = self::NOMI_PREDEFINITI[$tipo] ?? self::NOME_PREDEFINITO;
        $estensione = self::ESTENSIONI[$tipo] ?? 'csv';

        if (preg_match('/filename\*?=\s*(?:"([^"]*)"|([^;]*))/i', $intestazione, $parti) !== 1) {
            return $predefinito;
        }

        $grezzo = ($parti[1] ?? '') !== '' ? $parti[1] : trim($parti[2] ?? '');
        $nome = basename(str_replace('\\', '/', $grezzo));
        $nome = preg_replace('/[\x00-\x1F\x7F]/u', '', $nome) ?? '';
        $nome = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $nome), '.-');
        $nome = substr($nome, 0, self::NOME_MASSIMO);

        if ($nome === '') {
            return $predefinito;
        }

        return preg_match('/\.'.$estensione.'$/i', $nome) === 1 ? $nome : $nome.'.'.$estensione;
    }

    /**
     * Il corpo a pezzi di `$byte`. Oltre `zr-auth.scarico` secondi dall'inizio della lettura, BackofficeNonRisponde; alla fine, o se chi
     * legge si ferma, il flusso si chiude.
     *
     * @return Generator<int, string>
     */
    public function blocchi(int $byte = 8192): Generator
    {
        $inizio = microtime(true);

        try {
            while (true) {
                try {
                    if ($this->flusso->eof()) {
                        return;
                    }

                    if (microtime(true) - $inizio > $this->secondi) {
                        throw new BackofficeNonRisponde("Lo scaricamento ha superato i {$this->secondi} secondi.");
                    }

                    $blocco = $this->flusso->read(max(1, $byte));
                } catch (RuntimeException $e) {
                    // Una lettura che scade o una connessione che cade a metà (Guzzle: TimeoutException, «Unable to read from stream»).
                    throw $e instanceof BackofficeNonRisponde ? $e : new BackofficeNonRisponde('Il backoffice ha interrotto lo scaricamento.', previous: $e);
                }

                if ($blocco !== '') {
                    yield $blocco;
                }
            }
        } finally {
            $this->flusso->close();
        }
    }

    /**
     * Le intestazioni che il browser vuole per salvare il file: il tipo con la sua codifica, il nome ripulito, `nosniff` (il browser non
     * indovina un altro tipo) e `no-store` (sono dati personali in blocco).
     *
     * @return array<string, string>
     */
    public function intestazioni(): array
    {
        return [
            'Content-Type' => str_starts_with($this->tipo, 'text/') ? $this->tipo.'; charset=utf-8' : $this->tipo,
            'Content-Disposition' => 'attachment; filename="'.$this->nome.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ];
    }

    /** Il file passato al browser a pezzi, con `intestazioni()`. */
    public function comeRisposta(): StreamedResponse
    {
        return new StreamedResponse(function () {
            try {
                foreach ($this->blocchi() as $blocco) {
                    echo $blocco;
                }
            } catch (BackofficeNonRisponde $e) {
                // Dopo il primo byte gli header sono partiti: rilanciare farebbe accodare al file la pagina d'errore di Laravel.
                // Il file finisce lì, e l'errore resta nel log.
                report($e);
            }
        }, 200, $this->intestazioni());
    }
}
