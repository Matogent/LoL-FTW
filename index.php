<?php
// ============================================
session_start();
include 'db.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function isLogged(): bool      { return isset($_SESSION['user']); }
function isAdmin(): bool       { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function redirect(string $url): void { header("Location: $url"); exit; }

function refreshSession(PDO $pdo): void {
    if (!isLogged()) return;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $u = $stmt->fetch();
    if ($u) $_SESSION['user'] = [
        'id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'],
        'role' => $u['role'], 'avatar_url' => $u['avatar_url'] ?? null,
        'lane_main' => $u['lane_main'] ?? null, 'lane_second' => $u['lane_second'] ?? null,
    ];
}

function avatarHtml(array $user, string $size = 'sm'): string {
    $px   = match($size) { 'lg' => '100px', 'md' => '52px', default => '34px' };
    $font = match($size) { 'lg' => '2rem',  'md' => '1.2rem', default => '.85rem' };
    $initial = strtoupper(substr($user['username'] ?? '?', 0, 1));
    if (!empty($user['avatar_url']))
        return '<img src="uploads/avatars/'.h($user['avatar_url']).'" alt="'.h($user['username']).'"
                     style="width:'.$px.';height:'.$px.';border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0;">';
    return '<span class="avatar-initial" style="width:'.$px.';height:'.$px.';font-size:'.$font.';">'.$initial.'</span>';
}

function friendshipStatus(PDO $pdo, int $myId, int $otherId): string {
    $stmt = $pdo->prepare("SELECT status, sender_id FROM friendships WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) LIMIT 1");
    $stmt->execute([$myId, $otherId, $otherId, $myId]);
    $row = $stmt->fetch();
    if (!$row) return 'none';
    if ($row['status'] === 'accepted') return 'friends';
    if ($row['status'] === 'pending' && $row['sender_id'] == $myId) return 'pending_sent';
    if ($row['status'] === 'pending' && $row['sender_id'] == $otherId) return 'pending_received';
    return 'declined';
}

$LANES      = ['Top', 'Jungle', 'Mid', 'Bot', 'Support'];
$LANE_ICONS = ['Top'=>'🔼','Jungle'=>'🌿','Mid'=>'⚡','Bot'=>'🎯','Support'=>'💙'];

// ============================================================
//  ACTIONS
// ============================================================

// INSCRIPTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $username = trim($_POST['username'] ?? ''); $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; $confirm = $_POST['confirm'] ?? '';
    $error_reg = '';
    if (strlen($username) < 3)                          $error_reg = 'Pseudo trop court (3 min).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error_reg = 'Email invalide.';
    elseif (strlen($password) < 8)                      $error_reg = 'Mot de passe trop court (8 min).';
    elseif ($password !== $confirm)                     $error_reg = 'Les mots de passe ne correspondent pas.';
    else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? OR username=?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) { $error_reg = 'Ce pseudo ou email est déjà utilisé.'; }
        else {
            $pdo->prepare("INSERT INTO users (username,email,password) VALUES (?,?,?)")->execute([$username,$email,password_hash($password,PASSWORD_BCRYPT)]);
            $_SESSION['user'] = ['id'=>(int)$pdo->lastInsertId(),'username'=>$username,'email'=>$email,'role'=>'user','avatar_url'=>null,'lane_main'=>null,'lane_second'=>null];
            redirect('index.php?page=accueil');
        }
    }
}

// CONNEXION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = trim($_POST['email'] ?? ''); $password = $_POST['password'] ?? '';
    $error_login = '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=?"); $stmt->execute([$email]); $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) { $error_login = 'Email ou mot de passe incorrect.'; }
    else {
        $_SESSION['user'] = ['id'=>(int)$user['id'],'username'=>$user['username'],'email'=>$user['email'],'role'=>$user['role'],'avatar_url'=>$user['avatar_url']??null,'lane_main'=>$user['lane_main']??null,'lane_second'=>$user['lane_second']??null];
        redirect('index.php?page=accueil');
    }
}

// DÉCONNEXION
if (isset($_GET['logout'])) { session_destroy(); redirect('index.php?page=accueil'); }

// UPLOAD AVATAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
    if (!isLogged()) redirect('index.php?page=login');
    $uploadDir = 'uploads/avatars/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $file = $_FILES['avatar'] ?? null; $error_avatar = '';
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) { $error_avatar = 'Erreur upload.'; }
    else {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mimeType = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
        if (!in_array($mimeType,$allowed))      $error_avatar = 'Format non supporté (JPG,PNG,GIF,WEBP).';
        elseif ($file['size'] > 2*1024*1024)    $error_avatar = 'Image trop lourde (2Mo max).';
        else {
            if (!empty($_SESSION['user']['avatar_url'])) { $old=$uploadDir.$_SESSION['user']['avatar_url']; if(file_exists($old)) unlink($old); }
            $ext = match($mimeType){'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif',default=>'webp'};
            $filename = 'avatar_'.$_SESSION['user']['id'].'_'.time().'.'.$ext;
            move_uploaded_file($file['tmp_name'], $uploadDir.$filename);
            $pdo->prepare("UPDATE users SET avatar_url=? WHERE id=?")->execute([$filename,$_SESSION['user']['id']]);
            $_SESSION['user']['avatar_url'] = $filename;
        }
    }
    redirect('index.php?page=profile'.($error_avatar?'&avatar_error='.urlencode($error_avatar):'&avatar_ok=1'));
}

// SUPPRIMER AVATAR
if (isset($_GET['delete_avatar']) && isLogged()) {
    if (!empty($_SESSION['user']['avatar_url'])) { $f='uploads/avatars/'.$_SESSION['user']['avatar_url']; if(file_exists($f)) unlink($f); }
    $pdo->prepare("UPDATE users SET avatar_url=NULL WHERE id=?")->execute([$_SESSION['user']['id']]);
    $_SESSION['user']['avatar_url'] = null;
    redirect('index.php?page=profile');
}

