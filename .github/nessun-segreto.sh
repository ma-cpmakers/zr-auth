#!/usr/bin/env bash
# Nessun segreto nel repo: il pacchetto è pubblico, e un segreto qui dentro è pubblicato. Gira nella radice del repo, sui
# file che git conosce, e lo lancia la CI; esce 1 e dice dove (il nome della variabile, mai il valore), e 2 se non ha
# potuto leggere tutto. Grezzo apposta: un falso allarme si corregge scrivendo diverso, un segreto pubblicato si revoca.
# Cosa vede e cosa no: il README, «Cosa vede la guardia, e cosa no». Le sue prove: .github/prova-nessun-segreto.sh.
set -uo pipefail

trovato=0
rotto=0

# L'uscita di un controllo: 0 = niente; 1 = trovato, e lo si dice; ogni altra vuol dire che il controllo non ha letto
# tutto (un file che non si apre o non si legge, perl o git che falliscono): la guardia è rossa anche così, e lo dice.
esito() {
    case "$1" in
        0) ;;
        1) echo "$2"; trovato=1 ;;
        *) echo "$2: il controllo non ha letto tutto (uscita $1)"; rotto=1 ;;
    esac
}

# L'uscita di grep e di git grep è al contrario: 0 = trovato, 1 = niente, ogni altra = non ha letto tutto.
esito_grep() {
    case "$1" in
        0) esito 1 "$2" ;;
        1) esito 0 "$2" ;;
        *) esito "$1" "$2" ;;
    esac
}

# I file che git conosce, chiesti una volta sola e dalla radice: fuori da un repo, o con git che fallisce, la guardia non
# ha niente da leggere, e non dice «niente trovato».
radice=$(git rev-parse --show-toplevel) || { echo "la guardia non è in un repo git: non ha letto niente"; exit 2; }
cd "$radice" || { echo "la radice del repo non si apre: non ha letto niente"; exit 2; }
lista=$(mktemp) || { echo "il file temporaneo non si crea: non ha letto niente"; exit 2; }
trap 'rm -f "$lista"' EXIT
git ls-files -z > "$lista" || { echo "git non elenca i file del repo: non ha letto niente"; exit 2; }

# I file che non ci devono essere: un .env (anche .env.example), una chiave, un certificato, un archivio di chiavi (.p12,
# .pfx, .jks, .keystore), una chiave PuTTY (.ppk), le credenziali di Composer (auth.json), e un archivio compresso, che
# la guardia non sa leggere. L'esito è quello di grep, non quello della stampa: un tr che fallisce non lo cambia.
sensibili=$(grep -zE '(^|/)\.env($|\.)|\.(key|pem|p12|pfx|jks|keystore|ppk)$|(^|/)auth\.json$|\.(zip|tar|gz|tgz|bz2|xz|7z|rar|jar|phar)$' "$lista" | tr '\0' '\n'; exit "${PIPESTATUS[0]}")
uscita=$?
[ -n "$sensibili" ] && printf '%s\n' "$sensibili"
esito_grep "$uscita" "file sensibile nel repo"

# Una chiave privata, in qualunque file di testo: PEM e OpenSSH (BEGIN … PRIVATE KEY) e PuTTY.
git grep -lE 'BEGIN [A-Z ]*PRIVATE KEY|PuTTY-User-Key-File[-]' -- .
esito_grep $? "chiave privata nel repo"

# Il nome di una variabile segreta, in inglese o in italiano, in maiuscolo o in minuscolo; PASS solo come parola intera
# del nome (DB_PASS sì, BYPASS e PASSO no). NOMI_CITATI è lo stesso fra virgolette, dove un nome può avere anche - e .
export NOMI='(?i:[A-Z0-9_]*(?:SECRET|KEY|TOKEN|PASSWORD|PASSWD|PWD|(?<![A-Z0-9])PASS(?![A-Z0-9])|SEGRETO|SEGRETI|CHIAVE|CHIAVI|GETTONE|GETTONI|WEBHOOK|DSN|CREDENTIAL|CREDENZIAL)[A-Z0-9_]*)'
export NOMI_CITATI='(?i:[A-Z0-9_.-]*(?:SECRET|KEY|TOKEN|PASSWORD|PASSWD|PWD|(?<![A-Z0-9])PASS(?![A-Z0-9])|SEGRETO|SEGRETI|CHIAVE|CHIAVI|GETTONE|GETTONI|WEBHOOK|DSN|CREDENTIAL|CREDENZIAL)[A-Z0-9_.-]*)'

