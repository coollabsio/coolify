#!/bin/bash
# 1. Create Migration
cat << 'MIGRATION' > database/migrations/2026_02_08_000001_add_is_oauth_registration_enabled_to_instance_settings_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_oauth_registration_enabled')->default(true)->after('is_registration_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('is_oauth_registration_enabled');
        });
    }
};
MIGRATION

# 2. Update Model
sed -i "/'is_wire_navigate_enabled' => 'boolean',/a \        'is_oauth_registration_enabled' => 'boolean'," app/Models/InstanceSettings.php

# 3. Update Controller
sed -i "s/if (! \$settings->is_registration_enabled) {/if (! \$settings->is_registration_enabled \&\& ! \$settings->is_oauth_registration_enabled) {/" app/Http/Controllers/OauthController.php

# 4. Update Livewire Component
sed -i "/public bool \$is_registration_enabled;/a \    #[Validate('boolean')]\n    public bool \$is_oauth_registration_enabled;" app/Livewire/Settings/Advanced.php
sed -i "/'is_registration_enabled' => 'boolean',/a \            'is_oauth_registration_enabled' => 'boolean'," app/Livewire/Settings/Advanced.php
sed -i "/\$this->is_registration_enabled = \$this->settings->is_registration_enabled;/a \        \$this->is_oauth_registration_enabled = \$this->settings->is_oauth_registration_enabled;" app/Livewire/Settings/Advanced.php
sed -i "/\$this->settings->is_registration_enabled = \$this->is_registration_enabled;/a \            \$this->settings->is_oauth_registration_enabled = \$this->is_oauth_registration_enabled;" app/Livewire/Settings/Advanced.php

# 5. Update Blade View
sed -i "/label=\"Registration Allowed\" \/>/a \                    </div>\n                    <div class=\"md:w-96\">\n                        <x-forms.checkbox instantSave id=\"is_oauth_registration_enabled\"\n                            helper=\"Allow users to self-register via OAuth (if OAuth is configured). If disabled, only administrators can create accounts or existing users can log in via OAuth.\"\n                            label=\"OAuth Registration Allowed\" \/>" resources/views/livewire/settings/advanced.blade.php

# 6. Git & PR
git add .
git commit -m "feat: add OAuth only registration option"
git push origin feat/oauth-only-registration --force
gh pr create --title "Feat: OAuth Only Registration Option" --body "This PR adds a new setting to allow user registration exclusively via OAuth, even when general self-registration is disabled.

/claim #8042"
