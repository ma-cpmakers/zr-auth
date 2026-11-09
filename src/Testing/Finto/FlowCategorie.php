<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Support\Str;

/**
 * Le categorie di sistema di Finanze nel backoffice finto (`config/flow.php` e `App\Flow\Categorie` del backoffice, #3469, #3460): due livelli, uguali per ogni workspace, con la `chiave`
 * stabile, l'`id` fisso e le parole chiave che assegnano la categoria a una riga che nasce. Copia fedele: se il backoffice cambia l'elenco, si ricopia qui insieme a lui.
 */
final class FlowCategorie
{
    public const TUTTE = [
        ['id' => '01k91x19wz0e4g14ves37qm805', 'codice' => 'spesa', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['esselunga', 'coop', 'conad', 'carrefour', 'lidl', 'eurospin', 'supermercato', 'ipercoop', 'iperal', 'tigros', 'famila', 'despar', 'bennet', 'pam', 'panorama', 'penny', 'alimentari', 'macelleria', 'panificio', 'ortofrutta', 'pescheria', 'gastronomia']],
        ['id' => '01k9683r90qhq73qtz3jq3z19w', 'codice' => 'casa', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => []],
        ['id' => '01k9vvwsb1qj2bv16xqhpp0ktc', 'codice' => 'casa.luce', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['enel', 'enel energia', 'energia elettrica', 'edison', 'a2a', 'sorgenia', 'illumia', 'plenitude', 'eni plenitude', 'octopus energy', 'eon energia', 'fornitura luce', 'bolletta luce']],
        ['id' => '01k9318dvsr0k9n9tbs4x8ya9g', 'codice' => 'casa.gas', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['fornitura gas', 'gas naturale', 'bolletta gas', 'italgas', 'metano', 'gpl']],
        ['id' => '01k92qk7327pqswaaatpdv1fwm', 'codice' => 'casa.acqua', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['servizio idrico', 'acquedotto', 'bolletta acqua', 'publiacqua', 'smat', 'acea ato', 'acquedotto pugliese']],
        ['id' => '01k9y95nb01apkvyybgsz7gx8b', 'codice' => 'casa.mutuo', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['mutuo', 'rata mutuo', 'affitto', 'canone locazione', 'locazione', 'canone di locazione']],
        ['id' => '01k902zybprrgfqhbk3v88ywmr', 'codice' => 'casa.condominio', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['condominio', 'spese condominiali', 'amministratore condominio']],
        ['id' => '01k9hk09ed7r98nasrc1e3vajp', 'codice' => 'casa.internet', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['fibra', 'internet', 'vodafone', 'windtre', 'wind tre', 'fastweb', 'iliad', 'tiscali', 'kena mobile', 'very mobile', 'ho mobile', 'linkem', 'eolo', 'telecom italia', 'fattura tim', 'ricarica telefonica', 'telefono']],
        ['id' => '01k9tewtyg04jm39mcs95pzewa', 'codice' => 'casa.manutenzione', 'genitore' => 'casa', 'tipo' => 'spesa', 'parole_chiave' => ['idraulico', 'elettricista', 'caldaia', 'leroy merlin', 'bricoman', 'brico io', 'ferramenta', 'falegname', 'imbianchino', 'manutenzione casa']],
        ['id' => '01k9nqxgbtb83psnee4brgnbcb', 'codice' => 'trasporti', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => []],
        ['id' => '01k9ts7bxg0jx3wfr05f071p0c', 'codice' => 'trasporti.carburante', 'genitore' => 'trasporti', 'tipo' => 'spesa', 'parole_chiave' => ['carburante', 'benzina', 'gasolio', 'rifornimento', 'distributore', 'agip', 'eni station', 'q8', 'tamoil', 'shell', 'ip station', 'ip matic', 'total erg']],
        ['id' => '01k917wrg4zbb2jp3w16h0sq3q', 'codice' => 'trasporti.mezzi', 'genitore' => 'trasporti', 'tipo' => 'spesa', 'parole_chiave' => ['trenitalia', 'italo treno', 'trenord', 'flixbus', 'blablacar', 'atac', 'atm milano', 'biglietto bus', 'biglietto metro', 'biglietto treno', 'taxi', 'uber', 'freenow']],
        ['id' => '01k9g4sdnj7x6d1xs0bs5qj1va', 'codice' => 'trasporti.auto', 'genitore' => 'trasporti', 'tipo' => 'spesa', 'parole_chiave' => ['telepass', 'autostrade', 'pedaggio', 'parcheggio', 'autolavaggio', 'officina', 'meccanico', 'gommista', 'revisione auto', 'carrozzeria', 'ricambi auto', 'norauto', 'midas']],
        ['id' => '01k9err8q0abcx157h9hccrnnd', 'codice' => 'trasporti.assicurazione', 'genitore' => 'trasporti', 'tipo' => 'spesa', 'parole_chiave' => ['assicurazione', 'assicurazione auto', 'polizza', 'unipolsai', 'unipol', 'generali', 'allianz', 'axa assicurazioni', 'zurich', 'reale mutua', 'vittoria assicurazioni', 'genertel', 'verti', 'linear', 'direct line']],
        ['id' => '01k9ab2d09e9108xghsv6tygx6', 'codice' => 'trasporti.bollo', 'genitore' => 'trasporti', 'tipo' => 'spesa', 'parole_chiave' => ['bollo', 'bollo auto', 'tassa automobilistica']],
        ['id' => '01k95ccze2x0sd2b9fcvqzaskp', 'codice' => 'ristoranti', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['ristorante', 'pizzeria', 'trattoria', 'osteria', 'bar', 'pub', 'birreria', 'enoteca', 'caffetteria', 'pasticceria', 'gelateria', 'sushi', 'paninoteca', 'mcdonald', 'mcdonalds', 'kfc', 'starbucks', 'autogrill', 'glovo', 'deliveroo', 'justeat', 'burger king', 'just eat', 'uber eats', 'tavola calda']],
        ['id' => '01k9779q87dx6etgc5n1yqzyrb', 'codice' => 'salute', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['farmacia', 'parafarmacia', 'medico', 'dentista', 'ospedale', 'clinica', 'ticket sanitario', 'visita medica', 'visita specialistica', 'analisi', 'laboratorio analisi', 'oculista', 'ottica', 'fisioterapia', 'poliambulatorio', 'centro medico', 'studio medico', 'psicologo', 'azienda sanitaria', 'amplifon']],
        ['id' => '01k91gejgwde22b791h2b64kxm', 'codice' => 'figli', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['asilo', 'scuola', 'mensa scolastica', 'retta scolastica', 'retta asilo', 'libri scolastici', 'cartolibreria', 'pannolini', 'giocattoli', 'toys center', 'prenatal', 'centro estivo', 'doposcuola', 'scuolabus', 'tasse universitarie']],
        ['id' => '01k9516s9wfq8h5q1qxkbmvfw8', 'codice' => 'svago', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['cinema', 'teatro', 'concerto', 'ticketone', 'ticketmaster', 'museo', 'palestra', 'fitness', 'centro sportivo', 'piscina', 'decathlon', 'gardaland', 'bowling', 'libreria', 'feltrinelli', 'mondadori', 'playstation store', 'steam', 'nintendo']],
        ['id' => '01k9w2m1rcpyb4t2qg8j1pzg2f', 'codice' => 'abbonamenti', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['netflix', 'spotify', 'disney plus', 'disneyplus', 'amazon prime', 'prime video', 'dazn', 'sky italia', 'now tv', 'timvision', 'paramount', 'apple music', 'itunes', 'icloud', 'google one', 'youtube premium', 'microsoft 365', 'adobe', 'dropbox', 'chatgpt', 'openai', 'audible', 'playstation plus', 'xbox', 'nordvpn', '1password', 'patreon']],
        ['id' => '01k9rmr97g3gj7hff0j9g6kkcr', 'codice' => 'shopping', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['amazon', 'zalando', 'zara', 'primark', 'oviesse', 'shein', 'vinted', 'ebay', 'aliexpress', 'temu', 'ikea', 'mediaworld', 'unieuro', 'euronics', 'mondo convenienza', 'maisons du monde', 'abbigliamento', 'calzature']],
        ['id' => '01k9c2cx0yk915vsw2bfzabpvr', 'codice' => 'viaggi', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['booking', 'airbnb', 'expedia', 'ryanair', 'easyjet', 'wizz air', 'vueling', 'volotea', 'ita airways', 'lufthansa', 'trainline', 'hotel', 'albergo', 'hostel', 'agenzia viaggi', 'autonoleggio', 'hertz', 'sixt', 'europcar']],
        ['id' => '01k9zszacyee3qpqe3ace2rfj6', 'codice' => 'regali', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['regalo', 'regali', 'fioraio', 'fiori', 'interflora', 'gift card', 'lista nozze', 'bomboniera', 'donazione']],
        ['id' => '01k9x9366w045yrkwba63t8dca', 'codice' => 'animali', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['veterinario', 'clinica veterinaria', 'arcaplanet', 'zooplus', 'maxi zoo', 'toelettatura', 'pet shop', 'petstore', 'croccantini', 'mangime']],
        ['id' => '01k99gqg9xxvhv7v0g9s742rea', 'codice' => 'tasse', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => ['imposta', 'tassa', 'tari', 'imu', 'f24', 'agenzia delle entrate', 'agenzia entrate riscossione', 'inps', 'imposta di bollo', 'commissione', 'commissioni', 'canone conto', 'canone carta', 'spese tenuta conto', 'spese bancarie', 'interessi passivi']],
        ['id' => '01k9y39f0e7x2mnk5h14j39anq', 'codice' => 'altro', 'genitore' => null, 'tipo' => 'spesa', 'parole_chiave' => []],
        ['id' => '01k9pn695jg4kjvk9at7v6e86k', 'codice' => 'entrate', 'genitore' => null, 'tipo' => 'entrata', 'parole_chiave' => []],
        ['id' => '01k999d3cy52hz56zfx3gvvsmw', 'codice' => 'entrate.stipendio', 'genitore' => 'entrate', 'tipo' => 'entrata', 'parole_chiave' => ['stipendio', 'emolumenti', 'retribuzione', 'competenze', 'cedolino', 'salary']],
        ['id' => '01k96n2rc8dth1haf4aefhd25k', 'codice' => 'entrate.rimborsi', 'genitore' => 'entrate', 'tipo' => 'entrata', 'parole_chiave' => ['rimborso', 'rimborsi', 'rimborso spese', 'storno', 'rimborso 730']],
        ['id' => '01k9s8nhyn7j4ecxz37d7rkbsh', 'codice' => 'entrate.altro', 'genitore' => 'entrate', 'tipo' => 'entrata', 'parole_chiave' => ['pensione', 'assegno unico', 'interessi attivi', 'dividendo', 'dividendi', 'cedola', 'cashback', 'bonus']],
        ['id' => '01k9jmphavhjstnd48wk2k9qm4', 'codice' => 'trasferimenti', 'genitore' => null, 'tipo' => 'trasferimento', 'parole_chiave' => ['giroconto', 'giro conto', 'trasferimento tra conti', 'ricarica carta', 'ricarica postepay', 'ricarica prepagata', 'saldo carta', 'pagamento carta di credito']],
    ];

