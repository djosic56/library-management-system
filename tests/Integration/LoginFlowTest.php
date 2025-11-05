<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for login flow
 * Requires test database to be set up
 */
class LoginFlowTest extends TestCase
{
    protected $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = TestHelper::createTestDatabase();
        } catch (Exception $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        TestHelper::cleanupTestData($this->pdo);
        TestHelper::resetSession();

        $GLOBALS['pdo'] = $this->pdo;
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            TestHelper::cleanupTestData($this->pdo);
        }
        TestHelper::resetSession();
    }

    /**
     * Test successful login flow
     */
    public function testSuccessfulLoginFlow()
    {
        // Create test user
        $userId = TestHelper::createTestUser($this->pdo, 'testuser', 'password123', 2);

        // Simulate login
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute(['testuser']);
        $user = $stmt->fetch();

        $this->assertNotNull($user);
        $this->assertTrue(password_verify('password123', $user['password']));
        $this->assertEquals(2, $user['level']);
    }

    /**
     * Test login attempt logging
     */
    public function testLoginAttemptLogging()
    {
        require_once __DIR__ . '/../../functions.php';

        $userId = TestHelper::createTestUser($this->pdo, 'testuser', 'password123');

        // Log successful attempt
        log_login_attempt('testuser', $userId, '127.0.0.1', true);

        $stmt = $this->pdo->query("SELECT * FROM login_attempt ORDER BY attempted_at DESC LIMIT 1");
        $attempt = $stmt->fetch();

        $this->assertEquals('testuser', $attempt['username']);
        $this->assertEquals($userId, $attempt['user_id']);
        $this->assertEquals('127.0.0.1', $attempt['ip_address']);
        $this->assertEquals(1, $attempt['success']);
    }

    /**
     * Test failed login attempt logging
     */
    public function testFailedLoginAttemptLogging()
    {
        require_once __DIR__ . '/../../functions.php';

        // Log failed attempt
        log_login_attempt('testuser', null, '127.0.0.1', false);

        $stmt = $this->pdo->query("SELECT * FROM login_attempt ORDER BY attempted_at DESC LIMIT 1");
        $attempt = $stmt->fetch();

        $this->assertEquals('testuser', $attempt['username']);
        $this->assertNull($attempt['user_id']);
        $this->assertEquals('127.0.0.1', $attempt['ip_address']);
        $this->assertEquals(0, $attempt['success']);
    }

    /**
     * Test rate limiting after multiple failed attempts
     */
    public function testRateLimitingAfterMultipleFailedAttempts()
    {
        require_once __DIR__ . '/../../functions.php';

        // Log 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            log_login_attempt('testuser', null, '127.0.0.1', false);
        }

        // Check if rate limiting is triggered
        $isLocked = check_login_attempts('testuser', '127.0.0.1');

        $this->assertTrue($isLocked);
    }

    /**
     * Test rate limiting does not trigger with successful attempts
     */
    public function testRateLimitingDoesNotTriggerWithSuccessfulAttempts()
    {
        require_once __DIR__ . '/../../functions.php';

        $userId = TestHelper::createTestUser($this->pdo, 'testuser', 'password123');

        // Log 5 successful attempts
        for ($i = 0; $i < 5; $i++) {
            log_login_attempt('testuser', $userId, '127.0.0.1', true);
        }

        // Check that rate limiting is NOT triggered
        $isLocked = check_login_attempts('testuser', '127.0.0.1');

        $this->assertFalse($isLocked);
    }

    /**
     * Test rate limiting by IP address
     */
    public function testRateLimitingByIpAddress()
    {
        require_once __DIR__ . '/../../functions.php';

        // Log 5 failed attempts from same IP but different usernames
        for ($i = 0; $i < 5; $i++) {
            log_login_attempt("user$i", null, '127.0.0.1', false);
        }

        // Check if rate limiting is triggered for the IP
        $isLocked = check_login_attempts('anotheruser', '127.0.0.1');

        $this->assertTrue($isLocked);
    }

    /**
     * Test different IPs are not affected by rate limiting
     */
    public function testDifferentIpsNotAffectedByRateLimiting()
    {
        require_once __DIR__ . '/../../functions.php';

        // Log 5 failed attempts from one IP
        for ($i = 0; $i < 5; $i++) {
            log_login_attempt('testuser', null, '127.0.0.1', false);
        }

        // Check that different IP is not locked
        $isLocked = check_login_attempts('testuser', '192.168.1.1');

        $this->assertFalse($isLocked);
    }

    /**
     * Test user action logging
     */
    public function testUserActionLogging()
    {
        require_once __DIR__ . '/../../functions.php';

        $userId = TestHelper::createTestUser($this->pdo, 'testuser', 'password123');

        // Log an action
        log_action($userId, 'test_action', '127.0.0.1', 'Test details');

        $stmt = $this->pdo->query("SELECT * FROM user_log ORDER BY timestamp DESC LIMIT 1");
        $log = $stmt->fetch();

        $this->assertEquals($userId, $log['user_id']);
        $this->assertEquals('test_action', $log['action']);
        $this->assertEquals('127.0.0.1', $log['ip']);
        $this->assertEquals('Test details', $log['details']);
    }

    /**
     * Test password hashing is secure
     */
    public function testPasswordHashingIsSecure()
    {
        $password = 'testpassword123';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Verify hash is valid
        $this->assertTrue(password_verify($password, $hash));

        // Verify hash is different each time
        $hash2 = password_hash($password, PASSWORD_DEFAULT);
        $this->assertNotEquals($hash, $hash2);

        // Verify wrong password fails
        $this->assertFalse(password_verify('wrongpassword', $hash));
    }

    /**
     * Test admin user has correct level
     */
    public function testAdminUserHasCorrectLevel()
    {
        $adminId = TestHelper::createTestUser($this->pdo, 'admin', 'adminpass', 1);

        $stmt = $this->pdo->prepare("SELECT level FROM users WHERE id = ?");
        $stmt->execute([$adminId]);
        $level = $stmt->fetchColumn();

        $this->assertEquals(1, $level);
    }

    /**
     * Test regular user has correct level
     */
    public function testRegularUserHasCorrectLevel()
    {
        $userId = TestHelper::createTestUser($this->pdo, 'user', 'userpass', 2);

        $stmt = $this->pdo->prepare("SELECT level FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $level = $stmt->fetchColumn();

        $this->assertEquals(2, $level);
    }
}