// SAUVEGARDER LANES + CHAMPIONS MAÎTRISÉS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gaming_profile') {
    if (!isLogged()) redirect('index.php?page=login');
    $uid = $_SESSION['user']['id'];
    $lane_main   = in_array($_POST['lane_main']   ?? '', $LANES) ? $_POST['lane_main']   : null;
    $lane_second = in_array($_POST['lane_second'] ?? '', $LANES) ? $_POST['lane_second'] : null;
    if ($lane_main && $lane_main === $lane_second) $lane_second = null;
    $pdo->prepare("UPDATE users SET lane_main=?, lane_second=? WHERE id=?")->execute([$lane_main, $lane_second, $uid]);

    $champ_ids = array_values(array_unique(array_filter(
        array_map('intval', array_slice($_POST['mastered_champions'] ?? [], 0, 4)),
        fn($id) => $id > 0
    )));
    if ($champ_ids) {
        $pl = implode(',', array_fill(0, count($champ_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM champions WHERE id IN ($pl)"); $stmt->execute($champ_ids);
        $valid_ids = array_column($stmt->fetchAll(), 'id');
    } else { $valid_ids = []; }

    $pdo->prepare("DELETE FROM user_champions WHERE user_id=?")->execute([$uid]);
    foreach ($valid_ids as $pos => $cid)
        $pdo->prepare("INSERT INTO user_champions (user_id,champion_id,position) VALUES (?,?,?)")->execute([$uid,$cid,$pos+1]);

    refreshSession($pdo);
    redirect('index.php?page=profile&tab=setup&saved=1');
}

// AMIS
if (isset($_GET['friend_add']) && isLogged()) {
    $targetId = (int)$_GET['friend_add'];
    if ($targetId !== $_SESSION['user']['id']) {
        $stmt = $pdo->prepare("SELECT id FROM friendships WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
        $stmt->execute([$_SESSION['user']['id'],$targetId,$targetId,$_SESSION['user']['id']]);
        if (!$stmt->fetch()) $pdo->prepare("INSERT INTO friendships (sender_id,receiver_id) VALUES (?,?)")->execute([$_SESSION['user']['id'],$targetId]);
    }
    $back = $_GET['from'] ?? 'profile';
    redirect("index.php?page=$back".($back==='user'?"&id=$targetId":''));
}
if (isset($_GET['friend_accept']) && isLogged()) {
    $pdo->prepare("UPDATE friendships SET status='accepted' WHERE id=? AND receiver_id=?")->execute([(int)$_GET['friend_accept'],$_SESSION['user']['id']]);
    redirect('index.php?page=profile&tab=friends');
}
if (isset($_GET['friend_remove']) && isLogged()) {
    $pdo->prepare("DELETE FROM friendships WHERE id=? AND (sender_id=? OR receiver_id=?)")->execute([(int)$_GET['friend_remove'],$_SESSION['user']['id'],$_SESSION['user']['id']]);
    redirect('index.php?page=profile&tab=friends');
}

// COMMENTAIRES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment') {
    if (!isLogged()) redirect('index.php?page=login');
    $champion_id=(int)($_POST['champion_id']??0); $content=trim($_POST['content']??''); $rating=!empty($_POST['rating'])?(int)$_POST['rating']:null;
    if ($content && $champion_id) {
        $stmt=$pdo->prepare("SELECT id FROM comments WHERE champion_id=? AND user_id=?"); $stmt->execute([$champion_id,$_SESSION['user']['id']]);
        if ($stmt->fetch()) $pdo->prepare("UPDATE comments SET content=?,rating=?,created_at=NOW() WHERE champion_id=? AND user_id=?")->execute([$content,$rating,$champion_id,$_SESSION['user']['id']]);
        else $pdo->prepare("INSERT INTO comments (champion_id,user_id,content,rating) VALUES (?,?,?,?)")->execute([$champion_id,$_SESSION['user']['id'],$content,$rating]);
    }
    redirect("index.php?page=champion&id=$champion_id#comments");
}
if (isset($_GET['delete_comment'])) {
    if (!isLogged()) redirect('index.php');
    $cid=(int)$_GET['delete_comment']; $chid=(int)($_GET['champion_id']??0);
    if (isAdmin()) $pdo->prepare("DELETE FROM comments WHERE id=?")->execute([$cid]);
    else $pdo->prepare("DELETE FROM comments WHERE id=? AND user_id=?")->execute([$cid,$_SESSION['user']['id']]);
    redirect("index.php?page=champion&id=$chid#comments");
}

// ============================================================
//  ROUTING
// ============================================================
$page        = $_GET['page'] ?? 'accueil';
$champion_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$profile_tab = $_GET['tab'] ?? 'comments';

// Données champion
$champion=$ddragon=null; $comments=[]; $avg_rating=['avg'=>0,'total'=>0];
if ($page==='champion' && $champion_id) {
    $stmt=$pdo->prepare("SELECT * FROM champions WHERE id=?"); $stmt->execute([$champion_id]); $champion=$stmt->fetch();
    if ($champion) {
        $imgKey=$champion['image_url'];
        $json=@file_get_contents("https://ddragon.leagueoflegends.com/cdn/14.10.1/data/fr_FR/champion/{$imgKey}.json");
        if ($json) { $dec=json_decode($json,true); $ddragon=$dec['data'][$imgKey]??null; }
        $stmt=$pdo->prepare("SELECT c.*,u.username,u.avatar_url FROM comments c JOIN users u ON c.user_id=u.id WHERE c.champion_id=? ORDER BY c.created_at DESC");
        $stmt->execute([$champion_id]); $comments=$stmt->fetchAll();
        $stmt=$pdo->prepare("SELECT AVG(rating) as avg,COUNT(*) as total FROM comments WHERE champion_id=? AND rating IS NOT NULL");
        $stmt->execute([$champion_id]); $avg_rating=$stmt->fetch();
    }
}

// Données profil
$my_comments=$friends=$pending_received=$pending_sent=$my_mastered=$all_champions=[];
$me=null;
if ($page==='profile' && isLogged()) {
    $uid=$_SESSION['user']['id'];
    $stmt=$pdo->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$uid]); $me=$stmt->fetch();
    $stmt=$pdo->prepare("SELECT c.*,ch.name AS champion_name,ch.id AS champion_id,ch.image_url FROM comments c JOIN champions ch ON c.champion_id=ch.id WHERE c.user_id=? ORDER BY c.created_at DESC LIMIT 10");
    $stmt->execute([$uid]); $my_comments=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT ch.*,uc.position FROM user_champions uc JOIN champions ch ON uc.champion_id=ch.id WHERE uc.user_id=? ORDER BY uc.position");
    $stmt->execute([$uid]); $my_mastered=$stmt->fetchAll();
    $all_champions=$pdo->query("SELECT id,name,image_url FROM champions ORDER BY name ASC")->fetchAll();
    $stmt=$pdo->prepare("SELECT f.id as friendship_id,f.created_at as friend_since,u.id,u.username,u.avatar_url,u.role FROM friendships f JOIN users u ON u.id=IF(f.sender_id=?,f.receiver_id,f.sender_id) WHERE (f.sender_id=? OR f.receiver_id=?) AND f.status='accepted' ORDER BY u.username");
    $stmt->execute([$uid,$uid,$uid]); $friends=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT f.id as friendship_id,u.id,u.username,u.avatar_url,f.created_at FROM friendships f JOIN users u ON u.id=f.sender_id WHERE f.receiver_id=? AND f.status='pending' ORDER BY f.created_at DESC");
    $stmt->execute([$uid]); $pending_received=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT f.id as friendship_id,u.id,u.username,u.avatar_url,f.created_at FROM friendships f JOIN users u ON u.id=f.receiver_id WHERE f.sender_id=? AND f.status='pending' ORDER BY f.created_at DESC");
    $stmt->execute([$uid]); $pending_sent=$stmt->fetchAll();
}

// Profil public
$viewed_user=null; $v_mastered=[];
if ($page==='user' && isset($_GET['id'])) {
    $stmt=$pdo->prepare("SELECT id,username,avatar_url,role,created_at,lane_main,lane_second FROM users WHERE id=?");
    $stmt->execute([(int)$_GET['id']]); $viewed_user=$stmt->fetch();
    if ($viewed_user) {
        $stmt=$pdo->prepare("SELECT ch.*,uc.position FROM user_champions uc JOIN champions ch ON uc.champion_id=ch.id WHERE uc.user_id=? ORDER BY uc.position");
        $stmt->execute([$viewed_user['id']]); $v_mastered=$stmt->fetchAll();
    }
}

// Recherche joueurs
$search_results=[]; $search_mode=$_GET['search_mode']??'pseudo';
$search_query=trim($_GET['q']??''); $search_champ=(int)($_GET['champ_id']??0); $search_lane=$_GET['lane']??'';
if ($page==='find_players') {
    if ($search_mode==='pseudo' && $search_query!=='') {
        $stmt=$pdo->prepare("SELECT id,username,avatar_url,lane_main,lane_second FROM users WHERE username LIKE ? ORDER BY username LIMIT 20");
        $stmt->execute(['%'.$search_query.'%']); $search_results=$stmt->fetchAll();
    } elseif ($search_mode==='champion' && ($search_champ>0 || $search_lane!=='')) {
        $sql="SELECT DISTINCT u.id,u.username,u.avatar_url,u.lane_main,u.lane_second FROM users u JOIN user_champions uc ON uc.user_id=u.id JOIN champions ch ON ch.id=uc.champion_id WHERE 1=1";
        $params=[];
        if ($search_champ>0) { $sql.=" AND ch.id=?"; $params[]=$search_champ; }
        if ($search_lane!=='' && in_array($search_lane,$LANES)) { $sql.=" AND (u.lane_main=? OR u.lane_second=?)"; $params[]=$search_lane; $params[]=$search_lane; }
        $sql.=" ORDER BY u.username LIMIT 30";
        $stmt=$pdo->prepare($sql); $stmt->execute($params); $search_results=$stmt->fetchAll();
    }
    foreach ($search_results as &$sr) {
        $stmt=$pdo->prepare("SELECT ch.name,ch.image_url FROM user_champions uc JOIN champions ch ON ch.id=uc.champion_id WHERE uc.user_id=? ORDER BY uc.position LIMIT 4");
        $stmt->execute([$sr['id']]); $sr['mastered']=$stmt->fetchAll();
    } unset($sr);
}

