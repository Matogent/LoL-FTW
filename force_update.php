<?php
include 'db.php';

$missing_champs = [
    [
        "name" => "Ambessa",
        "title" => "Matriarche de guerre",
        "role" => "Fighter",
        "diff" => 3,
        "lore" => "Générale de Noxus et mère de Mel Medarda, Ambessa est une guerrière impitoyable qui utilise ses doubles lames pour dominer le champ de bataille.",
        "img" => "Ambessa"
    ],
    [
        "name" => "Mel",
        "title" => "Conseillère de Piltover",
        "role" => "Mage",
        "diff" => 2,
        "lore" => "Issue de la prestigieuse famille Medarda de Noxus, Mel a tracé sa propre voie à Piltover, utilisant son intelligence et ses pouvoirs pour façonner l'avenir.",
        "img" => "Mel"
    ],
    [
        "name" => "Aurora",
        "title" => "Sorcière entre les mondes",
        "role" => "Mage",
        "diff" => 2,
        "lore" => "Aurora est une vasteya capable de voyager entre le monde spirituel et le monde matériel pour protéger l'équilibre de Freljord.",
        "img" => "Aurora"
    ],
    [
        "name" => "Smolder",
        "title" => "Dragonneau flamboyant",
        "role" => "Marksman",
        "diff" => 1,
        "lore" => "Un jeune dragon héritier d'une lignée royale camavorienne, Smolder apprend à maîtriser son souffle de feu sous l'œil attentif de sa mère.",
        "img" => "Smolder"
    ]
];

foreach ($missing_champs as $c) {
    // On vérifie si le champion existe déjà pour éviter les doublons
    $check = $pdo->prepare("SELECT id FROM champions WHERE name = ?");
    $check->execute([$c['name']]);
    
    if (!$check->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO champions (name, title, role_primary, difficulty, lore, image_url) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$c['name'], $c['title'], $c['role'], $c['diff'], $c['lore'], $c['img']]);
        echo "Ajouté : " . $c['name'] . "<br>";
    } else {
        echo "Déjà présent : " . $c['name'] . "<br>";
    }
}

echo "<strong>Mise à jour terminée !</strong>";
?>