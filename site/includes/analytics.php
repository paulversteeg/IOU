<?php
/**
 * Lichtgewicht, zelf-gehoste bezoekstatistieken — geen Google Analytics
 * of ander extern trackingscript, dus geen gegevens van jou of je
 * vrienden gaan naar een derde partij. Er wordt alleen gelogd: welke
 * pagina, van welk evenement, en een niet-herleidbare hash van
 * IP+browser (om ruwweg "unieke bezoekers" te kunnen tellen zonder
 * echte IP-adressen op te slaan).
 *
 * Genegeerde IP-adressen (bijv. je eigen thuis/werk-IP) tellen bewust
 * niet mee, zodat je eigen testbezoekjes de statistieken niet vertekenen.
 */

/** Bepaalt de bezoeker-hash. Los testbaar van de databaselogica hieronder. */
function bezoeker_hash_berekenen(string $ip, string $user_agent): string
{
    return substr(hash('sha256', $ip . '|' . $user_agent . '|' . BEZOEKER_SALT), 0, 16);
}

/** Maakt de tabel met genegeerde IP-adressen aan als die nog niet bestaat. */
function negeerips_tabel_zorgen(PDO $conn): void
{
    try {
        $conn->exec('
            CREATE TABLE IF NOT EXISTS NegeerIps (
                id INT NOT NULL AUTO_INCREMENT,
                ip_adres VARCHAR(45) NOT NULL,
                opmerking VARCHAR(100) NULL,
                toegevoegd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY ip_adres (ip_adres)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ');
    } catch (\Throwable $e) {
        error_log('IOU negeerips-tabel aanmaken mislukt: ' . $e->getMessage());
    }
}

/** Staat dit IP-adres op de negeerlijst? Bij twijfel (bijv. DB-fout): nee, gewoon loggen. */
function ip_genegeerd(PDO $conn, string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    try {
        negeerips_tabel_zorgen($conn);
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM NegeerIps WHERE ip_adres = ?');
        $stmt->execute([$ip]);
        return (int) $stmt->fetch()['n'] > 0;
    } catch (\Throwable $e) {
        error_log('IOU ip_genegeerd-check mislukt: ' . $e->getMessage());
        return false;
    }
}

/**
 * Logt één paginabezoek. Maakt de tabel zo nodig zelf aan (eenmalig,
 * daarna is het een no-op). Faalt bewust "stil": als loggen om wat voor
 * reden dan ook niet lukt, mag dat de pagina zelf nooit breken. Bezoeken
 * vanaf een genegeerd IP-adres worden bewust niet weggeschreven.
 */
function log_bezoek(PDO $conn, ?int $event_id, ?string $event_naam, string $pagina): void
{
    // Los geprobeerd van de INSERT hieronder: als het aanmaken van de
    // tabel om wat voor reden dan ook faalt (bijv. bij het allereerste
    // gebruik nog niet aangemaakt kunnen worden), mag dat het loggen
    // zelf niet permanent blokkeren zodra de tabel er wél is.
    try {
        $conn->exec('
            CREATE TABLE IF NOT EXISTS Bezoeken (
                id INT NOT NULL AUTO_INCREMENT,
                event_id INT NULL,
                event_naam VARCHAR(150) NULL,
                pagina VARCHAR(50) NOT NULL,
                tijdstip DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                bezoeker_hash CHAR(16) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_tijdstip (tijdstip),
                KEY idx_event (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ');
    } catch (\Throwable $e) {
        error_log('IOU analytics-tabel aanmaken mislukt: ' . $e->getMessage());
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (ip_genegeerd($conn, $ip)) {
        return;
    }

    try {
        $hash = bezoeker_hash_berekenen($ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt = $conn->prepare('INSERT INTO Bezoeken (event_id, event_naam, pagina, bezoeker_hash) VALUES (?, ?, ?, ?)');
        $stmt->execute([$event_id, $event_naam, $pagina, $hash]);
    } catch (\Throwable $e) {
        error_log('IOU analytics-log mislukt: ' . $e->getMessage());
    }
}
