<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class ServiceTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'category',
        'tags',
        'icon',
        'version',
        'author',
        'compose_file',
        'template_path',
        'environment_variables',
        'ports',
        'volumes',
        'documentation'
    ];

    protected $casts = [
        'tags' => 'array',
        'environment_variables' => 'array',
        'ports' => 'array',
        'volumes' => 'array'
    ];

    public static function getAvailableTemplates()
    {
        $templatesPath = base_path('templates');
        $templates = [];

        if (!File::exists($templatesPath)) {
            return collect($templates);
        }

        $directories = File::directories($templatesPath);

        foreach ($directories as $directory) {
            $templateJsonPath = $directory . '/template.json';
            $dockerComposePath = $directory . '/docker-compose.yml';

            if (File::exists($templateJsonPath) && File::exists($dockerComposePath)) {
                $templateData = json_decode(File::get($templateJsonPath), true);
                $templateData['template_path'] = basename($directory);
                $templateData['compose_content'] = File::get($dockerComposePath);
                $templates[] = $templateData;
            }
        }

        return collect($templates);
    }

    public static function getTemplate($templatePath)
    {
        $fullPath = base_path('templates/' . $templatePath);
        $templateJsonPath = $fullPath . '/template.json';
        $dockerComposePath = $fullPath . '/docker-compose.yml';

        if (!File::exists($templateJsonPath) || !File::exists($dockerComposePath)) {
            return null;
        }

        $templateData = json_decode(File::get($templateJsonPath), true);
        $templateData['compose_content'] = File::get($dockerComposePath);
        $templateData['template_path'] = $templatePath;

        return $templateData;
    }

    public function getComposeContent()
    {
        if ($this->template_path) {
            $composePath = base_path('templates/' . $this->template_path . '/docker-compose.yml');
            if (File::exists($composePath)) {
                return File::get($composePath);
            }
        }

        return $this->compose_file;
    }
}