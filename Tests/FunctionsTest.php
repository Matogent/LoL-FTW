<?php
use PHPUnit\Framework\TestCase;

// On charge l'autoloader de Composer et nos fonctions isolées
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../functions.php';

class FunctionsTest extends TestCase
{
    /**
     * S'assure que la session est vide avant chaque test
     */
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    // --- TESTS DE LA FONCTION h() ---

    public function testHFunctionEscapesHtml()
    {
        $input = "<script>alert('test');</script>";
        $expected = "&lt;script&gt;alert(&#039;test&#039;);&lt;/script&gt;";
        $this->assertEquals($expected, h($input));
    }

    // --- TESTS DES FONCTIONS DE SESSION ---

    public function testIsLoggedReturnsTrueWhenUserSet()
    {
        $_SESSION['user'] = ['id' => 1, 'username' => 'Joueur'];
        $this->assertTrue(isLogged());
    }

    public function testIsLoggedReturnsFalseWhenSessionEmpty()
    {
        $this->assertFalse(isLogged());
    }

    public function testIsAdminReturnsTrueForAdminRole()
    {
        $_SESSION['user'] = ['role' => 'admin'];
        $this->assertTrue(isAdmin());
    }

    public function testIsAdminReturnsFalseForNormalUser()
    {
        $_SESSION['user'] = ['role' => 'user'];
        $this->assertFalse(isAdmin());
    }

    // --- TESTS DE LA FONCTION avatarHtml() ---

    public function testAvatarHtmlGeneratesImgTagWhenUrlExists()
    {
        $user = ['username' => 'Teemo', 'avatar_url' => 'teemo.png'];
        $html = avatarHtml($user, 'sm');
        
        $this->assertStringContainsString('<img src="uploads/avatars/teemo.png"', $html);
        $this->assertStringContainsString('width:34px', $html);
    }

    public function testAvatarHtmlGeneratesInitialsWhenNoUrl()
    {
        $user = ['username' => 'Garen', 'avatar_url' => null];
        $html = avatarHtml($user, 'lg');
        
        $this->assertStringContainsString('<span class="avatar-initial"', $html);
        $this->assertStringContainsString('>G</span>', $html); // Vérifie l'initiale
        $this->assertStringContainsString('width:100px', $html);
    }
}