// Badge demandes en attente
$pending_count=0;
if (isLogged()) {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM friendships WHERE receiver_id=? AND status='pending'");
    $stmt->execute([$_SESSION['user']['id']]); $pending_count=(int)$stmt->fetchColumn();
}

// Liste champions pour filtres recherche
$all_champions_list=$pdo->query("SELECT id,name FROM champions ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
        if ($page==='champion'&&$champion)    echo h($champion['name']).' — LoL Library';
        elseif ($page==='find_players')       echo 'Trouver des joueurs — LoL Library';
        elseif ($page==='user'&&$viewed_user) echo h($viewed_user['username']).' — LoL Library';
        elseif ($page==='profile')            echo 'Mon Profil — LoL Library';
        else                                  echo 'LoL Library';
    ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700;900&family=Exo+2:ital,wght@0,300;0,400;0,600;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="sidebar">
    <div class="logo"><span class="gold">LOL</span>-FTW</div>
    <ul class="nav-links">
        <li><a href="index.php?page=accueil"      class="<?=$page==='accueil'?'active':''?>">🏠 Accueil</a></li>
        <li><a href="index.php?page=champions"    class="<?=$page==='champions'?'active':''?>">⚔ Champions</a></li>
        <li><a href="index.php?page=find_players" class="<?=$page==='find_players'?'active':''?>">🔎 Joueurs</a></li>
        <?php if (isAdmin()): ?>
        <li><a href="index.php?page=admin" class="<?=$page==='admin'?'active':''?>">⚙ Admin Panel</a></li>
        <?php endif; ?>
    </ul>
        <?php if (isAdmin()): ?>
        <li><a href="index.php?page=admin" class="<?=$page==='admin'?'active':''?>">⚙ Admin Panel</a></li>
        <?php endif; ?>
    <div class="nav-bottom">
        <?php if (isLogged()): ?>
            <div class="user-badge">
                <?= avatarHtml($_SESSION['user'], 'sm') ?>
                <div class="user-info-small">
                    <span class="user-name-small"><?=h($_SESSION['user']['username'])?></span>
                    <span class="user-role-small"><?=h($_SESSION['user']['role'])?></span>
                </div>
            </div>
            <a href="index.php?page=profile" class="btn-account btn-profile">
                Mon profil <?php if($pending_count>0) echo '<span class="badge-notif">'.$pending_count.'</span>'; ?>
            </a>
            <a href="index.php?logout=1" class="btn-logout">Déconnexion</a>
        <?php else: ?>
            <a href="index.php?page=register" class="btn-account">Créer un compte</a>
            <a href="index.php?page=login"    class="btn-login">Se connecter</a>
        <?php endif; ?>
    </div>
</nav>

<div class="main-content">

<?php if ($page==='accueil'): ?>
<!-- ====== ACCUEIL ====== -->
<section class="welcome-section">
    <div class="welcome-glow"></div>
    <h1>Bienvenue<?= isLogged()?', '.h($_SESSION['user']['username']):', Invocateur'?></h1>
    <p class="welcome-sub">Ta bibliothèque de champions League of Legends.</p>
    <div class="home-cards">
        <a href="index.php?page=champions"    class="home-card"><span class="home-card-icon">⚔</span><span class="home-card-label">Champions</span></a>
        <a href="index.php?page=find_players" class="home-card"><span class="home-card-icon">🔎</span><span class="home-card-label">Trouver des joueurs</span></a>
        <?php if(!isLogged()): ?>
        <a href="index.php?page=register" class="home-card"><span class="home-card-icon">✦</span><span class="home-card-label">Créer un compte</span></a>
        <?php else: ?>
        <a href="index.php?page=profile"  class="home-card"><span class="home-card-icon">👤</span><span class="home-card-label">Mon profil</span></a>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($page==='champions'): ?>
<!-- ====== CHAMPIONS ====== -->
<section class="champions-section">
    <div class="filter-header">
        <h2>Champions</h2>
        <div class="filters-row">
            <input type="text" id="searchInput" placeholder="🔍 Rechercher…" oninput="filterChampions()" class="filter-search">
            <select id="roleFilter" onchange="filterChampions()" class="filter-select">
                <option value="all">Tous les rôles</option>
                <option value="Assassin">Assassin</option><option value="Fighter">Fighter</option>
                <option value="Mage">Mage</option><option value="Marksman">Marksman</option>
                <option value="Support">Support</option><option value="Tank">Tank</option>
            </select>
        </div>
    </div>
    <div class="champion-grid" id="championGrid">
        <?php $q=$pdo->query("SELECT * FROM champions ORDER BY name ASC"); while($champ=$q->fetch()): ?>
        <a href="index.php?page=champion&id=<?=$champ['id']?>" class="card" data-role="<?=h($champ['role_primary'])?>" data-name="<?=strtolower(h($champ['name']))?>">
            <div class="card-img"><img src="https://ddragon.leagueoflegends.com/cdn/img/champion/loading/<?=h($champ['image_url'])?>_0.jpg" alt="<?=h($champ['name'])?>" loading="lazy"></div>
            <div class="card-content">
                <h3><?=h($champ['name'])?></h3>
                <p class="card-role"><?=h($champ['role_primary'])?></p>
                <?php if(!empty($champ['lane'])): ?><p class="card-lane">🗺 <?=h($champ['lane'])?></p><?php endif; ?>
            </div>
        </a>
        <?php endwhile; ?>
    </div>
</section>

<?php elseif ($page==='champion'): ?>
<!-- ====== DÉTAIL CHAMPION ====== -->
<?php if(!$champion): ?><div class="error-block">Champion introuvable.</div>
<?php else:
    $splash="https://ddragon.leagueoflegends.com/cdn/img/champion/splash/{$champion['image_url']}_0.jpg";
    $lore=$ddragon['lore']??$champion['lore']??''; $spells=$ddragon['spells']??[];
    $passive=$ddragon['passive']??null; $tags=$ddragon['tags']??[]; $tips=$ddragon['allytips']??[];
    $lanes_arr=!empty($champion['lane'])?array_map('trim',explode(',',$champion['lane'])):[];
?>
<div class="champion-hero" style="--splash: url('<?=$splash?>')">
    <div class="champion-hero-overlay"></div>
    <div class="champion-hero-content">
        <a href="index.php?page=champions" class="breadcrumb">← Champions</a>
        <h1 class="champion-hero-name"><?=h($champion['name'])?></h1>
        <p class="champion-hero-title"><?=h($champion['title']??($ddragon['title']??''))?></p>
        <div class="champion-hero-tags">
            <?php foreach($tags?:[$champion['role_primary']] as $t) echo '<span class="hero-tag">'.h($t).'</span>'; ?>
        </div>
    </div>
</div>
<div class="champion-body">
    <div class="champion-info-grid">
        <div class="info-block">
            <h3 class="block-title">Lanes jouées</h3>
            <?php if($lanes_arr): ?>
                <div class="lanes-list"><?php foreach($lanes_arr as $l) echo '<span class="lane-badge">'.($LANE_ICONS[$l]??'🗺').' '.h($l).'</span>'; ?></div>
            <?php else: ?><p class="muted">Non renseignées.</p><?php endif; ?>
        </div>
        <div class="info-block">
            <h3 class="block-title">Difficulté</h3>
            <?php $diff=$ddragon['info']['difficulty']??$champion['difficulty']??0; $pct=min(100,($diff/10)*100); ?>
            <div class="difficulty-bar"><div class="diff-fill" style="width:<?=$pct?>%"></div></div>
            <span class="diff-label"><?=$diff>=8?'Difficile':($diff>=5?'Moyen':'Facile')?></span>
        </div>
        <?php if($avg_rating['total']>0): ?>
        <div class="info-block">
            <h3 class="block-title">Note communauté</h3>
            <div class="stars-display"><?php $avg=round((float)$avg_rating['avg']); for($s=1;$s<=5;$s++) echo '<span class="star '.($s<=$avg?'star-on':'').'">★</span>'; ?></div>
            <span class="muted"><?=number_format((float)$avg_rating['avg'],1)?>/5 — <?=$avg_rating['total']?> avis</span>
        </div>
        <?php endif; ?>
        <div class="info-block">
            <h3 class="block-title">Meta &amp; Builds</h3>
            <?php $slug=strtolower(str_replace(["'"," "],['',''],$champion['name'])); ?>
            <div class="meta-links">
                <a href="https://www.op.gg/champions/<?=$slug?>" target="_blank" class="meta-btn">OP.GG</a>
                <a href="https://u.gg/lol/champions/<?=$slug?>/build" target="_blank" class="meta-btn">U.GG</a>
                <a href="https://lolalytics.com/lol/<?=$slug?>/build/" target="_blank" class="meta-btn">Lolalytics</a>
            </div>
        </div>
    </div>
    <?php if($lore): ?><div class="champion-section"><h2 class="section-heading">Histoire</h2><p class="lore-text"><?=nl2br(h($lore))?></p></div><?php endif; ?>
    <?php if($passive||$spells): ?>
    <div class="champion-section"><h2 class="section-heading">Capacités</h2><div class="spells-grid">
        <?php if($passive): ?><div class="spell-card"><img src="https://ddragon.leagueoflegends.com/cdn/14.10.1/img/passive/<?=h($passive['image']['full'])?>" class="spell-icon" alt=""><div class="spell-info"><span class="spell-key">Passif</span><span class="spell-name"><?=h($passive['name'])?></span><p class="spell-desc"><?=h(substr(strip_tags($passive['description']),0,200))?>…</p></div></div><?php endif; ?>
        <?php $keys=['Q','W','E','R']; $i=0; foreach($spells as $sp): ?><div class="spell-card"><img src="https://ddragon.leagueoflegends.com/cdn/14.10.1/img/spell/<?=h($sp['image']['full'])?>" class="spell-icon" alt=""><div class="spell-info"><span class="spell-key"><?=$keys[$i++]?></span><span class="spell-name"><?=h($sp['name'])?></span><p class="spell-desc"><?=h(substr(strip_tags($sp['description']),0,200))?>…</p></div></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
    <?php if($tips): ?><div class="champion-section"><h2 class="section-heading">Conseils</h2><ul class="tips-list"><?php foreach(array_slice($tips,0,3) as $t) echo '<li>'.h($t).'</li>'; ?></ul></div><?php endif; ?>
    <div class="champion-section" id="comments">
        <h2 class="section-heading">Commentaires <span class="comment-count"><?=count($comments)?></span></h2>
        <?php if(isLogged()): ?>
        <form method="POST" action="index.php?page=champion&id=<?=$champion['id']?>#comments" class="comment-form">
            <input type="hidden" name="action" value="comment"><input type="hidden" name="champion_id" value="<?=$champion['id']?>">
            <div class="rating-row"><span class="rating-label">Note :</span><?php for($s=5;$s>=1;$s--) echo '<input type="radio" name="rating" id="r'.$s.'" value="'.$s.'"><label for="r'.$s.'" class="star-input">★</label>'; ?></div>
            <textarea name="content" class="comment-textarea" rows="4" placeholder="Ton avis sur <?=h($champion['name'])?>…" required></textarea>
            <button type="submit" class="btn-comment">Publier</button>
        </form>
        <?php else: ?><div class="login-prompt"><a href="index.php?page=login">Connecte-toi</a> pour commenter.</div><?php endif; ?>
        <div class="comments-list">
            <?php if(empty($comments)): ?><p class="muted no-comments">Sois le premier ! ✦</p>
            <?php else: foreach($comments as $cm): ?>
            <div class="comment-item">
                <div class="comment-header">
                    <?= avatarHtml(['username'=>$cm['username'],'avatar_url'=>$cm['avatar_url']],'sm') ?>
                    <div class="comment-meta">
                        <a href="index.php?page=user&id=<?=$cm['user_id']?>" class="comment-author"><?=h($cm['username'])?></a>
                        <?php if($cm['rating']): ?><div class="comment-stars"><?php for($s=1;$s<=5;$s++) echo '<span class="star '.($s<=$cm['rating']?'star-on':'').'">★</span>'; ?></div><?php endif; ?>
                        <span class="comment-date"><?=date('d/m/Y à H:i',strtotime($cm['created_at']))?></span>
                    </div>
                    <?php if(isLogged()&&(isAdmin()||$_SESSION['user']['id']==$cm['user_id'])): ?>
                    <a href="index.php?delete_comment=<?=$cm['id']?>&champion_id=<?=$champion['id']?>" class="comment-delete" onclick="return confirm('Supprimer ?')">✕</a>
                    <?php endif; ?>
                </div>
                <p class="comment-body"><?=nl2br(h($cm['content']))?></p>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($page==='register'): ?>
<!-- ====== INSCRIPTION ====== -->
<section class="auth-section"><div class="auth-box">
    <h2 class="auth-title">Créer un compte</h2>
    <?php if(!empty($error_reg)) echo '<div class="alert-error">'.h($error_reg).'</div>'; ?>
    <form method="POST" class="auth-form">
        <input type="hidden" name="action" value="register">
        <div class="form-group"><label>Pseudo</label><input type="text" name="username" required minlength="3" value="<?=h($_POST['username']??'')?>" placeholder="InvocateurX"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" required value="<?=h($_POST['email']??'')?>" placeholder="toi@example.com"></div>
        <div class="form-group"><label>Mot de passe <small>(8 min)</small></label><input type="password" name="password" required minlength="8" placeholder="••••••••"></div>
        <div class="form-group"><label>Confirmer</label><input type="password" name="confirm" required placeholder="••••••••"></div>
        <button type="submit" class="btn-auth">S'inscrire</button>
        <p class="auth-switch">Déjà un compte ? <a href="index.php?page=login">Se connecter</a></p>
    </form>
</div></section>

<?php elseif ($page==='login'): ?>
<!-- ====== CONNEXION ====== -->
<section class="auth-section"><div class="auth-box">
    <h2 class="auth-title">Connexion</h2>
    <?php if(!empty($error_login)) echo '<div class="alert-error">'.h($error_login).'</div>'; ?>
    <form method="POST" class="auth-form">
        <input type="hidden" name="action" value="login">
        <div class="form-group"><label>Email</label><input type="email" name="email" required value="<?=h($_POST['email']??'')?>" placeholder="toi@example.com"></div>
        <div class="form-group"><label>Mot de passe</label><input type="password" name="password" required placeholder="••••••••"></div>
        <button type="submit" class="btn-auth">Se connecter</button>
        <p class="auth-switch">Pas encore de compte ? <a href="index.php?page=register">S'inscrire</a></p>
    </form>
</div></section>

<?php elseif ($page==='profile' && isLogged()):
    if (!$me) { $stmt=$pdo->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$_SESSION['user']['id']]); $me=$stmt->fetch(); }
    $mastered_ids = array_column($my_mastered, 'champion_id');
