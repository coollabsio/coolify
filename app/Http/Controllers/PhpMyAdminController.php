<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class PhpMyAdminController extends Controller
{
    public function autoLogin(Request $request)
    {
        $encryptedData = $request->query('data');
        
        if (! $encryptedData) {
            abort(400, 'Missing data parameter');
        }

        try {
            $data = json_decode(Crypt::decryptString($encryptedData), true);
            
            if (! isset($data['url']) || ! isset($data['credentials'])) {
                abort(400, 'Invalid data');
            }

            $phpMyAdminUrl = $data['url'];
            $credentials = $data['credentials'];

            // Limpiar la URL para obtener solo la base (sin parámetros GET ni index.php)
            $urlParts = parse_url($phpMyAdminUrl);
            $baseUrl = $urlParts['scheme'].'://'.$urlParts['host'];
            if (isset($urlParts['port'])) {
                $baseUrl .= ':'.$urlParts['port'];
            }
            if (isset($urlParts['path'])) {
                $path = rtrim($urlParts['path'], '/');
                // Si termina en index.php, quitarlo
                if (str_ends_with($path, 'index.php')) {
                    $path = dirname($path);
                }
                $baseUrl .= $path;
            }
            // Asegurar que termine con /
            $baseUrl = rtrim($baseUrl, '/').'/';
            
            // URL del formulario de login de phpMyAdmin
            $loginUrl = $baseUrl.'index.php';
            
            // Escapar valores para HTML
            $server = htmlspecialchars($credentials['server'] ?? '1', ENT_QUOTES, 'UTF-8');
            $username = htmlspecialchars($credentials['username'] ?? 'root', ENT_QUOTES, 'UTF-8');
            $password = htmlspecialchars($credentials['password'] ?? '', ENT_QUOTES, 'UTF-8');

            // Generar página HTML que envía el formulario automáticamente mediante POST
            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conectando a phpMyAdmin...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 4px solid white;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h1 {
            margin: 0 0 1rem 0;
            font-size: 1.5rem;
        }
        p {
            margin: 0;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>Conectando a phpMyAdmin...</h1>
        <p>Por favor espera mientras se completa el inicio de sesión automático.</p>
    </div>
    
    <form id="phpmyadmin-form" method="post" action="{$loginUrl}" style="display: none;">
        <input type="hidden" name="pma_servername" value="{$server}">
        <input type="hidden" name="pma_username" value="{$username}">
        <input type="hidden" name="pma_password" value="{$password}">
        <input type="hidden" name="server" value="{$server}">
    </form>
    
    <script>
        // Enviar el formulario inmediatamente cuando la página carga
        (function() {
            const form = document.getElementById('phpmyadmin-form');
            if (form) {
                // Enviar inmediatamente sin esperar
                form.submit();
            }
        })();
        
        // Fallback: si después de 2 segundos no se ha redirigido, mostrar mensaje
        setTimeout(function() {
            const container = document.querySelector('.container');
            if (container && document.visibilityState === 'visible') {
                container.innerHTML = '<h1>Redirigiendo...</h1><p>Si no se redirige automáticamente, <a href="{$loginUrl}" style="color: white; text-decoration: underline;">haz clic aquí</a>.</p>';
            }
        }, 2000);
    </script>
</body>
</html>
HTML;

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            abort(400, 'Invalid encrypted data');
        }
    }
}
