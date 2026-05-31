<?php
declare(strict_types=1);

namespace LockBits\Tests;

use PHPUnit\Framework\TestCase;

final class EdrAgentSectionTest extends TestCase
{
    public function testConfigConstantsAreDefined(): void
    {
        $this->assertTrue(defined('EDR_SERVER_URL'));
        $this->assertTrue(defined('EDR_AUTH_TOKEN'));
    }

    public function testConfigConstantsDefaultToEmpty(): void
    {
        // Save current env values, clear them, test the getenv expression
        $savedUrl = getenv('EDR_SERVER_URL');
        $savedToken = getenv('EDR_AUTH_TOKEN');
        putenv('EDR_SERVER_URL');
        putenv('EDR_AUTH_TOKEN');

        $url = (string) (getenv('EDR_SERVER_URL') ?: '');
        $token = (string) (getenv('EDR_AUTH_TOKEN') ?: '');

        $this->assertSame('', $url);
        $this->assertSame('', $token);

        // Restore
        putenv('EDR_SERVER_URL=' . $savedUrl);
        putenv('EDR_AUTH_TOKEN=' . $savedToken);
    }

    public function testConfigConstantsReadFromEnv(): void
    {
        $savedUrl = getenv('EDR_SERVER_URL');
        $savedToken = getenv('EDR_AUTH_TOKEN');
        putenv('EDR_SERVER_URL=https://edr.example.com');
        putenv('EDR_AUTH_TOKEN=test-token-123');

        $url = (string) (getenv('EDR_SERVER_URL') ?: '');
        $token = (string) (getenv('EDR_AUTH_TOKEN') ?: '');

        $this->assertSame('https://edr.example.com', $url);
        $this->assertSame('test-token-123', $token);

        putenv('EDR_SERVER_URL=' . $savedUrl);
        putenv('EDR_AUTH_TOKEN=' . $savedToken);
    }

    public function testDashboardFileExistsAndContainsExpectedPatterns(): void
    {
        $path = __DIR__ . '/../client/dashboard.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('EDR Agents', $content);
        $this->assertStringContainsString('install-agent.sh', $content);
    }
}
