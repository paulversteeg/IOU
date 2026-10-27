<?php
/**
 * Overzicht van gebruik: hoe vaak en wanneer IOU wordt bezocht, per
 * evenement en per type pagina. Achter een simpel wachtwoord (zie
 * connection.php) omdat dit iets zegt over hoe vaak je vriendengroep
 * de app gebruikt — geen reden om dat voor iedereen met de link
 * zichtbaar te maken.
 */
require_once 'connection.php';
require_once 'includes/functions.php';
require_once 'includes/analytics.php';

if (isset($_GET['uitloggen'])) {
    unset($_SESSION['statistieken_ok']);
    header('Location: statistieken.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inloggen'])) {
    verify_csrf();
    if (hash_equals(STATISTIEKEN_WACHTWOORD, $_POST['wachtwoord'] ?? '')) {
        $_SESSION['statistieken_ok'] = true;
    } else {
        flash_set('error', 'Onjuist wachtwoord.');
    }
    header('Location: statistieken.php');
    exit;
}

$ingelogd = !empty($_SESSION['statistieken_ok']);

// --- Genegeerde IP-adressen beheren (alleen als je bent ingelogd) ---
if ($ingelogd && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toevoegen_negeer_ip'])) {
    verify_csrf();
    negeerips_tabel_zorgen($conn);

    $ip_input = trim($_POST['ip_adres'] ?? '');
    $opmerking = trim($_POST['opmerking'] ?? '');

    if (!filter_var($ip_input, FILTER_VALIDATE_IP)) {
        flash_set('error', 'Dat is geen geldig IP-adres.');
    } else {
        $stmt = $conn->prepare('INSERT IGNORE INTO NegeerIps (ip_adres, opmerking) VALUES (?, ?)');
        $stmt->execute([$ip_input, $opmerking !== '' ? $opmerking : null]);
        flash_set('success', 'IP-adres "' . $ip_input . '" wordt vanaf nu genegeerd in de statistieken.');
    }
    header('Location: statistieken.php');
    exit;
}

if ($ingelogd && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwijder_negeer_ip'])) {
    verify_csrf();
    negeerips_tabel_zorgen($conn);
    $stmt = $conn->prepare('DELETE FROM NegeerIps WHERE id=?');
    $stmt->execute([$_POST['verwijder_negeer_ip']]);
    flash_set('success', 'IP-adres van de negeerlijst gehaald.');
    header('Location: statistieken.php');
    exit;
}

$page_title = 'IOU - Statistieken';
require 'includes/header.php';

if (!$ingelogd) {
    ?>
    <h1>Statistieken</h1>
    <div class="box accent">
        <form method="POST" action="statistieken.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="wachtwoord">Wachtwoord</label>
                <input type="password" id="wachtwoord" name="wachtwoord" required aria-required="true" autofocus>
            </div>
            <button type="submit" name="inloggen" value="1" class="btn">Inloggen</button>
        </form>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

// --- Data verzamelen ---
function tabel_bestaat_stats(PDO $conn, string $tabel): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabel]);
    return (int) $stmt->fetch()['n'] > 0;
}

$heeft_data = tabel_bestaat_stats($conn, 'Bezoeken');

negeerips_tabel_zorgen($conn);
$genegeerde_ips = $conn->query('SELECT * FROM NegeerIps ORDER BY toegevoegd_op DESC')->fetchAll();
$mijn_ip = $_SERVER['REMOTE_ADDR'] ?? '';

$totaal = 0;
$laatste_7 = 0;
$laatste_30 = 0;
$unieke_30 = 0;
$per_dag = [];
$top_events = [];
$per_pagina = [];