?>
<!-- ====== MON PROFIL ====== -->
<section class="profile-section">
    <div class="profile-header">
        <div class="profile-avatar-wrap">
            <?= avatarHtml($_SESSION['user'],'lg') ?>
            <button class="btn-avatar-change" onclick="document.getElementById('avatarInput').click()" title="Changer">✎</button>
            <?php if(!empty($_SESSION['user']['avatar_url'])): ?>
                <a href="index.php?delete_avatar=1" class="btn-avatar-delete" onclick="return confirm('Supprimer ta photo ?')">✕</a>
            <?php endif; ?>
        </div>
        <form method="POST" enctype="multipart/form-data" id="avatarForm" style="display:none;">
            <input type="hidden" name="action" value="upload_avatar">
            <input type="file" id="avatarInput" name="avatar" accept="image/*" onchange="document.getElementById('avatarForm').submit()">
        </form>
        <div class="profile-info">
            <h1><?=h($_SESSION['user']['username'])?></h1>
            <span class="profile-role <?=isAdmin()?'role-admin':'role-user'?>"><?=isAdmin()?'⚙ Admin':'✦ Invocateur'?></span>
            <p class="profile-email"><?=h($_SESSION['user']['email'])?></p>
            <?php if($me['lane_main']): ?>
            <div class="profile-lanes-display">
                <span class="lane-badge"><?=$LANE_ICONS[$me['lane_main']]??''?> <?=h($me['lane_main'])?></span>
                <?php if($me['lane_second']): ?><span class="lane-badge secondary"><?=$LANE_ICONS[$me['lane_second']]??''?> <?=h($me['lane_second'])?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if(isset($_GET['avatar_ok'])) echo '<div class="alert-success" style="margin-top:8px;">Photo mise à jour !</div>'; ?>
            <?php if(isset($_GET['avatar_error'])) echo '<div class="alert-error" style="margin-top:8px;">'.h($_GET['avatar_error']).'</div>'; ?>
        </div>
    </div>

    <?php if(!empty($my_mastered)): ?>
    <div class="mastered-strip">
        <?php foreach($my_mastered as $mc): ?>
        <a href="index.php?page=champion&id=<?=$mc['id']?>" class="mastered-mini" title="<?=h($mc['name'])?>">
            <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($mc['image_url'])?>_0.jpg" alt="<?=h($mc['name'])?>">
            <span><?=h($mc['name'])?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="profile-tabs">
        <a href="index.php?page=profile&tab=comments" class="profile-tab <?=$profile_tab==='comments'?'tab-active':''?>">💬 Commentaires</a>
        <a href="index.php?page=profile&tab=setup"    class="profile-tab <?=$profile_tab==='setup'?'tab-active':''?>">⚙ Mon Setup</a>
        <a href="index.php?page=profile&tab=friends"  class="profile-tab <?=$profile_tab==='friends'?'tab-active':''?>">
            👥 Amis <span class="tab-count"><?=count($friends)?></span>
            <?php if($pending_count>0) echo '<span class="badge-notif">'.$pending_count.'</span>'; ?>
        </a>
    </div>

    <!-- ONGLET COMMENTAIRES -->
    <?php if($profile_tab==='comments'): ?>
    <div class="profile-body">
        <?php if(empty($my_comments)): ?><p class="muted">Pas encore de commentaires. <a href="index.php?page=champions">Explore !</a></p>
        <?php else: ?><div class="profile-comments"><?php foreach($my_comments as $mc): ?>
            <div class="profile-comment-item">
                <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($mc['image_url'])?>_0.jpg" alt="" class="profile-champ-thumb">
                <div class="profile-comment-info">
                    <a href="index.php?page=champion&id=<?=$mc['champion_id']?>" class="profile-champ-name"><?=h($mc['champion_name'])?></a>
                    <?php if($mc['rating']): ?><div class="comment-stars-small"><?php for($s=1;$s<=5;$s++) echo '<span class="star '.($s<=$mc['rating']?'star-on':'').'">★</span>'; ?></div><?php endif; ?>
                    <p class="profile-comment-text"><?=h(substr($mc['content'],0,120))?><?=strlen($mc['content'])>120?'…':''?></p>
                    <span class="comment-date"><?=date('d/m/Y',strtotime($mc['created_at']))?></span>
                </div>
            </div>
        <?php endforeach; ?></div><?php endif; ?>
    </div>

    <!-- ONGLET SETUP -->
    <?php elseif($profile_tab==='setup'): ?>
    <div class="profile-body">
        <?php if(isset($_GET['saved'])) echo '<div class="alert-success">✓ Profil de jeu sauvegardé !</div><br>'; ?>
        <form method="POST" action="index.php?page=profile&tab=setup" class="setup-form">
            <input type="hidden" name="action" value="save_gaming_profile">

            <!-- LANES -->
            <div class="setup-block">
                <h3 class="setup-title">🗺 Mes lanes principales</h3>
                <p class="muted" style="margin-bottom:14px;">Lane principale (obligatoire si tu veux apparaître dans la recherche)</p>
                <div class="lanes-picker" id="laneMainPicker">
                    <?php foreach($LANES as $ln): ?>
                    <label class="lane-pick-label <?=$me['lane_main']===$ln?'lane-pick-active':''?>">
                        <input type="radio" name="lane_main" value="<?=$ln?>" <?=$me['lane_main']===$ln?'checked':''?> style="display:none">
                        <span class="lane-pick-icon"><?=$LANE_ICONS[$ln]?></span>
                        <span class="lane-pick-name"><?=$ln?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted" style="margin:16px 0 10px;">Lane secondaire <small>(optionnel)</small></p>
                <div class="lanes-picker" id="laneSecondPicker">
                    <label class="lane-pick-label <?=empty($me['lane_second'])?'lane-pick-active':''?>">
                        <input type="radio" name="lane_second" value="" <?=empty($me['lane_second'])?'checked':''?> style="display:none">
                        <span class="lane-pick-icon">✕</span>
                        <span class="lane-pick-name">Aucune</span>
                    </label>
                    <?php foreach($LANES as $ln): ?>
                    <label class="lane-pick-label <?=$me['lane_second']===$ln?'lane-pick-active':''?>">
                        <input type="radio" name="lane_second" value="<?=$ln?>" <?=$me['lane_second']===$ln?'checked':''?> style="display:none">
                        <span class="lane-pick-icon"><?=$LANE_ICONS[$ln]?></span>
                        <span class="lane-pick-name"><?=$ln?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- CHAMPIONS MAÎTRISÉS -->
            <div class="setup-block">
                <h3 class="setup-title">⚔ Champions maîtrisés <span class="muted">(max 4)</span></h3>
                <p class="muted" style="margin-bottom:16px;">Ces champions apparaîtront sur ton profil et dans les résultats de recherche.</p>
                <div class="mastered-slots" id="masteredSlots">
                    <?php for($slot=0; $slot<4; $slot++): $mc=$my_mastered[$slot]??null; ?>
                    <div class="mastered-slot <?=$mc?'slot-filled':''?>" data-slot="<?=$slot?>">
                        <?php if($mc): ?>
                            <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($mc['image_url'])?>_0.jpg" alt="<?=h($mc['name'])?>">
                            <span class="slot-name"><?=h($mc['name'])?></span>
                            <button type="button" class="slot-remove" onclick="removeSlot(<?=$slot?>)">✕</button>
                            <input type="hidden" name="mastered_champions[]" value="<?=$mc['champion_id']?>">
                        <?php else: ?>
                            <span class="slot-empty-icon">+</span>
                            <span class="slot-empty-label">Slot <?=$slot+1?></span>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="champ-picker-wrap">
                    <input type="text" id="champPickerSearch" placeholder="🔍 Ajouter un champion…" class="filter-search" style="width:100%;max-width:340px;margin-top:12px;" autocomplete="off">
                    <div class="champ-picker-dropdown" id="champPickerDropdown">
                        <?php foreach($all_champions as $ac): $already=in_array($ac['id'],$mastered_ids); ?>
                        <div class="champ-picker-item <?=$already?'picker-selected':''?>"
                             data-id="<?=$ac['id']?>" data-name="<?=strtolower(h($ac['name']))?>"
                             data-img="<?=h($ac['image_url'])?>" data-label="<?=h($ac['name'])?>"
                             onclick="pickChampion(this)">
                            <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($ac['image_url'])?>_0.jpg" alt="">
                            <?=h($ac['name'])?>
                            <?php if($already) echo '<span class="picker-check">✓</span>'; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-auth" style="max-width:220px;margin-top:8px;">💾 Sauvegarder</button>
        </form>
    </div>

    <!-- ONGLET AMIS -->
    <?php elseif($profile_tab==='friends'): ?>
    <div class="profile-body">
        <?php if(!empty($pending_received)): ?>
        <div class="friends-section"><h3 class="friends-subtitle">Demandes reçues</h3><div class="friends-list">
            <?php foreach($pending_received as $pr): ?>
            <div class="friend-item friend-pending">
                <a href="index.php?page=user&id=<?=$pr['id']?>"><?=avatarHtml($pr,'md')?></a>
                <div class="friend-info"><a href="index.php?page=user&id=<?=$pr['id']?>" class="friend-name"><?=h($pr['username'])?></a><span class="friend-date"><?=date('d/m/Y',strtotime($pr['created_at']))?></span></div>
                <div class="friend-actions">
                    <a href="index.php?friend_accept=<?=$pr['friendship_id']?>" class="btn-friend btn-accept">✓ Accepter</a>
                    <a href="index.php?friend_remove=<?=$pr['friendship_id']?>" class="btn-friend btn-decline">✕ Refuser</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <?php if(!empty($pending_sent)): ?>
        <div class="friends-section"><h3 class="friends-subtitle">Demandes envoyées</h3><div class="friends-list">
            <?php foreach($pending_sent as $ps): ?>
            <div class="friend-item">
                <?=avatarHtml($ps,'md')?>
                <div class="friend-info"><a href="index.php?page=user&id=<?=$ps['id']?>" class="friend-name"><?=h($ps['username'])?></a><span class="muted">En attente…</span></div>
                <a href="index.php?friend_remove=<?=$ps['friendship_id']?>" class="btn-friend btn-decline">Annuler</a>
            </div>
            <?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <div class="friends-section"><h3 class="friends-subtitle">Mes amis (<?=count($friends)?>)</h3>
            <?php if(empty($friends)): ?><p class="muted">Pas encore d'amis. <a href="index.php?page=find_players">Trouver des joueurs</a> !</p>
            <?php else: ?><div class="friends-list">
                <?php foreach($friends as $fr): ?>
                <div class="friend-item">
                    <a href="index.php?page=user&id=<?=$fr['id']?>"><?=avatarHtml($fr,'md')?></a>
                    <div class="friend-info"><a href="index.php?page=user&id=<?=$fr['id']?>" class="friend-name"><?=h($fr['username'])?></a><span class="friend-date">Amis depuis <?=date('d/m/Y',strtotime($fr['friend_since']))?></span></div>
                    <a href="index.php?friend_remove=<?=$fr['friendship_id']?>" class="btn-friend btn-decline" onclick="return confirm('Retirer cet ami ?')">Retirer</a>
                </div>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </div>
    </div>
    <?php endif; // tabs ?>
