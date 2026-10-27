<?php
require_once 'connection.php';
require_once 'includes/functions.php';
require_once 'includes/event.php';
require_once 'includes/analytics.php';

$event = huidig_event($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_bezoek($conn, $event['id'], $event['naam'], 'uitgaven');
}

// --- Verwijderen (POST met CSRF-check i.p.v. een kale GET-link) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwijder_uitgave'])) {
    verify_csrf();
    $stmt = $conn->prepare('DELETE FROM Uitgaven WHERE id=? AND event_id=?');
    $stmt->execute([$_POST['verwijder_uitgave'], $event['id']]);
    flash_set('success', 'Uitgave verwijderd.');
    header('Location: ' . event_url('index.php', $event));
    exit;
}

// --- Opslaan (nieuw of bewerken) ---
$form_errors = [];
$form_values = ['datum' => date('Y-m-d'), 'omschrijving' => '', 'betaald_door_id' => '', 'bedrag' => '', 'edit_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opslaan_uitgave'])) {
    verify_csrf();

    $form_values['datum'] = $_POST['datum'] ?? '';
    $form_values['omschrijving'] = trim($_POST['omschrijving'] ?? '');
    $form_values['betaald_door_id'] = $_POST['betaald_door_id'] ?? '';
    $form_values['bedrag'] = trim($_POST['bedrag'] ?? '');
    $form_values['edit_id'] = $_POST['edit_id'] ?? '';

    $datum_obj = DateTime::createFromFormat('Y-m-d', $form_values['datum']);
    if (!$datum_obj) {
        $form_errors['datum'] = 'Kies een geldige datum.';
    }
    if ($form_values['omschrijving'] === '') {
        $form_errors['omschrijving'] = 'Vul een omschrijving in.';
    } elseif (mb_strlen($form_values['omschrijving']) > 255) {
        $form_errors['omschrijving'] = 'Omschrijving mag maximaal 255 tekens zijn.';
    }

    // Alleen deelnemers van DIT evenement mogen als "betaald door" gekozen worden.
    $stmt = $conn->prepare('SELECT id FROM Deelnemers WHERE event_id=?');
    $stmt->execute([$event['id']]);
    $geldige_ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    if ($form_values['betaald_door_id'] === '' || !in_array((int) $form_values['betaald_door_id'], $geldige_ids, true)) {
        $form_errors['betaald_door_id'] = 'Kies wie er heeft betaald.';
    }

    $bedrag = parse_bedrag($form_values['bedrag']);
    if ($bedrag === null) {
        $form_errors['bedrag'] = 'Vul een geldig bedrag groter dan 0 in (bijv. 12,50).';
    }

    if (empty($form_errors)) {
        if (!empty($form_values['edit_id'])) {
            $stmt = $conn->prepare('UPDATE Uitgaven SET datum=?, omschrijving=?, betaald_door_id=?, bedrag=? WHERE id=? AND event_id=?');
            $stmt->execute([
                $form_values['datum'],
                $form_values['omschrijving'],
                $form_values['betaald_door_id'],
                $bedrag,
                $form_values['edit_id'],
                $event['id'],
            ]);
            flash_set('success', 'Wijziging opgeslagen.');
        } else {
            $stmt = $conn->prepare('INSERT INTO Uitgaven (event_id, datum, omschrijving, betaald_door_id, bedrag) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $event['id'],
                $form_values['datum'],
                $form_values['omschrijving'],
                $form_values['betaald_door_id'],
                $bedrag,
            ]);
            flash_set('success', 'Uitgave toegevoegd.');
        }
        header('Location: ' . event_url('index.php', $event));
        exit;
    }
}

// --- Checken of we een uitgave aan het bewerken zijn ---
$edit_data = null;
if (isset($_GET['bewerk'])) {
    $stmt = $conn->prepare('SELECT * FROM Uitgaven WHERE id=? AND event_id=?');
    $stmt->execute([$_GET['bewerk'], $event['id']]);
    $edit_data = $stmt->fetch();
    if ($edit_data) {
        $form_values = [
            'datum'           => date('Y-m-d', strtotime($edit_data['datum'])),
            'omschrijving'    => $edit_data['omschrijving'],
            'betaald_door_id' => $edit_data['betaald_door_id'],
            'bedrag'          => $edit_data['bedrag'],
            'edit_id'         => $edit_data['id'],
        ];
    }
}

