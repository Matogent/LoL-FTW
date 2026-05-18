<?php
include 'db.php';


$versions_json = file_get_contents("https://ddragon.leagueoflegends.com/api/versions.json");
$versions = json_decode($versions_json, true);
$latest_version = $versions[0]; 
echo "Importation en cours (Version $latest_version)...<br>";


$url = "https://ddragon.leagueoflegends.com/cdn/$latest_version/data/fr_FR/champion.json";
$json = file_get_contents($url);
$data = json_decode($json, true);

if ($data) {
    
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