</section>

<?php elseif ($page==='find_players'): ?>
<!-- ====== TROUVER DES JOUEURS ====== -->
<section class="find-section">
    <h1 class="page-title">🔎 Trouver des joueurs</h1>
    <p class="welcome-sub" style="margin-bottom:28px;">Recherche par pseudo ou par champion / lane.</p>

    <div class="search-mode-tabs">
        <a href="index.php?page=find_players&search_mode=pseudo"
           class="search-mode-tab <?=$search_mode==='pseudo'?'smt-active':''?>">👤 Par pseudo</a>
        <a href="index.php?page=find_players&search_mode=champion"
           class="search-mode-tab <?=$search_mode==='champion'?'smt-active':''?>">⚔ Par champion / lane</a>
    </div>

    <?php if($search_mode==='pseudo'): ?>
    <form method="GET" action="index.php" class="find-form">
        <input type="hidden" name="page" value="find_players">
        <input type="hidden" name="search_mode" value="pseudo">
        <input type="text" name="q" value="<?=h($search_query)?>" placeholder="Pseudo d'un invocateur…" class="filter-search find-input" autofocus>
        <button type="submit" class="btn-find">Rechercher</button>
    </form>
    <?php else: ?>
    <form method="GET" action="index.php" class="find-form">
        <input type="hidden" name="page" value="find_players">
        <input type="hidden" name="search_mode" value="champion">
        <div class="find-filters">
            <select name="champ_id" class="filter-select find-select">
                <option value="">— Tous les champions —</option>
                <?php foreach($all_champions_list as $cl): ?><option value="<?=$cl['id']?>" <?=$search_champ===$cl['id']?'selected':''?>><?=h($cl['name'])?></option><?php endforeach; ?>
            </select>
            <select name="lane" class="filter-select find-select">
                <option value="">— Toutes les lanes —</option>
                <?php foreach($LANES as $ln): ?><option value="<?=$ln?>" <?=$search_lane===$ln?'selected':''?>><?=$LANE_ICONS[$ln]?> <?=$ln?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn-find">Rechercher</button>
        </div>
    </form>
    <?php endif; ?>

    <?php if($search_query!==''||$search_champ>0||$search_lane!==''): ?>
    <div class="find-results">
        <p class="results-count"><?=count($search_results)?> joueur<?=count($search_results)>1?'s':''?> trouvé<?=count($search_results)>1?'s':''?></p>
        <?php if(empty($search_results)): ?>
            <div class="empty-find"><span style="font-size:2.5rem">🔮</span><p>Aucun joueur trouvé avec ces critères.</p></div>
        <?php else: ?>
        <div class="player-cards">
            <?php foreach($search_results as $pr): ?>
            <div class="player-card">
                <div class="player-card-top">
                    <a href="index.php?page=user&id=<?=$pr['id']?>"><?=avatarHtml($pr,'md')?></a>
                    <div class="player-card-info">
                        <a href="index.php?page=user&id=<?=$pr['id']?>" class="player-card-name"><?=h($pr['username'])?></a>
                        <div class="player-card-lanes">
                            <?php if($pr['lane_main']): ?><span class="lane-badge"><?=$LANE_ICONS[$pr['lane_main']]??''?> <?=h($pr['lane_main'])?></span><?php endif; ?>
                            <?php if($pr['lane_second']): ?><span class="lane-badge secondary"><?=$LANE_ICONS[$pr['lane_second']]??''?> <?=h($pr['lane_second'])?></span><?php endif; ?>
                            <?php if(!$pr['lane_main']): ?><span class="muted" style="font-size:.78rem">Lanes non définies</span><?php endif; ?>
                        </div>
                    </div>
                    <?php if(isLogged()&&$_SESSION['user']['id']!==$pr['id']):
                        $fs=friendshipStatus($pdo,$_SESSION['user']['id'],$pr['id']); ?>
                    <div class="player-card-action">
                        <?php if($fs==='friends'): ?><span class="status-badge status-friends">✓ Amis</span>
                        <?php elseif($fs==='pending_sent'): ?><span class="status-badge status-pending">⏳</span>
                        <?php elseif($fs==='pending_received'): ?><span class="status-badge status-pending">📩</span>
                        <?php else: ?><a href="index.php?friend_add=<?=$pr['id']?>&from=find_players" class="btn-friend btn-add-friend">+ Ajouter</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if(!empty($pr['mastered'])): ?>
                <div class="player-card-mastered">
                    <?php foreach($pr['mastered'] as $pm): ?>
                    <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($pm['image_url'])?>_0.jpg"
                         alt="<?=h($pm['name'])?>" title="<?=h($pm['name'])?>" class="mastered-thumb">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<?php elseif ($page==='user' && $viewed_user):
    $vId=(int)$viewed_user['id'];
    $vStatus=isLogged()?friendshipStatus($pdo,$_SESSION['user']['id'],$vId):'none';
    $stmt=$pdo->prepare("SELECT c.*,ch.name AS champion_name,ch.id AS champion_id,ch.image_url FROM comments c JOIN champions ch ON c.champion_id=ch.id WHERE c.user_id=? ORDER BY c.created_at DESC LIMIT 6");
    $stmt->execute([$vId]); $v_comments=$stmt->fetchAll();
