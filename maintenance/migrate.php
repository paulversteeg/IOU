<?php
/**
 * Eenmalige migratie: voegt evenementen (Events) toe aan de database en
 * koppelt de bestaande Deelnemers/Uitgaven aan één "gemigreerd" evenement,
 * zodat er niets verloren gaat. Idempotent: al gemigreerd? Dan gebeurt er
 * niets, ook niet als je deze pagina per ongeluk twee keer opent.
 *
 * BELANGRIJK: verwijder dit bestand van de server zodra de migratie is
 * gelukt — een migratiescript dat online blijft staan is nodeloos risico.
 */
require_once 'connection.php';
require_once 'includes/functions.php';
require_once 'includes/event.php';

function kolom_bestaat(PDO $conn, string $tabel, string $kolom): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$tabel, $kolom]);
    return (int) $stmt->fetch()['n'] > 0;
}

function tabel_bestaat(PDO $conn, string $tabel): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabel]);
    return (int) $stmt->fetch()['n'] > 0;
}

function constraint_bestaat(PDO $conn, string $tabel, string $naam): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $stmt->execute([$tabel, $naam]);
    return (int) $stmt->fetch()['n'] > 0;
}

$al_gemigreerd = kolom_bestaat($conn, 'Deelnemers', 'event_id') && kolom_bestaat($conn, 'Uitgaven', 'event_id');

$uitgevoerd = false;
$migratie_link = null;
$fout = null;

if (!$al_gemigreerd && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bevestig_migratie'])) {
    verify_csrf();
    try {
        if (!tabel_bestaat($conn, 'Events')) {
            $conn->exec("
                CREATE TABLE Events (
                    id INT NOT NULL AUTO_INCREMENT,
                    naam VARCHAR(150) NOT NULL,
                    token CHAR(24) NOT NULL,
                    aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY token (token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        // Eén event voor de bestaande (nog niet aan een event gekoppelde) gegevens.
        $token = nieuw_event_token();
        $stmt = $conn->prepare('SELECT id FROM Events WHERE token=?');
        $stmt->execute([$token]);
        while ($stmt->fetch()) {
            $token = nieuw_event_token();
            $stmt = $conn->prepare('SELECT id FROM Events WHERE token=?');
            $stmt->execute([$token]);
        }

        $stmt = $conn->prepare('INSERT INTO Events (naam, token) VALUES (?, ?)');
        $stmt->execute(['Bestaande gegevens (gemigreerd)', $token]);
        $migratie_event_id = (int) $conn->lastInsertId();

        if (!kolom_bestaat($conn, 'Deelnemers', 'event_id')) {
            $conn->exec("ALTER TABLE Deelnemers ADD COLUMN event_id INT NOT NULL DEFAULT {$migratie_event_id} AFTER id");
        }
        if (!kolom_bestaat($conn, 'Uitgaven', 'event_id')) {
            $conn->exec("ALTER TABLE Uitgaven ADD COLUMN event_id INT NOT NULL DEFAULT {$migratie_event_id} AFTER id");
        }

        if (!constraint_bestaat($conn, 'Deelnemers', 'FK_Deelnemers_Events')) {
            $conn->exec('ALTER TABLE Deelnemers ADD CONSTRAINT FK_Deelnemers_Events FOREIGN KEY (event_id) REFERENCES Events(id) ON DELETE CASCADE');
        }
        if (!constraint_bestaat($conn, 'Uitgaven', 'FK_Uitgaven_Events')) {
            $conn->exec('ALTER TABLE Uitgaven ADD CONSTRAINT FK_Uitgaven_Events FOREIGN KEY (event_id) REFERENCES Events(id) ON DELETE CASCADE');
        }

        $uitgevoerd = true;
        $migratie_link = huidige_basis_url() . '/index.php?e=' . $token;
    } catch (PDOException $e) {
        $fout = $e->getMessage();
        error_log('IOU migratie mislukt: ' . $fout);
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IOU - Migratie</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container narrow" style="padding-top: 40px;">
    <div class="box">
        <h1>Migratie: evenementen toevoegen</h1>

        <?php if ($al_gemigreerd || $uitgevoerd): ?>
            <p class="flash success">Deze database is al gemigreerd naar het evenementen-model. Er is niets (meer) te doen.</p>
            <?php if ($migratie_link): ?>
                <p>Je bestaande gegevens (Paul/Frank/Cyril e.d.) zijn ondergebracht in één evenement. Bewaar dit linkje om erbij te kunnen:</p>
                <p><a href="<?= e($migratie_link) ?>"><?= e($migratie_link) ?></a></p>
            <?php endif; ?>
            <p><strong>Verwijder nu <code>migrate.php</code> van de server</strong> &mdash; dit script hoeft niet meer online te blijven staan.</p>
            <p><a href="start.php" class="btn" style="text-decoration:none; display:inline-block;">Naar IOU &rarr;</a></p>
        <?php elseif ($fout): ?>
            <p class="flash error">Er ging iets mis tijdens de migratie: <?= e($fout) ?>. Er is niets aangepast dat niet ook opnieuw geprobeerd kan worden &mdash; ververs de pagina en probeer het nogmaals.</p>
        <?php else: ?>
            <p>Dit voegt eenmalig evenementen (<code>Events</code>) toe aan de database en zet je bestaande deelnemers/uitgaven onder in één evenement, zodat er niets verloren gaat. Dit hoeft je maar één keer te doen.</p>
            <form method="POST" action="migrate.php">
                <?= csrf_field() ?>
                <button type="submit" name="bevestig_migratie" value="1" class="btn">Ja, voer de migratie nu uit</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
