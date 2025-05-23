<?php

namespace Database\Factories;

use App\Models\ServerSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerSettingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ServerSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'is_swarm_manager' => false,
            'is_jump_server' => false,
            'is_build_server' => false,
            'is_reachable' => true,
            'is_usable' => true,
            'cpu_model' => null,
            'cpu_cores' => null,
            'cpu_speed' => null,
            'memory_total' => null,
            'memory_speed' => null,
            'swap_total' => null,
            'disk_total' => null,
            'disk_used' => null,
            'disk_free' => null,
            'gpu_model' => null,
            'gpu_memory' => null,
            'os_name' => null,
            'os_version' => null,
            'kernel_version' => null,
            'architecture' => null,
        ];
    }
}
