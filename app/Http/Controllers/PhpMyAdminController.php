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

            // Generar página HTML que envía el formulario automáticamente
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
    
    <form id="phpmyadmin-form" method="post" action="{$phpMyAdminUrl}" style="display: none;">
        <input type="hidden" name="pma_servername" value="{$credentials['server']}">
        <input type="hidden" name="pma_username" value="{$credentials['username']}">
        <input type="hidden" name="pma_password" value="{$credentials['password']}">
        <input type="hidden" name="server" value="{$credentials['server']}">
    </form>
    
    <script>
        // Intentar enviar el formulario automáticamente
        window.onload = function() {
            setTimeout(function() {
                const form = document.getElementById('phpmyadmin-form');
                if (form) {
                    form.submit();
                }
            }, 500);
        };
        
        // Si después de 3 segundos no se ha redirigido, mostrar mensaje
        setTimeout(function() {
            const container = document.querySelector('.container');
            if (container) {
                container.innerHTML = '<h1>Redirigiendo...</h1><p>Si no se redirige automáticamente, <a href="{$phpMyAdminUrl}" style="color: white; text-decoration: underline;">haz clic aquí</a>.</p>';
            }
        }, 3000);
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
