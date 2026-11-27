<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The proxy options of the domains of a cluster workload (decision 0031): basic auth, an IP
 * allow list, request and response headers, compression, and the path strip. Sentinel renders
 * them on the ingress Nodes with `ingress.route_options.v1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->boolean('is_http_basic_auth_enabled')->default(false);
            $table->string('http_basic_auth_username')->nullable();
            // The encrypted password, so the page can show it again, and its bcrypt hash for the routes.
            $table->text('http_basic_auth_password')->nullable();
            $table->string('http_basic_auth_password_hash')->nullable();
            $table->json('ingress_allowed_ips')->nullable();
            $table->json('ingress_request_headers')->nullable();
            $table->json('ingress_response_headers')->nullable();
            $table->boolean('is_gzip_enabled')->default(true);
            $table->boolean('is_stripprefix_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn([
                'is_http_basic_auth_enabled',
                'http_basic_auth_username',
                'http_basic_auth_password',
                'http_basic_auth_password_hash',
                'ingress_allowed_ips',
                'ingress_request_headers',
                'ingress_response_headers',
                'is_gzip_enabled',
                'is_stripprefix_enabled',
            ]);
        });
    }
};