if ($heeft_data) {
    $totaal = (int) $conn->query('SELECT COUNT(*) AS n FROM Bezoeken')->fetch()['n'];
    $laatste_7 = (int) $conn->query('SELECT COUNT(*) AS n FROM Bezoeken WHERE tijdstip >= NOW() - INTERVAL 7 DAY')->fetch()['n'];
    $laatste_30 = (int) $conn->query('SELECT COUNT(*) AS n FROM Bezoeken WHERE tijdstip >= NOW() - INTERVAL 30 DAY')->fetch()['n'];
    $unieke_30 = (int) $conn->query('SELECT COUNT(DISTINCT bezoeker_hash) AS n FROM Bezoeken WHERE tijdstip >= NOW() - INTERVAL 30 DAY')->fetch()['n'];

    // Bezoeken per dag, laatste 14 dagen (met nulwaarden voor dagen zonder bezoek).
    $ruwe_dagen = $conn->query("
        SELECT DATE(tijdstip) AS dag, COUNT(*) AS aantal
        FROM Bezoeken
        WHERE tijdstip >= NOW() - INTERVAL 14 DAY
        GROUP BY DATE(tijdstip)
    ")->fetchAll();
    $per_dag_map = array_column($ruwe_dagen, 'aantal', 'dag');
    for ($i = 13; $i >= 0; $i--) {
        $dag = date('Y-m-d', strtotime("-$i days"));
        $per_dag[] = ['dag' => $dag, 'aantal' => (int) ($per_dag_map[$dag] ?? 0)];
    }

    $top_events = $conn->query('
        SELECT event_naam, COUNT(*) AS aantal, MAX(tijdstip) AS laatste
        FROM Bezoeken
        WHERE event_id IS NOT NULL
        GROUP BY event_id, event_naam
        ORDER BY aantal DESC
        LIMIT 20
    ')->fetchAll();

    $per_pagina = $conn->query('
        SELECT pagina, COUNT(*) AS aantal
        FROM Bezoeken
        GROUP BY pagina
        ORDER BY aantal DESC
    ')->fetchAll();
}

$max_per_dag = max(array_column($per_dag, 'aantal') ?: [0]);
?>

    <h1>Statistieken</h1>
    <p class="subtitle">
        Hoe vaak en wanneer IOU wordt gebruikt. Zelf gehost, geen externe trackers &mdash;
        er wordt alleen een niet-herleidbare hash per bezoeker bijgehouden, geen IP-adressen.
    </p>
    <p><a href="statistieken.php?uitloggen=1">Uitloggen</a></p>

    <div class="box">
        <h2>Genegeerde IP-adressen</h2>
        <p class="hint">
            Bezoeken vanaf deze IP-adressen tellen niet mee in de cijfers hieronder &mdash;
            handig om je eigen bezoekjes (testen, beheren) niet mee te laten wegen.
            Jouw huidige IP-adres is <strong><?= e($mijn_ip) ?></strong>.
        </p>

        <form method="POST" action="statistieken.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="ip_adres">IP-adres</label>
                    <input type="text" id="ip_adres" name="ip_adres" placeholder="<?= e($mijn_ip) ?>" required aria-required="true">
                </div>
                <div class="form-group" style="flex-grow: 2;">
                    <label for="opmerking">Opmerking <span class="hint" style="display:inline;">(optioneel)</span></label>
                    <input type="text" id="opmerking" name="opmerking" maxlength="100" placeholder="Bijv. thuis">
                </div>
            </div>
            <button type="submit" name="toevoegen_negeer_ip" value="1" class="btn-secondary" style="padding: 10px 18px; border-radius: 8px;">
                IP-adres negeren
            </button>
        </form>

        <?php if (!empty($genegeerde_ips)): ?>
            <table style="margin-top: 16px;">
                <thead>
                <tr><th scope="col">IP-adres</th><th scope="col">Opmerking</th><th scope="col">Actie</th></tr>
                </thead>
                <tbody>
                <?php foreach ($genegeerde_ips as $ip_rij): ?>
                    <tr>
                        <td data-label="IP-adres"><?= e($ip_rij['ip_adres']) ?></td>
                        <td data-label="Opmerking"><?= e($ip_rij['opmerking'] ?: '—') ?></td>
                        <td data-label="Actie">
                            <form method="POST" action="statistieken.php" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="verwijder_negeer_ip" value="<?= (int) $ip_rij['id'] ?>">
                                <button type="submit" class="btn-delete" aria-label="Niet meer negeren: <?= e($ip_rij['ip_adres']) ?>">Niet meer negeren</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (!$heeft_data): ?>
        <div class="box">
            <p class="empty-state">Nog geen bezoeken gelogd. Zodra iemand een pagina opent, verschijnt hier data.</p>
        </div>
    <?php else: ?>

    <div class="box">
        <h2>Overzicht</h2>
        <p class="summary-line">
            <span><strong><?= $totaal ?></strong> bezoeken totaal</span>
            <span><strong><?= $laatste_7 ?></strong> laatste 7 dagen</span>
            <span><strong><?= $laatste_30 ?></strong> laatste 30 dagen</span>
            <span><strong><?= $unieke_30 ?></strong> unieke bezoekers (30 dagen)</span>
        </p>
    </div>

    <div class="box">
        <h2>Bezoeken per dag (laatste 14 dagen)</h2>
        <table>
            <thead>
            <tr><th scope="col">Datum</th><th scope="col">Bezoeken</th></tr>
            </thead>
            <tbody>
            <?php foreach ($per_dag as $rij): ?>
                <tr>
                    <td data-label="Datum"><?= e(date('d-m', strtotime($rij['dag']))) ?></td>
                    <td data-label="Bezoeken">
                        <div class="actions" style="gap: 10px;">
                            <div class="bar-track" style="flex: 1 1 140px;">
                                <div class="bar-fill" style="width: <?= $max_per_dag > 0 ? round($rij['aantal'] / $max_per_dag * 100) : 0 ?>%;"></div>
                            </div>
                            <span><?= $rij['aantal'] ?></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="box">
        <h2>Drukste evenementen</h2>
        <?php if (empty($top_events)): ?>
            <p class="empty-state">Nog geen bezoeken aan een specifiek evenement.</p>
        <?php else: ?>
        <table>
            <thead>
            <tr><th scope="col">Evenement</th><th scope="col">Bezoeken</th><th scope="col">Laatste bezoek</th></tr>
            </thead>
            <tbody>
            <?php foreach ($top_events as $ev): ?>
                <tr>
                    <td data-label="Evenement"><?= e($ev['event_naam'] ?? '(onbekend)') ?></td>
                    <td data-label="Bezoeken"><?= (int) $ev['aantal'] ?></td>
                    <td data-label="Laatste bezoek"><?= e(date('d-m-Y H:i', strtotime($ev['laatste']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Per pagina</h2>
        <table>
            <thead>
            <tr><th scope="col">Pagina</th><th scope="col">Bezoeken</th></tr>
            </thead>
            <tbody>
            <?php foreach ($per_pagina as $p): ?>
                <tr>
                    <td data-label="Pagina"><?= e($p['pagina']) ?></td>
                    <td data-label="Bezoeken"><?= (int) $p['aantal'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>

<?php require 'includes/footer.php'; ?>
