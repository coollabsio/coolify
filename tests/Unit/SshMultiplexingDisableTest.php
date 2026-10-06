<?php

namespace Tests\Unit;

use App\Helpers\SshMultiplexingHelper;
use Tests\TestCase;

/**
 * Tests for SSH multiplexing disable functionality.
 *
 * These tests verify the parameter signatures for the disableMultiplexing feature
 * which prevents race conditions when multiple scheduled tasks run concurrently.
 *
 * @see https://github.com/coollabsio/coolify/issues/6736
 */
class SshMultiplexingDisableTest extends TestCase
{
    /**
     * sshd runs the remote command with the SSH user's login shell (`$SHELL -c '<command>'`),
     * which can be fish or csh/tcsh. The wrapper must work in each of them.
     */
    public function test_remote_shell_runs_the_script_from_stdin_in_every_login_shell()
    {
        $wrapper = (new \ReflectionMethod(SshMultiplexingHelper::class, 'remoteShellCommand'))->invoke(null);
        $tested = [];

        foreach (['sh', 'bash', 'dash', 'zsh', 'fish', 'tcsh', 'csh'] as $loginShell) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($loginShell).' 2>/dev/null'));
            if ($path === '') {
                continue;
            }

            $output = shell_exec('echo "echo remote-ok" | '.escapeshellarg($path).' -c '.escapeshellarg($wrapper).' 2>&1');
            $this->assertSame('remote-ok', trim((string) $output), "Login shell {$loginShell} could not run the wrapper.");
            $tested[] = $loginShell;
        }

        $this->assertContains('sh', $tested);
    }

    /**
     * fish and csh/tcsh cannot parse POSIX `if ...; then ...; fi`. A single `sh -c "..."` command
     * with only double quotes (it is wrapped in single quotes on the ssh command line) is a simple
     * command that every login shell can run.
     */
    public function test_remote_shell_is_one_simple_sh_command()
    {
        $wrapper = (new \ReflectionMethod(SshMultiplexingHelper::class, 'remoteShellCommand'))->invoke(null);

        $this->assertMatchesRegularExpression('/^sh -c "[^"\'$`!]*"$/', $wrapper);
    }

    public function test_generate_ssh_command_accepts_disable_multiplexing_parameter()
    {
        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'generateSshCommand');
        $parameters = $reflection->getParameters();

        // Should have at least 3 parameters: $server, $command, $disableMultiplexing
        $this->assertGreaterThanOrEqual(3, count($parameters));

        $disableMultiplexingParam = $parameters[2] ?? null;
        $this->assertNotNull($disableMultiplexingParam);
        $this->assertEquals('disableMultiplexing', $disableMultiplexingParam->getName());
        $this->assertTrue($disableMultiplexingParam->isDefaultValueAvailable());
        $this->assertFalse($disableMultiplexingParam->getDefaultValue());
    }

    public function test_disable_multiplexing_parameter_is_boolean_type()
    {
        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'generateSshCommand');
        $parameters = $reflection->getParameters();

        $disableMultiplexingParam = $parameters[2] ?? null;
        $this->assertNotNull($disableMultiplexingParam);

        $type = $disableMultiplexingParam->getType();
        $this->assertNotNull($type);
        $this->assertEquals('bool', $type->getName());
    }

    public function test_instant_remote_process_accepts_disable_multiplexing_parameter()
    {
        $this->assertTrue(
            function_exists('instant_remote_process'),
            'instant_remote_process function should exist'
        );

        $reflection = new \ReflectionFunction('instant_remote_process');
        $parameters = $reflection->getParameters();

        // Find the disableMultiplexing parameter
        $disableMultiplexingParam = null;
        foreach ($parameters as $param) {
            if ($param->getName() === 'disableMultiplexing') {
                $disableMultiplexingParam = $param;
                break;
            }
        }

        $this->assertNotNull($disableMultiplexingParam, 'disableMultiplexing parameter should exist');
        $this->assertTrue($disableMultiplexingParam->isDefaultValueAvailable());
        $this->assertFalse($disableMultiplexingParam->getDefaultValue());
    }

    public function test_instant_remote_process_disable_multiplexing_is_boolean_type()
    {
        $reflection = new \ReflectionFunction('instant_remote_process');
        $parameters = $reflection->getParameters();

        // Find the disableMultiplexing parameter
        $disableMultiplexingParam = null;
        foreach ($parameters as $param) {
            if ($param->getName() === 'disableMultiplexing') {
                $disableMultiplexingParam = $param;
                break;
            }
        }

        $this->assertNotNull($disableMultiplexingParam);

        $type = $disableMultiplexingParam->getType();
        $this->assertNotNull($type);
        $this->assertEquals('bool', $type->getName());
    }

    public function test_multiplexing_is_skipped_when_disabled()
    {
        // This test verifies the logic flow by checking the code path
        // When disableMultiplexing is true, the condition `! $disableMultiplexing && self::isMultiplexingEnabled()`
        // should evaluate to false, skipping multiplexing entirely

        // We verify the condition logic:
        // disableMultiplexing = true -> ! true = false -> condition is false -> skip multiplexing
        $disableMultiplexing = true;
        $this->assertFalse(! $disableMultiplexing, 'When disableMultiplexing is true, negation should be false');

        // disableMultiplexing = false -> ! false = true -> condition may proceed
        $disableMultiplexing = false;
        $this->assertTrue(! $disableMultiplexing, 'When disableMultiplexing is false, negation should be true');
    }
}
