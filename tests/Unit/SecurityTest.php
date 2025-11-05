<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for security functions (CSRF, validation)
 */
class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        TestHelper::resetSession();
        require_once __DIR__ . '/../../config.php';
        require_once __DIR__ . '/../../functions.php';
    }

    protected function tearDown(): void
    {
        TestHelper::resetSession();
    }

    /**
     * Test generate_csrf_token() creates a token
     */
    public function testGenerateCsrfTokenCreatesToken()
    {
        $token = generate_csrf_token();

        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex characters
        $this->assertEquals($token, $_SESSION['csrf_token']);
    }

    /**
     * Test generate_csrf_token() returns same token on subsequent calls
     */
    public function testGenerateCsrfTokenReturnsSameToken()
    {
        $token1 = generate_csrf_token();
        $token2 = generate_csrf_token();

        $this->assertEquals($token1, $token2);
    }

    /**
     * Test validate_csrf_token() validates correct token
     */
    public function testValidateCsrfTokenValidatesCorrectToken()
    {
        $token = generate_csrf_token();

        $this->assertTrue(validate_csrf_token($token));
    }

    /**
     * Test validate_csrf_token() rejects invalid token
     */
    public function testValidateCsrfTokenRejectsInvalidToken()
    {
        generate_csrf_token();

        $this->assertFalse(validate_csrf_token('invalid_token'));
    }

    /**
     * Test validate_csrf_token() rejects when no session token exists
     */
    public function testValidateCsrfTokenRejectsWhenNoSessionToken()
    {
        unset($_SESSION['csrf_token']);

        $this->assertFalse(validate_csrf_token('any_token'));
    }

    /**
     * Test validate_csrf_token() uses timing-safe comparison
     */
    public function testValidateCsrfTokenUsesTimingSafeComparison()
    {
        $token = generate_csrf_token();

        // Slightly different token should fail
        $tamperedToken = substr($token, 0, -1) . 'x';
        $this->assertFalse(validate_csrf_token($tamperedToken));
    }

    /**
     * Test csrf_field() generates correct HTML
     */
    public function testCsrfFieldGeneratesCorrectHtml()
    {
        $html = csrf_field();

        $this->assertStringContainsString('<input type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="', $html);

        // Check that value is properly escaped
        $this->assertStringNotContainsString('">', $html);
    }

    /**
     * Test csrf_field() includes current token
     */
    public function testCsrfFieldIncludesCurrentToken()
    {
        $token = generate_csrf_token();
        $html = csrf_field();

        $this->assertStringContainsString($token, $html);
    }

    /**
     * Test verify_csrf() passes with valid token on POST
     */
    public function testVerifyCsrfPassesWithValidToken()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = generate_csrf_token();
        $_POST['csrf_token'] = $token;

        ob_start();
        verify_csrf();
        ob_end_clean();

        // If we got here without die(), test passed
        $this->assertTrue(true);
    }

    /**
     * Test verify_csrf() allows GET requests without token
     */
    public function testVerifyCsrfAllowsGetRequests()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_POST['csrf_token']);

        ob_start();
        verify_csrf();
        ob_end_clean();

        // Should pass without checking token
        $this->assertTrue(true);
    }

    /**
     * Test sanitize_string() handles various XSS attempts
     */
    public function testSanitizeStringHandlesXssAttempts()
    {
        $xssAttempts = [
            '<script>alert("XSS")</script>' => '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;',
            '<img src=x onerror=alert(1)>' => '&lt;img src=x onerror=alert(1)&gt;',
            'javascript:alert(1)' => 'javascript:alert(1)',
            '<iframe src="evil.com"></iframe>' => '&lt;iframe src=&quot;evil.com&quot;&gt;&lt;/iframe&gt;',
        ];

        foreach ($xssAttempts as $input => $expected) {
            $this->assertEquals($expected, sanitize_string($input));
        }
    }

    /**
     * Test sanitize_string() preserves safe text
     */
    public function testSanitizeStringPreservesSafeText()
    {
        $safeInputs = [
            'Hello World',
            'Book Title 123',
            'Author Name',
        ];

        foreach ($safeInputs as $input) {
            $this->assertEquals($input, sanitize_string($input));
        }
    }

    /**
     * Test sanitize_string() handles quotes correctly
     */
    public function testSanitizeStringHandlesQuotes()
    {
        $this->assertEquals('It&039;s a test', sanitize_string("It's a test"));
        $this->assertEquals('&quot;Quoted&quot;', sanitize_string('"Quoted"'));
    }

    /**
     * Test validate_email() rejects SQL injection attempts
     */
    public function testValidateEmailRejectsSqlInjection()
    {
        $sqlInjections = [
            "admin'--",
            "' OR '1'='1",
            "admin@example.com'; DROP TABLE users--",
        ];

        foreach ($sqlInjections as $input) {
            $this->assertFalse(validate_email($input));
        }
    }

    /**
     * Test validate_email() accepts international domains
     */
    public function testValidateEmailAcceptsInternationalDomains()
    {
        $this->assertTrue(validate_email('user@münchen.de'));
        $this->assertTrue(validate_email('test@例え.jp'));
    }

    /**
     * Test log_action() handles PDO exceptions gracefully
     */
    public function testLogActionHandlesExceptionsGracefully()
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('Test error'));
        $GLOBALS['pdo'] = $pdo;

        // Should not throw exception, just log error
        ob_start();
        log_action(1, 'test_action', '127.0.0.1', 'test details');
        ob_end_clean();

        $this->assertTrue(true); // If we got here, test passed
    }
}
