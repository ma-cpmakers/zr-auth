<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Le prenotazioni di Bookings nel backoffice finto: le righe che il test mette con `prenotazione()`. Contano per il filtro `non_frequentante` di
 * `crm.persone.elenca` (#2711); `bookings.prenotazioni.elenca` le legge (#2712, nel finto da lì).
 */
trait BookingsPrenotazioni
{
    /** @var list<array{id: string, workspace: string, persona: string, calendario: string, pagina: string, servizio: string, inizio: CarbonImmutable, stato: string, creata: CarbonImmutable}> */
    private array $prenotazioniBookings = [];

    /** @var array<string, string> gli id di calendario e di pagina che il finto dà a un servizio e a un workspace, perché una stessa chiamata dia gli stessi */
    private array $idDiBookings = [];

    /**
     * Una prenotazione di Bookings di un contatto del CRM del finto: `$stato` è `confermata`, `svolta`, `non_presentato` o `annullata`; `$servizio` il nome della
     * voce del catalogo, e `$calendario` l'id di un calendario (senza, ne nasce uno per ogni servizio del workspace). Dura un'ora. Conta come lezione del
     * contatto, per `non_frequentante` di `crm.persone.elenca`, se è confermata o svolta e comincia da 14 giorni fa in poi (una nel futuro compresa).
     *
     * @param  string  $persona  l'id di un contatto del CRM del finto
     */
    public function prenotazione(string $persona, CarbonInterface|string $inizio, string $stato = 'confermata', string $servizio = 'Lezione di prova', ?string $calendario = null): self
    {
        $persona = strtolower($persona);
        $riga = $this->crm['persone'][$persona] ?? throw new InvalidArgumentException('Il contatto non è del finto: nasce con crm.persone.crea.');

        if (! in_array($stato, ['confermata', 'svolta', 'non_presentato', 'annullata'], true)) {
            throw new InvalidArgumentException("Lo stato «{$stato}» di una prenotazione è confermata, svolta, non_presentato o annullata.");
        }

        $workspace = $riga['workspace'];
        $this->prenotazioniBookings[] = [
            'id' => strtolower((string) Str::ulid()),
            'workspace' => $workspace,
            'persona' => $persona,
            'calendario' => $calendario !== null ? strtolower($calendario) : ($this->idDiBookings["calendario|{$workspace}|{$servizio}"] ??= strtolower((string) Str::ulid())),
            'pagina' => $this->idDiBookings["pagina|{$workspace}"] ??= strtolower((string) Str::ulid()),
            'servizio' => $servizio,
            'inizio' => CarbonImmutable::parse($inizio, 'UTC'),
            'stato' => $stato,
            'creata' => now()->toImmutable(),
        ];

        return $this;
    }
}
