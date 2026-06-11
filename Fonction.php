<?php
function h(string $s): string { 
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); 
}

function isLogged(): bool { 
    return isset($_SESSION['user']); 
}

function isAdmin(): bool { 
    return ($_SESSION['user']['role'] ?? '') === 'admin'; 
}

function avatarHtml(array $user, string $size = 'sm'): string {
    $px   = match($size) { 'lg' => '100px', 'md' => '52px', default => '34px' };
    $font = match($size) { 'lg' => '2rem',  'md' => '1.2rem', default => '.85rem' };
    $initial = strtoupper(substr($user['username'] ?? '?', 0, 1));
    
    if (!empty($user['avatar_url'])) {
        return '<img src="uploads/avatars/'.h($user['avatar_url']).'" alt="'.h($user['username']).'" style="width:'.$px.';height:'.$px.';border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0;">';
    }
    
    return '<span class="avatar-initial" style="width:'.$px.';height:'.$px.';font-size:'.$font.';">'.$initial.'</span>';
}