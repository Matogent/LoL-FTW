<?php
// ============================================
// index.php — LoL Library (tout-en-un)
// ============================================
session_start();
include 'db.php';

// ---------- HELPERS ----------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function isLogged(): bool      { return isset($_SESSION['user']); }
function isAdmin(): bool       { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function redirect(string $url): void { header("Location: $url"); exit; }

// ---------- ACTIONS POST (traitement formulaires) ----------

// INSCRIPTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password =       $_POST['password'] ?? '';
    $confirm  =       $_POST['confirm']  ?? '';
    $error_reg = '';

    if (strlen($username) < 3)          $error_reg = 'Pseudo trop court (3 caractères min).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error_reg = 'Email invalide.';
    elseif (strlen($password) < 8)      $error_reg = 'Mot de passe trop court (8 caractères min).';
    elseif ($password !== $confirm)     $error_reg = 'Les mots de passe ne correspondent pas.';
    else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            $error_reg = 'Ce pseudo ou email est déjà utilisé.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hash]);
            $_SESSION['user'] = ['id' => $pdo->lastInsertId(), 'username' => $username, 'email' => $email, 'role' => 'user'];
            redirect('index.php?page=accueil');
        }
    }
}

// CONNEXION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email    = trim($_POST['email']    ?? '');
    $password =       $_POST['password'] ?? '';
    $error_login = '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $error_login = 'Email ou mot de passe incorrect.';
    } else {
        $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'role' => $user['role']];
        redirect('index.php?page=accueil');
    }
}

// DÉCONNEXION
if (isset($_GET['logout'])) {
    session_destroy();
    redirect('index.php?page=accueil');
}

// AJOUT COMMENTAIRE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment') {
    if (!isLogged()) redirect('index.php?page=login');
    $champion_id = (int)($_POST['champion_id'] ?? 0);
    $content     = trim($_POST['content'] ?? '');
    $rating      = !empty($_POST['rating']) ? (int)$_POST['rating'] : null;

    if ($content && $champion_id) {
        // Un seul commentaire par user/champion — on remplace s'il existe
        $stmt = $pdo->prepare("SELECT id FROM comments WHERE champion_id = ? AND user_id = ?");
        $stmt->execute([$champion_id, $_SESSION['user']['id']]);
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("UPDATE comments SET content = ?, rating = ?, created_at = NOW() WHERE champion_id = ? AND user_id = ?");
            $stmt->execute([$content, $rating, $champion_id, $_SESSION['user']['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO comments (champion_id, user_id, content, rating) VALUES (?, ?, ?, ?)");
            $stmt->execute([$champion_id, $_SESSION['user']['id'], $content, $rating]);
        }
    }
    redirect("index.php?page=champion&id=$champion_id#comments");
}

// SUPPRESSION COMMENTAIRE
if (isset($_GET['delete_comment'])) {
    if (!isLogged()) redirect('index.php');
    $comment_id  = (int)$_GET['delete_comment'];
    $champion_id = (int)($_GET['champion_id'] ?? 0);
    if (isAdmin()) {
        $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$comment_id]);
    } else {
        $pdo->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?")->execute([$comment_id, $_SESSION['user']['id']]);
    }
    redirect("index.php?page=champion&id=$champion_id#comments");
}

