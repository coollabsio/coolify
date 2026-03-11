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
            
            // Escapar valores para HTML y JavaScript
            $server = htmlspecialchars($credentials['server'] ?? '1', ENT_QUOTES, 'UTF-8');
            $username = htmlspecialchars($credentials['username'] ?? 'root', ENT_QUOTES, 'UTF-8');
            $password = htmlspecialchars($credentials['password'] ?? '', ENT_QUOTES, 'UTF-8');
            
            // Escapar para JavaScript (JSON encode)
            $serverJs = json_encode($server);
            $usernameJs = json_encode($username);
            $passwordJs = json_encode($password);
            $loginUrlJs = json_encode($loginUrl);

            // Generar página HTML que redirige a phpMyAdmin y rellena el formulario con JavaScript
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
    
    <script>
        // Redirigir a phpMyAdmin y luego rellenar el formulario
        const loginUrl = {$loginUrlJs};
        const server = {$serverJs};
        const username = {$usernameJs};
        const password = {$passwordJs};
        
        console.log('[phpMyAdmin Autologin] Redirecting to:', loginUrl);
        console.log('[phpMyAdmin Autologin] Credentials:', {server: server, username: username});
        
        // Redirigir a phpMyAdmin
        window.location.href = loginUrl;
        
        // Intentar rellenar el formulario cuando la página carga
        window.addEventListener('load', function() {
            setTimeout(function() {
                try {
                    // Buscar el formulario de login
                    const loginForm = document.querySelector('form[name="login_form"]') || 
                                    document.querySelector('form#login_form') ||
                                    document.querySelector('form.login-form') ||
                                    document.querySelector('form');
                    
                    if (loginForm) {
                        console.log('[phpMyAdmin Autologin] Form found, filling...');
                        
                        // Buscar campos
                        const serverInput = loginForm.querySelector('input[name="pma_servername"]') ||
                                         loginForm.querySelector('input[name="server"]') ||
                                         loginForm.querySelector('select[name="pma_servername"]') ||
                                         loginForm.querySelector('select[name="server"]');
                        
                        const usernameInput = loginForm.querySelector('input[name="pma_username"]') ||
                                            loginForm.querySelector('input[name="username"]');
                        
                        const passwordInput = loginForm.querySelector('input[name="pma_password"]') ||
                                            loginForm.querySelector('input[name="password"]');
                        
                        if (serverInput) {
                            if (serverInput.tagName === 'SELECT') {
                                const option = Array.from(serverInput.options).find(opt => 
                                    opt.value === server || opt.text.includes(server)
                                );
                                if (option) serverInput.value = option.value;
                                else if (serverInput.options.length > 0) serverInput.value = serverInput.options[0].value;
                            } else {
                                serverInput.value = server;
                            }
                        }
                        
                        if (usernameInput) usernameInput.value = username;
                        if (passwordInput) passwordInput.value = password;
                        
                        // Intentar enviar el formulario
                        if (serverInput && usernameInput && passwordInput) {
                            setTimeout(function() {
                                const submitButton = loginForm.querySelector('input[type="submit"]') ||
                                                   loginForm.querySelector('button[type="submit"]') ||
                                                   loginForm.querySelector('button');
                                if (submitButton) {
                                    console.log('[phpMyAdmin Autologin] Submitting form...');
                                    loginForm.submit();
                                }
                            }, 500);
                        }
                    }
                } catch (e) {
                    console.error('[phpMyAdmin Autologin] Error:', e);
                }
            }, 1000);
        });
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