    /** @var list<array{testo: string, id: string}>|null */
    private static ?array $parole = null;

    /** @return list<string> gli id di tutte le categorie */
    public static function id(): array
    {
        return array_column(self::TUTTE, 'id');
    }

    /**
     * Lo schema Categoria: id, chiave, genitore_id e tipo, e nient'altro.
     *
     * @return list<array{id: string, chiave: string, genitore_id: string|null, tipo: string}>
     */
    public static function forme(): array
    {
        $perChiave = array_column(self::TUTTE, 'id', 'codice');

        return array_map(fn (array $categoria) => [
            'id' => $categoria['id'],
            'chiave' => $categoria['codice'],
            'genitore_id' => $categoria['genitore'] === null ? null : $perChiave[$categoria['genitore']],
            'tipo' => $categoria['tipo'],
        ], self::TUTTE);
    }

    /** Il testo ridotto a ciò che si confronta: minuscolo, senza accenti, ogni segno diverso da lettere e cifre è uno spazio (Categorie::normalizza). */
    public static function normalizza(string $testo): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($testo))));
    }

    /** La categoria di sistema di un testo già normalizzato: parole intere, vince la più lunga, a parità la prima dell'elenco (Categorie::assegna). */
    public static function assegna(string $normalizzato): ?string
    {
        $testo = " {$normalizzato} ";

        foreach (self::parole() as $parola) {
            if (str_contains($testo, $parola['testo'])) {
                return $parola['id'];
            }
        }

        return null;
    }

    /** @return list<array{testo: string, id: string}> */
    private static function parole(): array
    {
        if (self::$parole !== null) {
            return self::$parole;
        }

        $parole = [];

        foreach (self::TUTTE as $categoria) {
            foreach ($categoria['parole_chiave'] as $parola) {
                $parole[] = ['testo' => ' '.self::normalizza($parola).' ', 'id' => $categoria['id']];
            }
        }

        usort($parole, fn (array $a, array $b) => strlen($b['testo']) <=> strlen($a['testo']));

        return self::$parole = $parole;
    }
}
