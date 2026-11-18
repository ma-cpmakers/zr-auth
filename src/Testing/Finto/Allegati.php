<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * I file di una scheda nel backoffice finto (#2793): `board.schede.allegati.elenca`, `.crea`, `.elimina` e `.contenuto.mostra` (AllegatiController). I byte
 * stanno in memoria, per id.
 *
 * Come il vero di produzione, che non ha un antivirus (zr-pm 18/11, regola di Luciano): un file nasce `non_scansionato` e si scarica subito; nessuno lo ha controllato, e la
 * pagina non deve dire il contrario. Con `conAntivirus()` il finto fa come il vero con uno scanner configurato: il file nasce `in_scansione` (la scansione parte in coda) e si
 * scarica solo da `pulito` (409 `scansione_in_corso` prima, 409 `infetto` per un infetto). Il finto non ha la coda: la scansione la chiude chi prova, con `scansiona($id)` (o
 * `scansionaTutti()`), `pulito` o `infetto`. Il tipo lo
 * dicono i byte, non il nome (un eseguibile o uno script è 422 su `#/file`), 25 MB al più (413 `file_troppo_grande`), 50 file per scheda (409
 * `limite_raggiunto`). Un file `riservato` lo vedono solo l'autore, il proprietario e gli amministratori: per gli altri è assente dall'elenco e da
 * `n_allegati` e il suo contenuto è 404. `elimina` toglie il file dall'API (il vero lo archivia). Non ci sono la quota del workspace, i freni né il cifrario dei
 * riservati.
 */
trait Allegati
{
    private const ALLEGATI_BYTE_MASSIMI = 25 * 1024 * 1024;

    private const ALLEGATI_PER_SCHEDA = 50;

    /** C'è uno scanner? Senza (di serie, come in produzione) un file nasce `non_scansionato`. */
    private bool $allegatiConAntivirus = false;

    /** I tipi che si vedono in linea nel browser (Allegati::IN_LINEA del backoffice): ogni altro è sempre `attachment`. */
    private const ALLEGATI_IN_LINEA = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Come il backoffice con ZR_ALLEGATI_SCANNER_INDIRIZZO o _SOCKET: i file nuovi nascono `in_scansione` e si scaricano solo da `pulito`. */
    public function conAntivirus(): self
    {
        $this->allegatiConAntivirus = true;

        return $this;
    }

    /**
     * Lo stato della scansione di un file, come se la coda l'avesse finita: `pulito` (si scarica) o `infetto` (409 `infetto`). Il file deve esserci e
     * non essere tolto.
     */
    public function scansiona(string $allegato, string $esito = 'pulito'): self
    {
        if (! isset($this->allegati[$allegato]) || $this->allegati[$allegato]['tolto']) {
            throw new \InvalidArgumentException('Il file '.$allegato.' non c\'è nel backoffice finto.');
        }

        if ($this->allegati[$allegato]['stato'] === 'non_scansionato') {
            throw new \InvalidArgumentException('Senza antivirus un file non si scansiona: il file '.$allegato.' è `non_scansionato`; per provare la scansione chiama `conAntivirus()` prima di caricarlo.');
        }

        if (! in_array($esito, ['pulito', 'infetto'], true)) {
            throw new \InvalidArgumentException('Una scansione finisce `pulito` o `infetto`, non `'.$esito.'`.');
        }

        $this->allegati[$allegato]['stato'] = $esito;

        return $this;
    }

    /** Chiude la scansione di tutti i file ancora `in_scansione`, con lo stesso esito (`pulito` o `infetto`). */
    public function scansionaTutti(string $esito = 'pulito'): self
    {
        foreach ($this->allegati as $id => $allegato) {
            if ($allegato['stato'] === 'in_scansione' && ! $allegato['tolto']) {
                $this->scansiona($id, $esito);
            }
        }

        return $this;
    }

    /**
     * I file di una scheda che chi legge vede: non tolti, e un `riservato` solo all'autore, al proprietario e agli amministratori
     * (Allegati::visibili).
     *
     * @return list<array<string, mixed>>
     */
    private function allegatiVisibili(string $scheda, string $workspace, ?string $persona): array
    {
        $ruolo = $persona === null ? null : ($this->membri[$workspace][$persona] ?? null);

        return array_values(array_filter($this->allegati, fn (array $allegato) => $allegato['scheda'] === $scheda
            && ! $allegato['tolto']
            && ($allegato['visibilita'] === 'workspace' || $allegato['autore'] === $persona || in_array($ruolo, ['proprietario', 'amministratore'], true))));
    }

    /** `n_allegati` di una scheda: i file che chi legge vede. */
    private function nAllegatiDellaScheda(array $scheda): int
    {
        return count($this->allegatiVisibili($scheda['id'], $scheda['workspace'], $this->personaInCorso));
    }

    /**
     * Un file come lo dà Forme::allegato: `id`, `nome`, `dimensione`, `mime`, `autore` `{id, nome}`, `creato_il`, `stato`, `visibilita`.
     *
     * @param  array<string, mixed>  $allegato
     * @return array<string, mixed>
     */
    private function voceAllegato(array $allegato): array
    {
        return [
            'id' => $allegato['id'],
            'nome' => $allegato['nome'],
            'dimensione' => $allegato['dimensione'],
            'mime' => $allegato['mime'],
            'autore' => ['id' => $allegato['autore'], 'nome' => $this->persone[$allegato['autore']]['nome'] ?? ''],
            'creato_il' => self::iso($allegato['creato_il']),
            'stato' => $allegato['stato'],
            'visibilita' => $allegato['visibilita'],
        ];
    }

    /**
     * I 409 di stato di una scrittura sui file (SchedeController::listaAperta e l'archivio): una board chiusa è `board_chiusa`, una scheda archiviata
     * `scheda_archiviata`.
     *
     * @param  array<string, mixed>  $scheda
     */
    private function schedaAperta(array $scheda): void
    {
        if (($this->boards[$scheda['board']]['stato'] ?? 'attiva') !== 'attiva') {
            throw new Problema('board_chiusa');
        }

        if ($scheda['archiviata_il'] !== null) {
            throw new Problema('scheda_archiviata');
        }
    }

    /**
     * Il file del percorso: un file tolto, di un'altra scheda o un `riservato` che chi chiama non vede è 404.
     *
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     * @param  array<string, mixed>  $scheda
     * @return array<string, mixed>
     */
    private function allegatoDelPercorso(array $chi, array $scheda, string $allegato): array
    {
        foreach ($this->allegatiVisibili($scheda['id'], $chi['workspace'], $chi['persona']) as $visibile) {
            if ($visibile['id'] === $allegato) {
                return $visibile;
            }
        }

        throw new Problema('non_trovato');
    }

    /**
     * board.schede.allegati.elenca (AllegatiController::elenca): i file che chi chiama vede, per id, a cursore.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function elencaAllegati(Request $richiesta, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);
        $voci = array_map($this->voceAllegato(...), $this->allegatiVisibili($mia['id'], $chi['workspace'], $chi['persona']));
        usort($voci, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        return $this->pagina($richiesta, 'board.schede.allegati.elenca', 'id', $voci, fn (array $voce) => [$voce['id']], fn (string $id) => [$id]);
    }

    /**
     * board.schede.allegati.crea (AllegatiController::crea): un file per chiamata, `multipart/form-data` col campo `file` e, facoltativo, `visibilita`;
     * 201 col file `non_scansionato` (`in_scansione` con `conAntivirus()`). Nell'ordine del backoffice: il ruolo e l'app `pm` (403), la scheda (404), la Idempotency-Key, i 409 di stato
     * (`board_chiusa`, `scheda_archiviata`), il corpo (413 `file_troppo_grande`, poi 422 sul file e su `visibilita`), il tipo dai byte (422 su `#/file`),
     * 409 `limite_raggiunto` (50 file per scheda).
     *
     * @param  array<mixed>  $corpo  le parti del multipart, come le dà Http::fake
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaAllegato(Request $richiesta, array $corpo, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);
        [$campi, $file] = $this->partiDelMultipart($corpo);
        $impronta = ['file' => $file === null ? null : [hash('sha256', $file['byte']), $file['nome']], 'campi' => $campi];

        return $this->conIdempotenza($richiesta, 'board.schede.allegati.crea', $chi, ['corpo' => $impronta, 'percorso' => ['scheda' => $scheda]], function () use ($chi, $mia, $campi, $file) {
            $this->schedaAperta($mia);

            if ($file !== null && strlen($file['byte']) > self::ALLEGATI_BYTE_MASSIMI) {
                throw new Problema('file_troppo_grande');
            }

            $dati = $this->testi->validaCorpo([...$campi, ...($file === null ? [] : ['file' => 'file'])], [
                'file' => ['bail', 'required', 'string'],
                'visibilita' => ['bail', 'sometimes', 'string', Rule::in(['workspace', 'riservato'])],
            ]);
            $mime = self::mimeDaiByte($file['byte'] ?? '');

            if ($mime === null) {
                // Un eseguibile o uno script: il valore non è ammesso, con il testo e la regola del backoffice.
                $this->testi->validaCorpo(['file' => 'file'], ['file' => ['bail', 'required', Rule::in([])]]);
            }

            if (count(array_filter($this->allegati, fn (array $allegato) => $allegato['scheda'] === $mia['id'] && ! $allegato['tolto'])) >= self::ALLEGATI_PER_SCHEDA) {
                throw new Problema('limite_raggiunto');
            }

            $id = self::id();
            $this->allegati[$id] = [
                'id' => $id,
                'scheda' => $mia['id'],
                'workspace' => $chi['workspace'],
                'nome' => self::nomeDelFile((string) ($file['nome'] ?? '')),
                'dimensione' => strlen($file['byte'] ?? ''),
                'mime' => $mime,
                'byte' => $file['byte'] ?? '',
                'autore' => $chi['persona'],
                'creato_il' => now(),
                'stato' => $this->allegatiConAntivirus ? 'in_scansione' : 'non_scansionato',
                'visibilita' => $dati['visibilita'] ?? 'workspace',
                'tolto' => false,
            ];
            $this->tocca($mia['id']);

            return [201, ['data' => $this->voceAllegato($this->allegati[$id])], []];
        });
    }

    /**
     * board.schede.allegati.elimina (AllegatiController::elimina): toglie il file, 204. Dopo la scheda (404) i 409 di stato e il file (404).
     *
     * @return array{int, null}
     */
    private function eliminaAllegato(Request $richiesta, string $scheda, string $allegato): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);
        $this->schedaAperta($mia);
        $corrente = $this->allegatoDelPercorso($chi, $mia, $allegato);
        $this->allegati[$corrente['id']]['tolto'] = true;
        $this->tocca($mia['id']);

        return [204, null];
    }

    /**
     * board.schede.allegati.contenuto.mostra (AllegatiController::contenuto): i byte del file, `application/octet-stream` e `attachment` (un PNG, JPEG,
     * GIF o WebP pulito va in linea col suo tipo), `no-store, private` e `nosniff`. 409 `scansione_in_corso` finché il file è in scansione, 409
     * `infetto` per un infetto.
     *
     * @return array{int, string, array<string, string>}
     */
    private function mostraContenutoAllegato(Request $richiesta, string $scheda, string $allegato): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);
        $corrente = $this->allegatoDelPercorso($chi, $mia, $allegato);

        if ($corrente['stato'] === 'in_scansione') {
            throw new Problema('scansione_in_corso');
        }

        if ($corrente['stato'] === 'infetto') {
            throw new Problema('infetto');
        }

        $inLinea = in_array($corrente['mime'], self::ALLEGATI_IN_LINEA, true);
        $ascii = trim((string) preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/', '_', $corrente['nome']));

        return [200, $corrente['byte'], [
            'Content-Type' => $inLinea ? $corrente['mime'] : 'application/octet-stream',
            'Content-Length' => (string) $corrente['dimensione'],
            'Content-Disposition' => ($inLinea ? 'inline' : 'attachment').'; filename="'.($ascii === '' ? 'file' : $ascii).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]];
    }

    /**
     * Le parti di un corpo multipart: i campi di testo (senza `_method`) e il file del campo `file`.
     *
     * @param  array<mixed>  $corpo
     * @return array{0: array<string, string>, 1: array{nome: string, byte: string}|null}
     */
    private function partiDelMultipart(array $corpo): array
    {
        $campi = [];
        $file = null;

        foreach ($corpo as $parte) {
            if (! is_array($parte) || ! isset($parte['name'])) {
                continue;
            }

            $contenuto = $parte['contents'] ?? '';
            $byte = is_resource($contenuto) ? (string) stream_get_contents($contenuto) : (is_string($contenuto) ? $contenuto : '');

            if (isset($parte['filename'])) {
                if ($parte['name'] === 'file') {
                    $file = ['nome' => (string) $parte['filename'], 'byte' => $byte];
                } else {
                    // Un file in un altro campo: un campo estraneo, con lo stesso nome.
                    $campi[(string) $parte['name']] = $byte;
                }

                continue;
            }

            if ($parte['name'] !== '_method') {
                $campi[(string) $parte['name']] = $byte;
            }
        }

        return [$campi, $file];
    }

    /**
     * Il tipo dai byte, mai dal nome: `null` per un eseguibile o uno script (ELF, PE, Mach-O, shell, PHP, e simili), che il backoffice rifiuta.
     */
    private static function mimeDaiByte(string $byte): ?string
    {
        $testa = ltrim(substr($byte, 0, 512));

        foreach (["\x7fELF", 'MZ', "\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCA\xFE\xBA\xBE", '#!', '<?php', '<?='] as $firma) {
            if (str_starts_with($testa, $firma) || str_starts_with($byte, $firma)) {
                return null;
            }
        }

        if (str_starts_with($byte, '%PDF-')) {
            return 'application/pdf';
        }

        if (str_starts_with($byte, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($byte, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($byte, 'GIF87a') || str_starts_with($byte, 'GIF89a')) {
            return 'image/gif';
        }

        if (str_starts_with($byte, 'RIFF') && substr($byte, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (function_exists('finfo_buffer')) {
            $tipo = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $byte);

            if (is_string($tipo) && $tipo !== '') {
                return $tipo;
            }
        }

        return 'application/octet-stream';
    }

    /** Il nome che la persona ha dato al file: senza il percorso del suo computer né caratteri di controllo, al più 255 caratteri; `file` se non resta niente. */
    private static function nomeDelFile(string $nome): string
    {
        $nome = (string) preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $nome)));
        $nome = trim(mb_substr($nome, 0, 255));

        return $nome === '' ? 'file' : $nome;
    }
}