?>
<!-- ====== PROFIL PUBLIC ====== -->
<section class="profile-section">
    <div class="profile-header">
        <div class="profile-avatar-wrap"><?=avatarHtml($viewed_user,'lg')?></div>
        <div class="profile-info">
            <h1><?=h($viewed_user['username'])?></h1>
            <span class="profile-role <?=$viewed_user['role']==='admin'?'role-admin':'role-user'?>"><?=$viewed_user['role']==='admin'?'⚙ Admin':'✦ Invocateur'?></span>
            <?php if($viewed_user['lane_main']): ?>
            <div class="profile-lanes-display" style="margin-top:8px;">
                <span class="lane-badge"><?=$LANE_ICONS[$viewed_user['lane_main']]??''?> <?=h($viewed_user['lane_main'])?></span>
                <?php if($viewed_user['lane_second']): ?><span class="lane-badge secondary"><?=$LANE_ICONS[$viewed_user['lane_second']]??''?> <?=h($viewed_user['lane_second'])?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            <p class="muted" style="margin-top:6px;">Membre depuis le <?=date('d/m/Y',strtotime($viewed_user['created_at']))?></p>
            <?php if(isLogged()&&$_SESSION['user']['id']!==$vId): ?>
            <div style="margin-top:12px;">
                <?php if($vStatus==='friends'): ?><span class="status-badge status-friends">✓ Vous êtes amis</span>
                <?php elseif($vStatus==='pending_sent'): ?><span class="status-badge status-pending">⏳ Demande envoyée</span>
                <?php elseif($vStatus==='pending_received'): ?><span class="status-badge status-pending">📩 Va dans ton profil pour accepter</span>
                <?php else: ?><a href="index.php?friend_add=<?=$vId?>&from=user&id=<?=$vId?>" class="btn-friend btn-add-friend">+ Ajouter en ami</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!empty($v_mastered)): ?>
    <div class="mastered-section">
        <h2 class="section-heading">Champions maîtrisés</h2>
        <div class="mastered-grid">
            <?php foreach($v_mastered as $mc): ?>
            <a href="index.php?page=champion&id=<?=$mc['id']?>" class="mastered-card">
                <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($mc['image_url'])?>_0.jpg" alt="<?=h($mc['name'])?>">
                <span><?=h($mc['name'])?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <h2 class="section-heading">Derniers commentaires</h2>
    <?php if(empty($v_comments)): ?><p class="muted">Aucun commentaire.</p>
    <?php else: ?><div class="profile-comments">
        <?php foreach($v_comments as $mc): ?>
        <div class="profile-comment-item">
            <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/<?=h($mc['image_url'])?>_0.jpg" alt="" class="profile-champ-thumb">
            <div class="profile-comment-info">
                <a href="index.php?page=champion&id=<?=$mc['champion_id']?>" class="profile-champ-name"><?=h($mc['champion_name'])?></a>
                <?php if($mc['rating']): ?><div class="comment-stars-small"><?php for($s=1;$s<=5;$s++) echo '<span class="star '.($s<=$mc['rating']?'star-on':'').'">★</span>'; ?></div><?php endif; ?>
                <p class="profile-comment-text"><?=h(substr($mc['content'],0,120))?><?=strlen($mc['content'])>120?'…':''?></p>
                <span class="comment-date"><?=date('d/m/Y',strtotime($mc['created_at']))?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div><?php endif; ?>
