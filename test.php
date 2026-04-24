<?php
include 'db.php';

echo "<h2>Ma base de données contient :</h2>";

$query = $pdo->query("SELECT name FROM champions");
while ($row = $query->fetch()) {
    echo "Champion : " . $row['name'] . "<br>";
}
?>