# Un segnaposto, che passa solo come valore intero: <…>, $VAR, ${VAR}, ${{ secrets.… }}, {{ … }}, …, ..., null, ~
# (NUDO); lo stesso fra virgolette, o due virgolette vuote (CITATO). Dove finisce il valore lo dice ogni forma (sotto): in
# testa a un valore (~k3y, null-k3y, ''k3y, `k3y`, #k3y, $k3y, {k3y}) un segnaposto non lo è, è il valore.
export NUDO='(?: <[^<>\n]*> | \$[A-Z_][A-Z0-9_]* | \$\{[A-Z_][A-Z0-9_]*\} | \$\{\{[^{}\n]*\}\} | \{\{[^{}\n]*\}\} | … | \.\.\. | (?i:null) | ~ )'
export CITATO="(?: ([\"\\x27]) (?:$NUDO)? \\g{-1} )"

# I controlli in perl ricevono i nomi dei file separati da NUL, e li aprono con l'open a tre argomenti: un nome con spazi
# ai lati o con < > | resta il nome di un file (con l'open a due argomenti, un nome che finisce con | è un comando). Un file
# che non si apre, o che si apre e non si legge (una cartella, come un sottomodulo), fa uscire 2. In slurp la prima lettura
# di un file vuoto è '': undef vuol dire che la lettura è fallita.
export LEGGI='
    sub leggi {
        my ($file) = @_;
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; return undef };
        my $testo = do { local $/; <$fh> };
        defined $testo or do { warn "non si legge: $file\n"; return undef };
        close $fh;
        return $testo;
    }
    sub riga { my ($testo, $dove) = @_; return 1 + (substr($testo, 0, $dove) =~ tr/\n//) }'

# Un valore di riserva per una variabile segreta, nel PHP: env('…', valore), env('…') ?: valore, env('…') ?? valore,
# anche su più righe; lo stesso con getenv() e Env::get(); $_ENV['…'] e $_SERVER['…'] seguiti da ?: o ??.
perl -e '
    eval $ENV{LEGGI}; die $@ if $@;
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        next unless $file =~ /\.php$/;
        my $testo = leggi($file);
        defined $testo or do { $rotto = 1; next };
        while ($testo =~ /(?:\b(?:env|getenv|Env::get)\s*\(\s*(["\x27])($nomi)\1\s*(?:,|\)\s*\?\s*[?:])|\$_(?:ENV|SERVER)\s*\[\s*(["\x27])($nomi)\3\s*\]\s*\?\s*[?:])/gi) {
            printf "%s:%d: %s\n", $file, riga($testo, $-[0]), $2 // $4;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)' < "$lista"
esito $? "un segreto come valore di riserva di env()"

# Un valore per una variabile segreta nella configurazione di PHPUnit (phpunit.xml, phpunit.xml.dist, phpunit.dist.xml):
# <env name="…" value="…"/>, e così <server>, <var>, <const>, <ini>, con gli attributi in qualunque ordine e su più righe.
# Un valore vuoto passa.
perl -e '
    eval $ENV{LEGGI}; die $@ if $@;
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        next unless $file =~ m{(?:^|/)[^/]*phpunit[^/]*\.xml[^/]*$};
        my $testo = leggi($file);
        defined $testo or do { $rotto = 1; next };
        while ($testo =~ /<(?:env|server|var|const|ini)\b([^>]*)>/gi) {
            my ($attributi, $riga) = ($1, riga($testo, $-[0]));
            next unless $attributi =~ /\bname\s*=\s*(["\x27])($nomi)\1/i;
            my $nome = $2;
            next unless $attributi =~ /\bvalue\s*=\s*(["\x27])(?!\1)/i;
            printf "%s:%d: %s\n", $file, $riga, $nome;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)' < "$lista"
esito $? "un segreto nella configurazione di PHPUnit"

# Un valore per una variabile segreta in qualunque file di testo (un README, un esempio, uno script, il codice):
# - come in un .env o in una riga di comando, NOME=valore senza spazi intorno all'uguale (== e => no). Il valore finisce
#   alla fine della riga, a uno spazio, o alla virgoletta (o al backtick) che apriva l'assegnazione: "NOME=$VAR",
#   `NOME=`. Vuoto, passa solo se dopo viene la fine della riga o un commento (uno spazio e #);
# - in un file YAML, NOME: valore a inizio riga, anche con la chiave fra virgolette o uno spazio prima dei due punti; il
#   valore finisce alla fine della riga o a un commento;
# - con la chiave fra virgolette, come in un JSON ("nome": "valore"), in un array PHP ('nome' => 'valore'), in config() e
#   define() ('nome', 'valore'), con un - o un . nella chiave, e anche col valore a capo: solo un valore fra virgolette (una
#   costante, una variabile, un numero non si leggono), e passa solo se fra le virgolette c'è un segnaposto intero;
# - con la chiave senza virgolette e il valore fra virgolette, come negli argomenti con nome del PHP e negli oggetti JS
#   (nome: 'valore'): non dopo un $, un ->, un ::, un . o una virgoletta (una variabile, una proprietà, una stringa).
# Le traduzioni (resources/lang/, le copie di quelle del backoffice) non si leggono con la chiave fra virgolette: le loro
# chiavi sono i nomi dei messaggi (current_password, array_keys), e i valori sono testi.
# E, dalla forma, anche senza un nome accanto: un gettone di Zeiras (zr_ e 48 lettere o cifre), un webhook di Slack, un
# gettone dopo Bearer (8 segni, con una cifra o un _), un indirizzo interno (la rete 10/8, il loopback salvo 127.0.0.1, i
# nomi ssh dei server, i domini interni). I file binari (con un NUL) no.
perl -e '
    eval $ENV{LEGGI}; die $@ if $@;
    my ($trovato, $rotto) = (0, 0);
    my ($nomi, $citati, $nudo, $citato) = @ENV{qw(NOMI NOMI_CITATI NUDO CITATO)};
    my %forme = (
        "un gettone di Zeiras" => qr/(?<![A-Za-z0-9_])zr_[A-Za-z0-9]{48}(?![A-Za-z0-9])/,
        "un webhook di Slack" => qr{hooks\.slack\.com/(?:services|workflows|triggers)/[A-Za-z0-9]}i,
        "un gettone dopo Bearer" => qr/\bBearer\s+(?=[A-Za-z._~+\/=-]*[0-9_])[A-Za-z0-9._~+\/=-]{8,}/,
        "un indirizzo interno" => qr/(?<![\d.])10\.\d{1,3}\.\d{1,3}\.\d{1,3}(?![\d.]*\d)|(?<![\d.])127\.0\.0\.(?!1(?!\d))\d{1,3}(?![\d.]*\d)|\b(?:zeiras|forge)[-]server\b|\bcpmakers[.]net\b/i,
    );
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        my $testo = leggi($file);
        defined $testo or do { $rotto = 1; next };
        next if $testo =~ /\0/;
        # In contesto scalare: un match che fallisce, in una lista, è una lista vuota, e sposterebbe i valori dopo di lui.
        my $yaml = $file =~ /\.ya?ml$/ ? 1 : 0;
        my $traduzioni = $file =~ m{^resources/lang/} ? 1 : 0;
        my @trovati;
        my $riga = 0;
        for my $linea (split /\n/, $testo) {
            $riga++;
            # NOME=valore: il gruppo 1 è la virgoletta (o il backtick) che apre l assegnazione, se c è.
            while ($linea =~ /(?:(?<=(["\x27\x60]))|(?<!["\x27\x60]))\b($nomi)=(?![=>])
                    (?! (?: $citato | $nudo ) (?= \s | $ | (?(1) \g{1} | (?!) ) )
                      | (?= \s*$ | \s+\# | (?(1) \g{1} | (?!) ) ) )/gx) {
                push @trovati, [$riga, $2];
            }
            if ($yaml && $linea =~ /^\s*-?\s*(["\x27]?)($citati)\g{1}\s*:(?=\s|$)\s*+
                    (?! (?: $citato | $nudo ) (?= \s*$ | \s+\# ) | (?= $ | \# ) )/x) {
                push @trovati, [$riga, $2];
            }
            for my $forma (sort keys %forme) {
                push @trovati, [$riga, $forma] if $linea =~ $forme{$forma};
            }
        }
        # Le forme con la chiave fra virgolette, sul testo intero: il valore può stare a capo.
        if (! $traduzioni) {
            while ($testo =~ /(["\x27])($citati)\g{1}\s*(?::|=>)\s*(?=["\x27])(?!$citato)/gx) {
                push @trovati, [riga($testo, $-[0]), $2];
            }
        }
        while ($testo =~ /(?:\bdefine|\bconfig|\bConfig::set|->withHeader)\s*\(\s*(["\x27])($citati)\g{1}\s*,\s*(?=["\x27])(?!$citato)/gx) {
            push @trovati, [riga($testo, $-[0]), $2];
        }
        while ($testo =~ /(?<![\$\w>:.\x27"-])($nomi)\s*:(?!:)[ \t]*(?=["\x27])(?!$citato)/gx) {
            push @trovati, [riga($testo, $-[0]), $1];
        }
        for my $t (sort { $a->[0] <=> $b->[0] } @trovati) {
            printf "%s:%d: %s\n", $file, @$t;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)' < "$lista"
esito $? "un segreto, o un indirizzo interno, in un file di testo"

if [ "$rotto" = 1 ]; then
    exit 2
fi

exit "$trovato"