</section>

<?php elseif ($page === 'admin'):
    if (!isAdmin()) redirect('index.php?page=accueil');
    
    $admin_tab = $_GET['tab'] ?? 'dashboard';
    
    // Données dashboard
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total_users = (int)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM comments");
    $total_comments = (int)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM champions");
    $total_champions = (int)$stmt->fetchColumn();
    
    // Utilisateurs récents
    $stmt = $pdo->query("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 10");
    $recent_users = $stmt->fetchAll();
    
    // Commentaires récents
    $stmt = $pdo->query("SELECT c.id, c.content, c.rating, c.created_at, u.username, ch.name AS champion_name 
                         FROM comments c 
                         JOIN users u ON c.user_id = u.id 
                         JOIN champions ch ON c.champion_id = ch.id 
                         ORDER BY c.created_at DESC LIMIT 15");
    $recent_comments = $stmt->fetchAll();
    
    // Tous les utilisateurs (pour tab gestion)
    $all_users = [];
    $search_user = $_GET['search_user'] ?? '';
    if ($admin_tab === 'users') {
        $sql = "SELECT id, username, email, role, created_at, avatar_url, lane_main, lane_second FROM users WHERE 1=1";
        if ($search_user !== '') {
            $sql .= " AND (username LIKE ? OR email LIKE ?)";
            $stmt = $pdo->prepare($sql . " ORDER BY created_at DESC");
            $stmt->execute(['%'.$search_user.'%', '%'.$search_user.'%']);
        } else {
            $stmt = $pdo->prepare($sql . " ORDER BY created_at DESC");
            $stmt->execute();
        }
        $all_users = $stmt->fetchAll();
    }
    
    // Tous les commentaires (pour tab gestion)
    $all_comments_admin = [];
    $search_comment = $_GET['search_comment'] ?? '';
    if ($admin_tab === 'comments') {
        $sql = "SELECT c.id, c.content, c.rating, c.created_at, u.id AS user_id, u.username, ch.id AS champion_id, ch.name AS champion_name 
                FROM comments c 
                JOIN users u ON c.user_id = u.id 
                JOIN champions ch ON c.champion_id = ch.id 
                WHERE 1=1";
        if ($search_comment !== '') {
            $sql .= " AND (u.username LIKE ? OR c.content LIKE ? OR ch.name LIKE ?)";
            $stmt = $pdo->prepare($sql . " ORDER BY c.created_at DESC");
            $stmt->execute(['%'.$search_comment.'%', '%'.$search_comment.'%', '%'.$search_comment.'%']);
        } else {
            $stmt = $pdo->prepare($sql . " ORDER BY c.created_at DESC");
            $stmt->execute();
        }
        $all_comments_admin = $stmt->fetchAll();
    }
?>

<!-- ====== ADMIN PANEL ====== -->
<section class="admin-section">
    <div class="admin-header">
        <h1 class="admin-title">⚙ Panneau Admin</h1>
        <a href="index.php?page=accueil" class="btn-back">← Retour</a>
    </div>

    <!-- Tabs -->
    <div class="admin-tabs">
        <a href="index.php?page=admin&tab=dashboard" class="admin-tab <?= $admin_tab === 'dashboard' ? 'admin-tab-active' : '' ?>">📊 Dashboard</a>
        <a href="index.php?page=admin&tab=users" class="admin-tab <?= $admin_tab === 'users' ? 'admin-tab-active' : '' ?>">👥 Utilisateurs</a>
        <a href="index.php?page=admin&tab=comments" class="admin-tab <?= $admin_tab === 'comments' ? 'admin-tab-active' : '' ?>">💬 Commentaires</a>
        <a href="index.php?page=admin&tab=champions" class="admin-tab <?= $admin_tab === 'champions' ? 'admin-tab-active' : '' ?>">⚔ Champions</a>
    </div>

    <!-- ---- TAB: DASHBOARD ---- -->
    <?php if ($admin_tab === 'dashboard'): ?>
    <div class="admin-body">
        <div class="admin-stats">
            <div class="stat-card">
                <span class="stat-icon">👥</span>
                <div class="stat-info">
                    <span class="stat-label">Utilisateurs</span>
                    <span class="stat-value"><?= $total_users ?></span>
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">💬</span>
                <div class="stat-info">
                    <span class="stat-label">Commentaires</span>
                    <span class="stat-value"><?= $total_comments ?></span>
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">⚔</span>
                <div class="stat-info">
                    <span class="stat-label">Champions</span>
                    <span class="stat-value"><?= $total_champions ?></span>
                </div>
            </div>
        </div>

        <div class="admin-grid">
            <!-- Utilisateurs récents -->
            <div class="admin-widget">
                <h3 class="widget-title">Utilisateurs récents</h3>
                <div class="widget-list">
                    <?php if (empty($recent_users)): ?>
                        <p class="muted">Aucun utilisateur.</p>
                    <?php else: ?>
                        <?php foreach ($recent_users as $u): ?>
                        <div class="widget-item">
                            <div class="item-info">
                                <span class="item-name"><?= h($u['username']) ?></span>
                                <span class="item-sub"><?= h($u['email']) ?></span>
                                <span class="item-date"><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></span>
                            </div>
                            <span class="item-badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                                <?= $u['role'] === 'admin' ? '⚙' : '✦' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Commentaires récents -->
            <div class="admin-widget">
                <h3 class="widget-title">Commentaires récents</h3>
                <div class="widget-list">
                    <?php if (empty($recent_comments)): ?>
                        <p class="muted">Aucun commentaire.</p>
                    <?php else: ?>
                        <?php foreach ($recent_comments as $cm): ?>
                        <div class="widget-item">
                            <div class="item-info">
                                <span class="item-name"><?= h($cm['username']) ?> → <?= h($cm['champion_name']) ?></span>
                                <span class="item-sub"><?= h(substr($cm['content'], 0, 60)) ?><?= strlen($cm['content']) > 60 ? '…' : '' ?></span>
                                <span class="item-date"><?= date('d/m/Y H:i', strtotime($cm['created_at'])) ?></span>
                            </div>
                            <?php if ($cm['rating']): ?>
                                <span class="item-badge">⭐ <?= $cm['rating'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ---- TAB: UTILISATEURS ---- -->
    <?php elseif ($admin_tab === 'users'): ?>
    <div class="admin-body">
        <div class="admin-search-bar">
            <form method="GET" action="index.php" class="search-form">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="tab" value="users">
                <input type="text" name="search_user" value="<?= h($search_user) ?>" placeholder="🔍 Rechercher un utilisateur…" class="filter-search">
                <button type="submit" class="btn-search">Rechercher</button>
                <?php if ($search_user !== ''): ?>
                    <a href="index.php?page=admin&tab=users" class="btn-reset">✕ Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Pseudo</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Lanes</th>
                        <th>Inscrit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_users)): ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:16px;">Aucun utilisateur trouvé.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_users as $u): ?>
                        <tr>
                            <td><strong><?= h($u['username']) ?></strong></td>
                            <td><?= h($u['email']) ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                                    <?= $u['role'] === 'admin' ? '⚙ Admin' : '✦ User' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['lane_main']): ?>
                                    <span class="lane-badge"><?= h($u['lane_main']) ?></span>
                                    <?php if ($u['lane_second']): ?><span class="lane-badge secondary"><?= h($u['lane_second']) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <a href="index.php?page=user&id=<?= $u['id'] ?>" class="btn-admin-small">Voir profil</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ---- TAB: COMMENTAIRES ---- -->
    <?php elseif ($admin_tab === 'comments'): ?>
    <div class="admin-body">
        <div class="admin-search-bar">
            <form method="GET" action="index.php" class="search-form">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="tab" value="comments">
                <input type="text" name="search_comment" value="<?= h($search_comment) ?>" placeholder="🔍 Chercher par pseudo, champion ou contenu…" class="filter-search">
                <button type="submit" class="btn-search">Rechercher</button>
                <?php if ($search_comment !== ''): ?>
                    <a href="index.php?page=admin&tab=comments" class="btn-reset">✕ Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="admin-comments-list">
            <?php if (empty($all_comments_admin)): ?>
            <p class="muted" style="text-align:center;padding:24px;">Aucun commentaire trouvé.</p>
            <?php else: ?>
                <?php foreach ($all_comments_admin as $cm): ?>
                <div class="admin-comment">
                    <div class="admin-comment-header">
                        <span class="admin-comment-user">
                            <strong><?= h($cm['username']) ?></strong>
                            sur
                            <strong><?= h($cm['champion_name']) ?></strong>
                        </span>
                        <span class="admin-comment-date"><?= date('d/m/Y H:i', strtotime($cm['created_at'])) ?></span>
                    </div>
                    <?php if ($cm['rating']): ?>
                    <div class="admin-comment-rating">
                        <?php for ($s=1; $s<=5; $s++) echo '<span class="star '.($s<=$cm['rating']?'star-on':'').'">★</span>'; ?>
                    </div>
                    <?php endif; ?>
                    <p class="admin-comment-content"><?= nl2br(h($cm['content'])) ?></p>
                    <div class="admin-comment-actions">
                        <a href="index.php?page=champion&id=<?= $cm['champion_id'] ?>#comments" class="btn-admin-small">Voir sur champion</a>
                        <a href="index.php?delete_comment=<?= $cm['id'] ?>&champion_id=<?= $cm['champion_id'] ?>" class="btn-admin-small btn-danger" onclick="return confirm('Supprimer ce commentaire ?')">Supprimer</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---- TAB: CHAMPIONS ---- -->
    <?php elseif ($admin_tab === 'champions'): ?>
    <div class="admin-body">
        <div class="admin-header-small">
            <a href="index.php?page=champions&action=add" class="btn-auth" style="max-width:200px;">+ Ajouter un champion</a>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Titre</th>
                        <th>Rôle principal</th>
                        <th>Lane</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT * FROM champions ORDER BY name ASC");
                    $champs = $stmt->fetchAll();
                    ?>
                    <?php if (empty($champs)): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;padding:16px;">Aucun champion.</td></tr>
                    <?php else: ?>
                        <?php foreach ($champs as $c): ?>
                        <tr>
                            <td><strong><?= h($c['name']) ?></strong></td>
                            <td><?= h($c['title'] ?? '') ?></td>
                            <td><?= h($c['role_primary'] ?? '') ?></td>
                            <td><?= h($c['lane'] ?? '') ?></td>
                            <td>
                                <a href="index.php?page=champion&id=<?= $c['id'] ?>" class="btn-admin-small">Voir</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; // admin tabs ?>
