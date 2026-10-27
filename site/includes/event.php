<?php
/**
 * Evenement-koppeling: elk weekend/event heeft een eigen, niet te raden
 * link (?e=TOKEN). Alle deelnemers en uitgaven zijn aan zo'n event
 * gekoppeld, zodat meerdere groepen/gelegenheden los van elkaar kunnen
 * bestaan zonder in elkaars gegevens te komen.
 */

/** Lengte van het token in bytes vóór hex-encoding (24 hex-tekens, 96 bits). */
const EVENT_TOKEN_BYTES = 12;

function nieuw_event_token(): string
{
    return bin2hex(random_bytes(EVENT_TOKEN_BYTES));
}

/**
 * Haalt het huidige event op basis van ?e=... (GET of POST). Stuurt door
 * naar start.php met een duidelijke melding als er geen of een onbekend
 * token is meegegeven — een pagina zonder event bestaat niet.
 */
function huidig_event(PDO $conn): array
{
    $token = $_GET['e'] ?? $_POST['e'] ?? '';

    if ($token === '' || !preg_match('/^[a-f0-9]{24}$/', $token)) {
        flash_set('error', 'Geen (geldig) evenement gevonden. Maak hieronder een nieuwe aan of vraag de organisator om het linkje.');
        header('Location: start.php');
        exit;
    }

    $stmt = $conn->prepare('SELECT * FROM Events WHERE token=?');
    $stmt->execute([$token]);
    $event = $stmt->fetch();

    if (!$event) {
        flash_set('error', 'Dit evenement bestaat niet (meer). Maak hieronder een nieuwe aan of vraag de organisator om het juiste linkje.');
        header('Location: start.php');
        exit;
    }

    return $event;
}

/** Bouwt een link naar een pagina van dit script, met het event-token erin. */
function event_url(string $pagina, array $event, array $extra = []): string
{
    return $pagina . '?' . http_build_query(['e' => $event['token']] + $extra);
}

/** Basis-URL (schema+host+pad) van de huidige request, voor de deel-link. */
function huidige_basis_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'iou.waxine.nl';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * Laatst bekende IBAN voor een naam, over alle evenementen heen (zodat
 * iemand die eerder meedeed zijn/haar rekeningnummer niet steeds opnieuw
 * hoeft op te zoeken — maar het blijft per event gewoon te overschrijven).
 */
function laatst_bekend_iban(PDO $conn, string $naam): ?string
{
    $stmt = $conn->prepare(
        "SELECT iban FROM Deelnemers WHERE LOWER(naam) = LOWER(?) AND iban IS NOT NULL AND iban <> '' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$naam]);
    $rij = $stmt->fetch();
    return $rij ? $rij['iban'] : null;
}

/** Namen die al eens zijn gebruikt (voor autocomplete), zonder IBAN's mee te sturen. */
function eerder_gebruikte_namen(PDO $conn): array
{
    return array_column(
        $conn->query('SELECT DISTINCT naam FROM Deelnemers ORDER BY naam ASC LIMIT 200')->fetchAll(),
        'naam'
    );
}
