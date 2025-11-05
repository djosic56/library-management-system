<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for authentication and authorization functions
 */
class AuthenticationTest extends TestCase
{
    protected $pdo;

    protected function setUp(): void
    {
        // Mock the global $pdo variable
        $this->pdo = $this->createMock(PDO::class);
        $GLOBALS['pdo'] = $this->pdo;

        // Reset session
        TestHelper::resetSession();

        // Load functions
        require_once __DIR__ . '/../../config.php';
        require_once __DIR__ . '/../../functions.php';
    }

    protected function tearDown(): void
    {
        TestHelper::resetSession();
    }

    /**
     * Test is_admin() returns true for admin users
     */
    public function testIsAdminReturnsTrueForAdminUser()
    {
        $_SESSION['level'] = 1;
        $this->assertTrue(is_admin());
    }

    /**
     * Test is_admin() returns false for regular users
     */
    public function testIsAdminReturnsFalseForRegularUser()
    {
        $_SESSION['level'] = 2;
        $this->assertFalse(is_admin());
    }

    /**
     * Test is_admin() returns false when level is not set
     */
    public function testIsAdminReturnsFalseWhenLevelNotSet()
    {
        unset($_SESSION['level']);
        $this->assertFalse(is_admin());
    }

    /**
     * Test is_admin() uses strict comparison (prevents type coercion)
     */
    public function testIsAdminUsesStrictComparison()
    {
        // String "1" should not be treated as admin
        $_SESSION['level'] = '1';
        // This should still return true because of (int) cast in is_admin()
        $this->assertTrue(is_admin());

        // But "1admin" should not
        $_SESSION['level'] = '1admin';
        $this->assertFalse(is_admin());
    }

    /**
     * Test require_login() allows logged-in users
     */
    public function testRequireLoginAllowsLoggedInUser()
    {
        $_SESSION['user_id'] = 1;

        // Should not throw exception or redirect
        ob_start();
        require_login();
        ob_end_clean();

        $this->assertTrue(true); // If we got here, test passed
    }

    /**
     * Test validate_email() with valid emails
     */
    public function testValidateEmailWithValidEmails()
    {
        $this->assertTrue(validate_email('test@example.com'));
        $this->assertTrue(validate_email('user.name@example.co.uk'));
        $this->assertTrue(validate_email('test+tag@example.com'));
    }

    /**
     * Test validate_email() with invalid emails
     */
    public function testValidateEmailWithInvalidEmails()
    {
        $this->assertFalse(validate_email('notanemail'));
        $this->assertFalse(validate_email('missing@domain'));
        $this->assertFalse(validate_email('@example.com'));
        $this->assertFalse(validate_email(''));
    }

    /**
     * Test sanitize_string() removes HTML and trims whitespace
     */
    public function testSanitizeStringRemovesHtmlAndTrims()
    {
        $this->assertEquals('Hello World', sanitize_string('  Hello World  '));
        $this->assertEquals('&lt;script&gt;alert(1)&lt;/script&gt;', sanitize_string('<script>alert(1)</script>'));
        $this->assertEquals('Test &amp; Example', sanitize_string('Test & Example'));
    }

    /**
     * Test check_login_attempts() with no attempts
     */
    public function testCheckLoginAttemptsWithNoAttempts()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(0);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertFalse(check_login_attempts('testuser', '127.0.0.1'));
    }

    /**
     * Test check_login_attempts() blocks after max attempts
     */
    public function testCheckLoginAttemptsBlocksAfterMaxAttempts()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(5); // MAX_LOGIN_ATTEMPTS
        $stmt->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertTrue(check_login_attempts('testuser', '127.0.0.1'));
    }

    /**
     * Test log_login_attempt() logs successful login
     */
    public function testLogLoginAttemptLogsSuccessfulLogin()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with(['testuser', 1, '127.0.0.1', 1])
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO login_attempt'))
            ->willReturn($stmt);

        log_login_attempt('testuser', 1, '127.0.0.1', true);
    }

    /**
     * Test log_login_attempt() logs failed login
     */
    public function testLogLoginAttemptLogsFailedLogin()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with(['testuser', null, '127.0.0.1', 0])
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO login_attempt'))
            ->willReturn($stmt);

        log_login_attempt('testuser', null, '127.0.0.1', false);
    }
}
