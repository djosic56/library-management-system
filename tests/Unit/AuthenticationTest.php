<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for authentication and authorization functions
 */
class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
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
        // A whole-number string such as "1" (as a database driver may return it) is admin
        $_SESSION['level'] = '1';
        $this->assertTrue(is_admin());

        // But a string that only starts with 1 must not be coerced to 1
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
}
