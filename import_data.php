<?php
include 'db.php';

// 1. On récupère dynamiquement la dernière version de l'API
$versions_json = file_get_contents("https://ddragon.leagueoflegends.com/api/versions.json");
$versions = json_decode($versions_json, true);
$latest_version = $versions[0]; // La version 0 est toujours la plus récente

echo "Importation en cours (Version $latest_version)...<br>";

// 2. On utilise cette version pour charger les champions
$url = "https://ddragon.leagueoflegends.com/cdn/$latest_version/data/fr_FR/champion.json";
$json = file_get_contents($url);
$data = json_decode($json, true);

if ($data) {
    // On vide la table avant pour éviter les doublons
    $pdo->exec("TRUNCATE TABLE champions");

    $stmt = $pdo->prepare("INSERT INTO champions (name, title, role_primary, difficulty, lore, image_url) VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($data['data'] as $champ) {
        $stmt->execute([
            $champ['name'],
            $champ['title'],
            $champ['tags'][0],
            $champ['info']['difficulty'],
            $champ['blurb'],
            $champ['id']
        ]);
    }
    echo "Succès ! Tous les champions (incluant les derniers sortis) ont été importés.";
} else {
    echo "Erreur lors de la récupération des données.";
}
?>