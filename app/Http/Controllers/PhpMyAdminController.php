<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class PhpMyAdminController extends Controller
{
    public function autoLogin(Request $request)
    {
        // Intentar obtener datos de múltiples fuentes: POST, GET, o sesión
        $encryptedData = $request->input('data') ?? $request->query('data') ?? session('phpmyadmin_data');
        $plainData = session('phpmyadmin_data_plain');
        
        \Log::info('phpMyAdmin autologin request', [
            'method' => $request->method(),
            'has_encrypted_data' => !empty($encryptedData),
            'has_plain_data' => !empty($plainData),
            'encrypted_data_length' => $encryptedData ? strlen($encryptedData) : 0,
            'request_all_keys' => array_keys($request->all()),
        ]);
        
        // Si hay datos sin cifrar en sesión, usarlos directamente (más confiable)
        if ($plainData && isset($plainData['url']) && isset($plainData['credentials'])) {
            session()->forget('phpmyadmin_data_plain');
            $phpMyAdminUrl = $plainData['url'];
            $credentials = $plainData['credentials'];
        } elseif ($encryptedData) {
            // Intentar descifrar los datos cifrados
            try {
                // Si viene por GET, puede estar codificado en URL
                if ($request->isMethod('get')) {
                    $encryptedData = urldecode($encryptedData);
                }
                
                // Limpiar la sesión después de usarla
                session()->forget('phpmyadmin_data');
                
                try {
                    $decryptedData = Crypt::decryptString($encryptedData);
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    \Log::error('phpMyAdmin: Decryption failed', [
                        'error' => $e->getMessage(),
                        'data_length' => strlen($encryptedData),
                        'data_preview' => substr($encryptedData, 0, 100),
                    ]);
                    abort(400, 'Decryption failed. Please try again from the file explorer.');
                }
                
                $data = json_decode($decryptedData, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Log::error('phpMyAdmin: JSON decode error', [
                        'error' => json_last_error_msg(),
                        'decrypted_length' => strlen($decryptedData),
                        'decrypted_preview' => substr($decryptedData, 0, 200),
                    ]);
                    abort(400, 'Invalid JSON data: '.json_last_error_msg());
                }
                
                if (! isset($data['url']) || ! isset($data['credentials'])) {
                    \Log::error('phpMyAdmin: Missing required fields', [
                        'has_url' => isset($data['url']),
                        'has_credentials' => isset($data['credentials']),
                        'data_keys' => array_keys($data ?? []),
                    ]);
                    abort(400, 'Invalid data structure');
                }
                
                $phpMyAdminUrl = $data['url'];
                $credentials = $data['credentials'];
            } catch (\Exception $e) {
                \Log::error('phpMyAdmin: Exception processing encrypted data', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
                abort(400, 'Error processing data: '.$e->getMessage());
            }
        } else {
            \Log::error('phpMyAdmin: No data available', [
                'request_all' => $request->all(),
                'query_params' => $request->query(),
            ]);
            abort(400, 'Missing data parameter. Please try again from the file explorer.');
        }

        try {
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
            
            // Obtener credenciales
            $server = $credentials['server'] ?? 'localhost';
            $username = $credentials['username'] ?? 'root';
            $password = $credentials['password'] ?? '';
            
            // El servidor debe ser el UUID completo de la base de datos
            // que es el nombre del servicio en docker-compose
            // phpMyAdmin puede conectarse usando este nombre directamente
            
            // Escapar valores para HTML y JavaScript
            $serverEscaped = htmlspecialchars($server, ENT_QUOTES, 'UTF-8');
            $usernameEscaped = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $passwordEscaped = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
            $loginUrlEscaped = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
            
            // Escapar para JavaScript (JSON encode)
            $serverJs = json_encode($server, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $usernameJs = json_encode($username, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $passwordJs = json_encode($password, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $loginUrlJs = json_encode($loginUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            
            // Generar página HTML que hace login automático con POST (como Plesk)
            // phpMyAdmin puede usar diferentes nombres de campos según la versión
            // Intentamos con múltiples variantes para máxima compatibilidad
            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciando phpMyAdmin - Coolify</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
        }
        .container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
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
            margin-top: 1rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>🔐 Iniciando sesión en phpMyAdmin...</h1>
        <p>Serás redirigido automáticamente.</p>
    </div>
    
    <form id="phpmyadmin-login-form" method="POST" action="{$loginUrlEscaped}" target="_blank" style="display: none;">
        <input type="hidden" name="pma_username" value="{$usernameEscaped}">
        <input type="hidden" name="pma_password" value="{$passwordEscaped}">
        <input type="hidden" name="server" value="1">
        <input type="hidden" name="pma_servername" value="{$serverEscaped}">
        <input type="hidden" name="username" value="{$usernameEscaped}">
        <input type="hidden" name="password" value="{$passwordEscaped}">
        <input type="hidden" name="pma_hostname" value="{$serverEscaped}">
    </form>
    
    <iframe id="phpmyadmin-frame" name="phpmyadmin-frame" style="display: none;"></iframe>
    
    <script>
        // Función para enviar el formulario automáticamente
        function submitPhpMyAdminForm() {
            const form = document.getElementById('phpmyadmin-login-form');
            if (form) {
                // Enviar el formulario inmediatamente
                form.submit();
                
                // Redirigir a phpMyAdmin después de un breve delay
                setTimeout(function() {
                    window.location.href = {$loginUrlJs};
                }, 500);
            }
        }
        
        // Enviar formulario automáticamente al cargar la página
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', submitPhpMyAdminForm);
        } else {
            submitPhpMyAdminForm();
        }
    </script>
</body>
</html>
HTML;

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            \Log::error('phpMyAdmin: Decryption exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            abort(400, 'Decryption failed: '.$e->getMessage());
        } catch (\Exception $e) {
            \Log::error('phpMyAdmin: General exception', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            abort(400, 'Error processing request: '.$e->getMessage());
        }
    }
}