// --- Data ophalen (alleen van dit evenement) ---
$stmt = $conn->prepare('SELECT * FROM Deelnemers WHERE event_id=? ORDER BY naam ASC');
$stmt->execute([$event['id']]);
$deelnemers_db = $stmt->fetchAll();

$stmt = $conn->prepare('
    SELECT u.id, u.datum, u.omschrijving, u.bedrag, u.betaald_door_id, d.naam AS betaald_door, d.iban
    FROM Uitgaven u
    JOIN Deelnemers d ON u.betaald_door_id = d.id
    WHERE u.event_id = ?
    ORDER BY u.datum DESC, u.id DESC
');
$stmt->execute([$event['id']]);
$uitgaven_db = $stmt->fetchAll();

$verdeling = bereken_verdeling($deelnemers_db, $uitgaven_db);

$page_title = 'IOU - ' . $event['naam'];
require 'includes/header.php';
?>

    <h1>Uitgaven &mdash; <?= e($event['naam']) ?></h1>
    <p class="subtitle">Houd gezamenlijke kosten bij en zie in één oogopslag wie nog moet afrekenen.</p>

    <div class="box accent">
        <h2><?= $edit_data ? 'Uitgave aanpassen' : '➕ Nieuwe uitgave toevoegen' ?></h2>
        <?php if (!empty($form_errors)): ?>
            <p class="flash error" role="alert">Controleer de gemarkeerde velden hieronder.</p>
        <?php endif; ?>
        <?php if (empty($deelnemers_db)): ?>
            <p class="empty-state">
                Nog geen deelnemers in dit evenement.
                <a href="<?= e(event_url('deelnemers.php', $event)) ?>">Voeg er eerst een paar toe</a>.
            </p>
        <?php else: ?>
        <form method="POST" action="<?= e(event_url('index.php', $event)) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="edit_id" value="<?= e((string) $form_values['edit_id']) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="datum">Datum</label>
                    <input type="date" id="datum" name="datum" value="<?= e($form_values['datum']) ?>"
                           required aria-required="true"
                           <?= isset($form_errors['datum']) ? 'aria-invalid="true" aria-describedby="datum-error"' : '' ?>>
                    <?php if (isset($form_errors['datum'])): ?>
                        <p class="field-error" id="datum-error"><?= e($form_errors['datum']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="flex-grow: 2;">
                    <label for="omschrijving">Omschrijving</label>
                    <input type="text" id="omschrijving" name="omschrijving" maxlength="255"
                           value="<?= e($form_values['omschrijving']) ?>" required aria-required="true"
                           placeholder="Wat is er betaald?"
                           <?= isset($form_errors['omschrijving']) ? 'aria-invalid="true" aria-describedby="omschrijving-error"' : '' ?>>
                    <?php if (isset($form_errors['omschrijving'])): ?>
                        <p class="field-error" id="omschrijving-error"><?= e($form_errors['omschrijving']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex-grow: 2;">
                    <label for="betaald_door_id">Wie heeft betaald?</label>
                    <select id="betaald_door_id" name="betaald_door_id" required aria-required="true"
                            <?= isset($form_errors['betaald_door_id']) ? 'aria-invalid="true" aria-describedby="betaald-error"' : '' ?>>
                        <option value="">-- Selecteer persoon --</option>
                        <?php foreach ($deelnemers_db as $p): ?>
                            <option value="<?= e((string) $p['id']) ?>" <?= ((string) $form_values['betaald_door_id'] === (string) $p['id']) ? 'selected' : '' ?>>
                                <?= e($p['naam']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($form_errors['betaald_door_id'])): ?>
                        <p class="field-error" id="betaald-error"><?= e($form_errors['betaald_door_id']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="bedrag">Bedrag (&euro;)</label>
                    <input type="text" inputmode="decimal" id="bedrag" name="bedrag"
                           value="<?= e((string) $form_values['bedrag']) ?>" required aria-required="true"
                           placeholder="0,00"
                           <?= isset($form_errors['bedrag']) ? 'aria-invalid="true" aria-describedby="bedrag-error"' : '' ?>>
                    <?php if (isset($form_errors['bedrag'])): ?>
                        <p class="field-error" id="bedrag-error"><?= e($form_errors['bedrag']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="actions">
                <button type="submit" name="opslaan_uitgave" value="1" class="btn"><?= $edit_data ? 'Wijziging opslaan' : 'Toevoegen' ?></button>
                <?php if ($edit_data): ?>
                    <a href="<?= e(event_url('index.php', $event)) ?>" class="btn-secondary" style="padding: 10px 18px; border-radius: 8px; text-decoration: none;">Annuleren</a>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Overzicht</h2>
        <p class="summary-line">
            <span><strong>Totale uitgaven:</strong> <?= euro($verdeling['totaal']) ?></span>
            <span><strong>Kosten per persoon:</strong> <?= euro($verdeling['per_persoon']) ?></span>
        </p>
        <?php if (empty($deelnemers_db)): ?>
            <p class="empty-state">Nog geen deelnemers in dit evenement.</p>
        <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Naam</th>
                <th scope="col">IBAN</th>
                <th scope="col">Totaal betaald</th>
                <th scope="col">Balans</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($verdeling['balansen'] as $b): ?>
                <tr>
                    <td data-label="Naam"><?= e($b['naam']) ?></td>
                    <td data-label="IBAN"><?= e($b['iban'] ?: '—') ?></td>
                    <td data-label="Totaal betaald"><?= euro($b['betaald']) ?></td>
                    <td data-label="Balans" class="<?= $b['balans'] >= 0 ? 'amount-positive' : 'amount-negative' ?>">
                        <?= $b['balans'] >= 0 ? '↑ krijgt nog ' : '↓ moet nog betalen ' ?><?= euro(abs($b['balans'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Wie betaalt wat aan wie?</h2>
        <?php if (count($verdeling['verrekeningen']) === 0): ?>
            <p class="empty-state">Iedereen staat op nul, er hoeft niets verrekend te worden.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th scope="col">Van</th>
                    <th scope="col">Aan</th>
                    <th scope="col">Bedrag</th>
                    <th scope="col">Naar rekening</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($verdeling['verrekeningen'] as $v): ?>
                    <tr>
                        <td data-label="Van"><?= e($v['van']) ?></td>
                        <td data-label="Aan"><?= e($v['aan']) ?></td>
                        <td data-label="Bedrag"><strong><?= euro($v['bedrag']) ?></strong></td>
                        <td data-label="Naar rekening"><?= e($v['iban'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Alle uitgaven</h2>
        <?php if (empty($uitgaven_db)): ?>
            <p class="empty-state">Nog geen uitgaven toegevoegd.</p>
        <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Datum</th>
                <th scope="col">Omschrijving</th>
                <th scope="col">Betaald door</th>
                <th scope="col">Bedrag</th>
                <th scope="col">Acties</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($uitgaven_db as $u): ?>
                <tr>
                    <td data-label="Datum"><?= e(date('d-m-Y', strtotime($u['datum']))) ?></td>
                    <td data-label="Omschrijving"><?= e($u['omschrijving']) ?></td>
                    <td data-label="Betaald door"><?= e($u['betaald_door']) ?></td>
                    <td data-label="Bedrag"><?= euro((float) $u['bedrag']) ?></td>
                    <td data-label="Acties">
                        <div class="actions">
                            <a href="<?= e(event_url('index.php', $event, ['bewerk' => $u['id']])) ?>" class="btn-edit"
                               aria-label="Bewerk uitgave: <?= e($u['omschrijving']) ?>">Bewerk</a>
                            <form method="POST" action="<?= e(event_url('index.php', $event)) ?>" style="display:inline;"
                                  onsubmit="return confirm('Uitgave &quot;<?= e($u['omschrijving']) ?>&quot; verwijderen?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="verwijder_uitgave" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn-delete"
                                        aria-label="Verwijder uitgave: <?= e($u['omschrijving']) ?>">Verwijder</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php require 'includes/footer.php'; ?>