</section>

<?php else: ?>
<div class="error-block">Page introuvable.</div>
<?php endif; ?>

</div><!-- /main-content -->

<script>
// ---- Filtre champions ----
function filterChampions() {
    const role   = document.getElementById('roleFilter')?.value ?? 'all';
    const search = (document.getElementById('searchInput')?.value ?? '').toLowerCase();
    document.querySelectorAll('#championGrid .card').forEach(c => {
        c.style.display = ((role==='all'||c.dataset.role===role) && c.dataset.name.includes(search)) ? '' : 'none';
    });
}

// ---- Lane picker ----
document.querySelectorAll('.lane-pick-label').forEach(label => {
    label.addEventListener('click', () => {
        const name = label.querySelector('input').name;
        document.querySelectorAll(`input[name="${name}"]`).forEach(r => r.closest('.lane-pick-label').classList.remove('lane-pick-active'));
        label.classList.add('lane-pick-active');
    });
});

// ---- Champion picker (slots) ----
const champSearch = document.getElementById('champPickerSearch');
const dropdown    = document.getElementById('champPickerDropdown');

if (champSearch && dropdown) {
    champSearch.addEventListener('focus', () => dropdown.classList.add('open'));
    champSearch.addEventListener('input', () => {
        const q = champSearch.value.toLowerCase();
        dropdown.querySelectorAll('.champ-picker-item').forEach(el => el.style.display = el.dataset.name.includes(q) ? '' : 'none');
        dropdown.classList.add('open');
    });
    document.addEventListener('click', e => {
        if (!champSearch.closest('.champ-picker-wrap').contains(e.target)) dropdown.classList.remove('open');
    });
}

function pickChampion(el) {
    if (el.classList.contains('picker-selected')) return;
    const slots = [...document.querySelectorAll('#masteredSlots .mastered-slot')];
    const empty = slots.find(s => !s.classList.contains('slot-filled'));
    const filled = slots.filter(s => s.classList.contains('slot-filled')).length;
    if (filled >= 4 || !empty) { alert('Maximum 4 champions !'); return; }

    const {id, img, label} = el.dataset;
    empty.classList.add('slot-filled');
    empty.innerHTML = `
        <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/tiles/${img}_0.jpg" alt="${label}">
        <span class="slot-name">${label}</span>
        <button type="button" class="slot-remove" onclick="removeSlot(${empty.dataset.slot})">✕</button>
        <input type="hidden" name="mastered_champions[]" value="${id}">
    `;
    el.classList.add('picker-selected');
    el.insertAdjacentHTML('beforeend','<span class="picker-check">✓</span>');
    dropdown.classList.remove('open');
    champSearch.value = '';
    dropdown.querySelectorAll('.champ-picker-item').forEach(i => i.style.display = '');
}

function removeSlot(idx) {
    const slot = document.querySelector(`[data-slot="${idx}"]`);
    if (!slot) return;
    const champId = slot.querySelector('input[type="hidden"]')?.value;
    slot.classList.remove('slot-filled');
    slot.innerHTML = `<span class="slot-empty-icon">+</span><span class="slot-empty-label">Slot ${parseInt(idx)+1}</span>`;
    if (champId && dropdown) {
        const item = dropdown.querySelector(`[data-id="${champId}"]`);
        if (item) { item.classList.remove('picker-selected'); item.querySelector('.picker-check')?.remove(); }
    }
}
</script>
</body>
</html>