<?php
/**
 * Gedeelde helpers: sessie/CSRF, escaping, formattering en de
 * verdeel-berekening. Wordt door index.php en deelnemers.php gebruikt
 * zodat er geen logica gedupliceerd hoeft te worden.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Korte alias voor htmlspecialchars, scheelt duplicatie en fouten. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Geeft het huidige CSRF-token terug (maakt er zo nodig één aan). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verborgen input voor in formulieren. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Stopt de request met een 400 als het token ontbreekt of niet klopt. */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Ongeldige of verlopen aanvraag. Ga terug, ververs de pagina en probeer het opnieuw.');
    }
}

/** Eenmalige flash-melding (survives één redirect). */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/** Bedrag naar "€ 12,34". */
function euro(float $bedrag): string
{
    return '€ ' . number_format($bedrag, 2, ',', '.');
}

/**
 * Valideert en normaliseert een door de gebruiker ingevoerd bedrag.
 * Accepteert komma of punt als decimaalteken. Geeft null bij ongeldige input.
 */
function parse_bedrag(string $input): ?float
{
    $input = trim($input);
    if ($input === '' || !preg_match('/^-?\d{1,9}([.,]\d{1,2})?$/', $input)) {
        return null;
    }
    $bedrag = (float) str_replace(',', '.', $input);
    if ($bedrag <= 0) {
        return null;
    }
    return round($bedrag, 2);
}

/** Simpele, niet-strenge IBAN-check: alleen lengte/tekenset, geen checksum. */
function is_geldig_iban(string $iban): bool
{
    $iban = strtoupper(str_replace(' ', '', $iban));
    return $iban === '' || (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban);
}

/**
 * Berekent, gegeven deelnemers en uitgaven, wie wat heeft voorgeschoten,
 * ieders balans en de minimale set onderlinge overboekingen om iedereen
 * weer op nul te krijgen ("schulden vereffenen").
 *
 * @param array<int, array{id:int,naam:string,iban:?string}> $deelnemers
 * @param array<int, array{betaald_door:string,bedrag:float}> $uitgaven
 * @return array{totaal:float,per_persoon:float,balansen:array,verrekeningen:array}
 */
function bereken_verdeling(array $deelnemers, array $uitgaven): array
{
    $voorgeschoten = [];
    foreach ($deelnemers as $p) {
        $voorgeschoten[$p['naam']] = 0.0;
    }

    $totaal = 0.0;
    foreach ($uitgaven as $u) {
        $totaal += (float) $u['bedrag'];
        if (isset($voorgeschoten[$u['betaald_door']])) {
            $voorgeschoten[$u['betaald_door']] += (float) $u['bedrag'];
        }
    }

    $aantal = count($deelnemers);
    $per_persoon = $aantal > 0 ? $totaal / $aantal : 0.0;

    $balansen = [];
    $schuldenaars = [];
    $schuldeisers = [];

    foreach ($deelnemers as $p) {
        $betaald = $voorgeschoten[$p['naam']];
        $balans = $betaald - $per_persoon;

        $balansen[] = [
            'naam'    => $p['naam'],
            'iban'    => $p['iban'],
            'betaald' => $betaald,
            'balans'  => $balans,
        ];

        if ($balans < -0.01) {
            $schuldenaars[] = ['naam' => $p['naam'], 'bedrag' => abs($balans)];
        } elseif ($balans > 0.01) {
            $schuldeisers[] = ['naam' => $p['naam'], 'iban' => $p['iban'], 'bedrag' => $balans];
        }
    }

    $verrekeningen = [];
    $d = 0;
    $c = 0;
    while ($d < count($schuldenaars) && $c < count($schuldeisers)) {
        $bedrag = min($schuldenaars[$d]['bedrag'], $schuldeisers[$c]['bedrag']);
        $verrekeningen[] = [
            'van'    => $schuldenaars[$d]['naam'],
            'aan'    => $schuldeisers[$c]['naam'],
            'bedrag' => $bedrag,
            'iban'   => $schuldeisers[$c]['iban'],
        ];
        $schuldenaars[$d]['bedrag'] -= $bedrag;
        $schuldeisers[$c]['bedrag'] -= $bedrag;
        if ($schuldenaars[$d]['bedrag'] < 0.01) {
            $d++;
        }
        if ($schuldeisers[$c]['bedrag'] < 0.01) {
            $c++;
        }
    }

    return [
        'totaal'        => $totaal,
        'per_persoon'   => $per_persoon,
        'balansen'       => $balansen,
        'verrekeningen' => $verrekeningen,
    ];
}
