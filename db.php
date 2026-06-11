<?php


if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1') {
    // Configuration Locale (votre PC)
    $host   = 'localhost';
    $dbname = 'lol_library';
    $user   = 'root';
    $pass   = ''; 
} else {
 
    $host   = 'nom_du_serveur_sql'; // ex: sql.hebergeur.com ou localhost
    $dbname = 'votre_nom_de_bdd';
    $user   = 'votre_utilisateur_bdd';
    $pass   = 'votre_mot_de_passe_bdd';
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
 
    error_log("Erreur BDD : " . $e->getMessage());
    die("Une erreur de connexion est survenue. Veuillez réessayer plus tard.");
}