// ---------- ROUTING ----------
$page = $_GET['page'] ?? 'accueil';
$champion_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Données champion si besoin
$champion = null;
$ddragon   = null;
if ($page === 'champion' && $champion_id) {
    $stmt = $pdo->prepare("SELECT * FROM champions WHERE id = ?");
    $stmt->execute([$champion_id]);
    $champion = $stmt->fetch();

    if ($champion) {
        // Appel API DDragon (données lore + sorts)
        $imgKey = $champion['image_url'];
        $apiUrl = "https://ddragon.leagueoflegends.com/cdn/14.10.1/data/fr_FR/champion/{$imgKey}.json";
        $json   = @file_get_contents($apiUrl);
        if ($json) {
            $decoded = json_decode($json, true);
            $ddragon = $decoded['data'][$imgKey] ?? null;
        }

        // Commentaires du champion
        $stmt = $pdo->prepare("
            SELECT c.*, u.username, u.avatar_url
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.champion_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$champion_id]);
        $comments = $stmt->fetchAll();

        // Note moyenne
        $stmt = $pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as total FROM comments WHERE champion_id = ? AND rating IS NOT NULL");
        $stmt->execute([$champion_id]);
        $avg_rating = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        if ($page === 'champion' && $champion) echo h($champion['name']) . ' — LoL Library';
        elseif ($page === 'register')           echo 'Inscription — LoL Library';
        elseif ($page === 'login')              echo 'Connexion — LoL Library';
        elseif ($page === 'profile')            echo 'Profil — LoL Library';
        else                                    echo 'LoL Library';
        ?>
    </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700;900&family=Exo+2:ital,wght@0,300;0,400;0,600;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ======================== SIDEBAR ======================== -->
<nav class="sidebar">
    <div class="logo">
        <span class="gold">LOL</span>-FTW
    </div>

    <ul class="nav-links">
        <li><a href="index.php?page=accueil" class="<?= $page === 'accueil' ? 'active' : '' ?>">🏠 Accueil</a></li>
        <li><a href="index.php?page=champions" class="<?= $page === 'champions' ? 'active' : '' ?>">⚔ Champions</a></li>
    </ul>

    <div class="nav-bottom">
        <?php if (isLogged()): ?>
            <div class="user-badge">
                <span class="user-avatar-small">
                    <?= strtoupper(substr($_SESSION['user']['username'], 0, 1)) ?>
                </span>
                <div class="user-info-small">
                    <span class="user-name-small"><?= h($_SESSION['user']['username']) ?></span>
                    <span class="user-role-small"><?= h($_SESSION['user']['role']) ?></span>
                </div>
            </div>
            <a href="index.php?page=profile" class="btn-account btn-profile">Mon profil</a>
            <a href="index.php?logout=1" class="btn-logout">Déconnexion</a>
        <?php else: ?>
            <a href="index.php?page=register" class="btn-account">Créer un compte</a>
            <a href="index.php?page=login" class="btn-login">Se connecter</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ======================== CONTENU PRINCIPAL ======================== -->
<div class="main-content">

<?php
// ============================================================
//  PAGE : ACCUEIL
// ============================================================
if ($page === 'accueil'): ?>

    <section class="welcome-section">
        <div class="welcome-glow"></div>
        <h1>Bienvenue<?= isLogged() ? ', ' . h($_SESSION['user']['username']) : ', Invocateur' ?></h1>
        <p class="welcome-sub">Ta bibliothèque de champions League of Legends.</p>

        <div class="home-cards">
            <a href="index.php?page=champions" class="home-card">
                <span class="home-card-icon">⚔</span>
                <span class="home-card-label">Voir les Champions</span>
            </a>
            <?php if (!isLogged()): ?>
            <a href="index.php?page=register" class="home-card">
                <span class="home-card-icon">✦</span>
                <span class="home-card-label">Créer un compte</span>
            </a>
            <?php else: ?>
            <a href="index.php?page=profile" class="home-card">
                <span class="home-card-icon">👤</span>
                <span class="home-card-label">Mon profil</span>
            </a>
            <?php endif; ?>
        </div>
    </section>

<?php
// ============================================================
//  PAGE : LISTE DES CHAMPIONS
// ============================================================
elseif ($page === 'champions'): ?>

    <section class="champions-section">
        <div class="filter-header">
            <h2>Champions</h2>
            <div class="filters-row">
                <input type="text" id="searchInput" placeholder="🔍 Rechercher…" oninput="filterChampions()" class="filter-search">
                <select id="roleFilter" onchange="filterChampions()" class="filter-select">
                    <option value="all">Tous les rôles</option>
                    <option value="Assassin">Assassin</option>
                    <option value="Fighter">Fighter</option>
                    <option value="Mage">Mage</option>
                    <option value="Marksman">Marksman</option>
                    <option value="Support">Support</option>
                    <option value="Tank">Tank</option>
                </select>
            </div>
        </div>

        <div class="champion-grid" id="championGrid">
            <?php
            $query = $pdo->query("SELECT * FROM champions ORDER BY name ASC");
            while ($champ = $query->fetch()):
                $img = $champ['image_url'];
            ?>
            <a href="index.php?page=champion&id=<?= $champ['id'] ?>" class="card" data-role="<?= h($champ['role_primary']) ?>" data-name="<?= strtolower(h($champ['name'])) ?>">
                <div class="card-img">
                    <img
                        src="https://ddragon.leagueoflegends.com/cdn/img/champion/loading/<?= h($img) ?>_0.jpg"
                        alt="<?= h($champ['name']) ?>"
                        loading="lazy">
                </div>
                <div class="card-content">
                    <h3><?= h($champ['name']) ?></h3>
                    <p class="card-role"><?= h($champ['role_primary']) ?></p>
                    <?php if (!empty($champ['lane'])): ?>
                        <p class="card-lane">🗺 <?= h($champ['lane']) ?></p>
                    <?php endif; ?>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
    </section>

<?php
// ============================================================
//  PAGE : DÉTAIL CHAMPION
// ============================================================
elseif ($page === 'champion'):
    if (!$champion): ?>
        <div class="error-block">Champion introuvable.</div>
    <?php else:
        $splash   = "https://ddragon.leagueoflegends.com/cdn/img/champion/splash/{$champion['image_url']}_0.jpg";
        $lore     = $ddragon['lore']  ?? $champion['lore'] ?? '';
        $spells   = $ddragon['spells'] ?? [];
        $passive  = $ddragon['passive'] ?? null;
        $stats    = $ddragon['stats']   ?? [];
        $tags     = $ddragon['tags']    ?? [];
        $tips     = $ddragon['allytips'] ?? [];
        // Lanes jouables depuis BDD
        $lanes_raw = $champion['lane'] ?? '';
        $lanes_arr = $lanes_raw ? array_map('trim', explode(',', $lanes_raw)) : [];
        $lane_icons = ['Top'=>'🔼','Jungle'=>'🌿','Mid'=>'⚡','Bot'=>'🎯','Support'=>'💙'];
    ?>

    <!-- Splash art hero -->
    <div class="champion-hero" style="--splash: url('<?= $splash ?>')">
        <div class="champion-hero-overlay"></div>
        <div class="champion-hero-content">
            <a href="index.php?page=champions" class="breadcrumb">← Champions</a>
            <h1 class="champion-hero-name"><?= h($champion['name']) ?></h1>
            <p class="champion-hero-title"><?= h($champion['title'] ?? ($ddragon['title'] ?? '')) ?></p>
            <div class="champion-hero-tags">
                <?php foreach ($tags as $tag): ?>
                    <span class="hero-tag"><?= h($tag) ?></span>
                <?php endforeach; ?>
                <?php if (empty($tags)): ?>
                    <span class="hero-tag"><?= h($champion['role_primary']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="champion-body">

        <!-- ---- BLOC INFO : Lanes + Stats ---- -->
        <div class="champion-info-grid">

            <!-- Lanes jouables -->
            <div class="info-block">
                <h3 class="block-title">Lanes jouées</h3>
                <?php if ($lanes_arr): ?>
                    <div class="lanes-list">
                        <?php foreach ($lanes_arr as $lane): ?>
                            <span class="lane-badge">
                                <?= $lane_icons[$lane] ?? '🗺' ?> <?= h($lane) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Non renseignées en BDD.</p>
                <?php endif; ?>
            </div>

            <!-- Difficulté -->
            <div class="info-block">
                <h3 class="block-title">Difficulté</h3>
                <div class="difficulty-bar">
                    <?php
                    $diff = $ddragon['info']['difficulty'] ?? $champion['difficulty'] ?? 0;
                    $pct  = min(100, ($diff / 10) * 100);
                    ?>
                    <div class="diff-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <span class="diff-label">
                    <?php
                    if ($diff >= 8)      echo 'Difficile';
                    elseif ($diff >= 5)  echo 'Moyen';
                    else                 echo 'Facile';
                    ?>
                </span>
            </div>

            <!-- Note communauté -->
            <?php if ($avg_rating['total'] > 0): ?>
            <div class="info-block">
                <h3 class="block-title">Note communauté</h3>
                <div class="stars-display">
                    <?php
                    $avg = round((float)$avg_rating['avg']);
                    for ($s = 1; $s <= 5; $s++):
                    ?>
                        <span class="star <?= $s <= $avg ? 'star-on' : '' ?>">★</span>
                    <?php endfor; ?>
                </div>
                <span class="muted"><?= number_format((float)$avg_rating['avg'], 1) ?>/5 — <?= $avg_rating['total'] ?> avis</span>
            </div>
            <?php endif; ?>

            <!-- Liens Meta -->
            <div class="info-block">
                <h3 class="block-title">Meta &amp; Builds</h3>
                <div class="meta-links">
                    <?php $slug = strtolower(str_replace(["'", " "], ['', ''], $champion['name'])); ?>
                    <a href="https://www.op.gg/champions/<?= $slug ?>" target="_blank" class="meta-btn">OP.GG</a>
                    <a href="https://u.gg/lol/champions/<?= $slug ?>/build" target="_blank" class="meta-btn">U.GG</a>
                    <a href="https://lolalytics.com/lol/<?= $slug ?>/build/" target="_blank" class="meta-btn">Lolalytics</a>
                </div>
            </div>
        </div>

        <!-- ---- LORE ---- -->
        <?php if ($lore): ?>
        <div class="champion-section">
            <h2 class="section-heading">Histoire</h2>
            <p class="lore-text"><?= nl2br(h($lore)) ?></p>
        </div>
        <?php endif; ?>

        <!-- ---- SORTS (DDragon) ---- -->
        <?php if ($passive || $spells): ?>
        <div class="champion-section">
            <h2 class="section-heading">Capacités</h2>
            <div class="spells-grid">
                <?php if ($passive): ?>
                <div class="spell-card">
                    <img src="https://ddragon.leagueoflegends.com/cdn/14.10.1/img/passive/<?= h($passive['image']['full']) ?>" alt="Passif" class="spell-icon">
                    <div class="spell-info">
                        <span class="spell-key">Passif</span>
                        <span class="spell-name"><?= h($passive['name']) ?></span>
                        <p class="spell-desc"><?= h(substr(strip_tags($passive['description']), 0, 200)) ?>…</p>
                    </div>
                </div>
                <?php endif; ?>
                <?php $keys = ['Q','W','E','R']; $i = 0; ?>
                <?php foreach ($spells as $spell): ?>
                <div class="spell-card">
                    <img src="https://ddragon.leagueoflegends.com/cdn/14.10.1/img/spell/<?= h($spell['image']['full']) ?>" alt="<?= h($spell['name']) ?>" class="spell-icon">
                    <div class="spell-info">
                        <span class="spell-key"><?= $keys[$i++] ?? '?' ?></span>
                        <span class="spell-name"><?= h($spell['name']) ?></span>
                        <p class="spell-desc"><?= h(substr(strip_tags($spell['description']), 0, 200)) ?>…</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ---- CONSEILS ---- -->
        <?php if ($tips): ?>
        <div class="champion-section">
            <h2 class="section-heading">Conseils d'allié</h2>
            <ul class="tips-list">
                <?php foreach (array_slice($tips, 0, 3) as $tip): ?>
                    <li><?= h($tip) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ---- COMMENTAIRES ---- -->
        <div class="champion-section" id="comments">
            <h2 class="section-heading">
                Commentaires <span class="comment-count"><?= count($comments) ?></span>
            </h2>

            <?php if (isLogged()): ?>
            <!-- Formulaire -->
            <form method="POST" action="index.php?page=champion&id=<?= $champion['id'] ?>#comments" class="comment-form">
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="champion_id" value="<?= $champion['id'] ?>">

                <div class="rating-row">
                    <span class="rating-label">Note :</span>
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                        <input type="radio" name="rating" id="r<?= $s ?>" value="<?= $s ?>">
                        <label for="r<?= $s ?>" class="star-input">★</label>
                    <?php endfor; ?>
                </div>

                <textarea name="content" class="comment-textarea" rows="4"
                    placeholder="Ton avis sur <?= h($champion['name']) ?>…" required></textarea>
                <button type="submit" class="btn-comment">Publier</button>
            </form>
            <?php else: ?>
            <div class="login-prompt">
                <a href="index.php?page=login">Connecte-toi</a> pour laisser un commentaire.
            </div>
            <?php endif; ?>

            <!-- Liste -->
            <div class="comments-list">
                <?php if (empty($comments)): ?>
                    <p class="muted no-comments">Aucun commentaire — sois le premier ! ✦</p>
                <?php else: ?>
                    <?php foreach ($comments as $cm): ?>
                    <div class="comment-item">
                        <div class="comment-header">
                            <span class="comment-avatar"><?= strtoupper(substr($cm['username'], 0, 1)) ?></span>
                            <div class="comment-meta">
                                <span class="comment-author"><?= h($cm['username']) ?></span>
                                <?php if ($cm['rating']): ?>
                                <div class="comment-stars">
                                    <?php for ($s=1;$s<=5;$s++) echo '<span class="star '.($s<=$cm['rating']?'star-on':'').'">★</span>'; ?>
                                </div>
                                <?php endif; ?>
                                <span class="comment-date"><?= date('d/m/Y à H:i', strtotime($cm['created_at'])) ?></span>
                            </div>
                            <?php if (isLogged() && (isAdmin() || $_SESSION['user']['id'] == $cm['user_id'])): ?>
                            <a href="index.php?delete_comment=<?= $cm['id'] ?>&champion_id=<?= $champion['id'] ?>"
                               class="comment-delete" onclick="return confirm('Supprimer ce commentaire ?')">✕</a>
                            <?php endif; ?>
                        </div>
                        <p class="comment-body"><?= nl2br(h($cm['content'])) ?></p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /champion-body -->
    <?php endif; // $champion ?>

<?php
// ============================================================
//  PAGE : INSCRIPTION
// ============================================================
elseif ($page === 'register'): ?>

    <section class="auth-section">
        <div class="auth-box">
            <h2 class="auth-title">Créer un compte</h2>
            <?php if (!empty($error_reg)): ?>
                <div class="alert-error"><?= h($error_reg) ?></div>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <input type="hidden" name="action" value="register">
                <div class="form-group">
                    <label>Pseudo</label>
                    <input type="text" name="username" required minlength="3" maxlength="50"
                           value="<?= h($_POST['username'] ?? '') ?>" placeholder="Melisbroken">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required
                           value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@example.com">
                </div>
                <div class="form-group">
                    <label>Mot de passe <small>(8 caractères min)</small></label>
                    <input type="password" name="password" required minlength="8" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Confirmer le mot de passe</label>
                    <input type="password" name="confirm" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn-auth">S'inscrire</button>
                <p class="auth-switch">Déjà un compte ? <a href="index.php?page=login">Se connecter</a></p>
            </form>
        </div>
    </section>

<?php
// ============================================================
//  PAGE : CONNEXION
// ============================================================
elseif ($page === 'login'): ?>

    <section class="auth-section">
        <div class="auth-box">
            <h2 class="auth-title">Connexion</h2>
            <?php if (!empty($error_login)): ?>
                <div class="alert-error"><?= h($error_login) ?></div>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required
                           value="<?= h($_POST['email'] ?? '') ?>" placeholder="toi@example.com">
                </div>
                <div class="form-group">
                    <label>Mot de passe</label>
                    <input type="password" name="password" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn-auth">Se connecter</button>
                <p class="auth-switch">Pas encore de compte ? <a href="index.php?page=register">S'inscrire</a></p>
            </form>
        </div>
    </section>

<?php
// ============================================================
//  PAGE : PROFIL
// ============================================================
elseif ($page === 'profile'):
    if (!isLogged()) redirect('index.php?page=login');
    // Récupère les derniers commentaires de l'user
    $stmt = $pdo->prepare("
        SELECT c.*, ch.name AS champion_name, ch.id AS champion_id, ch.image_url
        FROM comments c
        JOIN champions ch ON c.champion_id = ch.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user']['id']]);
    $my_comments = $stmt->fetchAll();
?>

    <section class="profile-section">
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($_SESSION['user']['username'], 0, 1)) ?>
            </div>
            <div class="profile-info">
                <h1><?= h($_SESSION['user']['username']) ?></h1>
                <span class="profile-role <?= isAdmin() ? 'role-admin' : 'role-user' ?>">
                    <?= isAdmin() ? '⚙ Admin' : '✦ Invocateur' ?>
                </span>
                <p class="profile-email"><?= h($_SESSION['user']['email']) ?></p>
            </div>
        </div>

        <div class="profile-body">
            <h2 class="section-heading">Mes derniers commentaires</h2>
            <?php if (empty($my_comments)): ?>
                <p class="muted">Tu n'as pas encore commenté de champion. <a href="index.php?page=champions">Explore !</a></p>
            <?php else: ?>
                <div class="profile-comments">
                    <?php foreach ($my_comments as $mc): ?>
                    <div class="profile-comment-item">
                        <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?= h($mc['image_url']) ?>_0.jpg"
                             alt="" class="profile-champ-thumb">
                        <div class="profile-comment-info">
                            <a href="index.php?page=champion&id=<?= $mc['champion_id'] ?>" class="profile-champ-name">
                                <?= h($mc['champion_name']) ?>
                            </a>
                            <?php if ($mc['rating']): ?>
                            <div class="comment-stars-small">
                                <?php for ($s=1;$s<=5;$s++) echo '<span class="star '.($s<=$mc['rating']?'star-on':'').'">★</span>'; ?>
                            </div>
                            <?php endif; ?>
                            <p class="profile-comment-text"><?= h(substr($mc['content'], 0, 120)) ?><?= strlen($mc['content']) > 120 ? '…' : '' ?></p>
                            <span class="comment-date"><?= date('d/m/Y', strtotime($mc['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

<?php endif; // routing ?>

</div><!-- /main-content -->

<script>
// Filtre champions (liste)
function filterChampions() {
    const role   = document.getElementById('roleFilter')?.value ?? 'all';
    const search = (document.getElementById('searchInput')?.value ?? '').toLowerCase();
    document.querySelectorAll('#championGrid .card').forEach(card => {
        const matchRole = role === 'all' || card.dataset.role === role;
        const matchName = card.dataset.name.includes(search);
        card.style.display = (matchRole && matchName) ? '' : 'none';
    });
}

// Burger mobile (optionnel)
document.addEventListener('DOMContentLoaded', () => {
    // Anime les cartes champion à l'entrée
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, i) => {
        card.style.animationDelay = `${i * 40}ms`;
        card.classList.add('card-appear');
    });
});
</script>

</body>
</html>