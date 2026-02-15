<?php



use Illuminate\Database\Migrations\Migration;

use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\Schema;



return new class extends Migration
  
{
  
    /**
    
     * Run the migrations.
     
     */
  
    public function up(): void
  
  {
    
        Schema::table('users', function (Blueprint $table) {
          
            $table->boolean('is_oauth_only')->default(false);
          
        });
    
  }
  

  
    /**
    
     * Reverse the migrations.
     
     */
  
    public function down(): void
  
  {
    
        Schema::table('users', function (Blueprint $table) {
          
            $table->dropColumn('is_oauth_only');
          
        });
    
  }
  
};<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    /**\n     * Run the migrations.\n     */\n    public function up(): void\n    {\n        Schema::table('users', function (Blueprint $table) {\n            $table->boolean('is_oauth_only')->default(false);\n        });\n    }\n\n    /**\n     * Reverse the migrations.\n     */\n    public function down(): void\n    {\n        Schema::table('users', function (Blueprint $table) {\n            $table->dropColumn('is_oauth_only');\n        });\n    }\n};
