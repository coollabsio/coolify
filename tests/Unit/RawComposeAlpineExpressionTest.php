<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

class RawComposeAlpineExpressionTest extends TestCase
{
    public function test_it_renders_the_raw_compose_toggle_condition_as_a_javascript_boolean(): void
    {
        $expression = 'x-show="!@js($application->settings->is_raw_compose_deployment_enabled)"';
        $viewPath = dirname(__DIR__, 2).'/resources/views/livewire/project/application/general.blade.php';
        $view = file_get_contents($viewPath);

        $this->assertStringContainsString($expression, $view);

        foreach ([[true, 'true'], [false, 'false']] as [$isEnabled, $expected]) {
            $application = (object) [
                'settings' => (object) ['is_raw_compose_deployment_enabled' => $isEnabled],
            ];
            $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
            $compiled = $compiler->compileString("<div {$expression}></div>");

            ob_start();
            eval('?>'.$compiled);
            $html = ob_get_clean();

            $this->assertStringContainsString("x-show=\"!{$expected}\"", $html);
            $this->assertStringNotContainsString('$application->', $html);
        }
    }
}
