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

            // Generar página HTML que muestra las credenciales y abre phpMyAdmin
            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credenciales phpMyAdmin - Coolify</title>
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
            max-width: 600px;
            width: 100%;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        h1 {
            margin: 0 0 1.5rem 0;
            font-size: 1.75rem;
            text-align: center;
        }
        .credentials-box {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .credential-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
        }
        .credential-item:last-child {
            margin-bottom: 0;
        }
        .credential-label {
            font-weight: 600;
            min-width: 100px;
            font-size: 0.9rem;
        }
        .credential-value {
            flex: 1;
            font-family: 'Courier New', monospace;
            background: rgba(0, 0, 0, 0.2);
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            word-break: break-all;
            font-size: 0.9rem;
        }
        .copy-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .copy-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .copy-btn.copied {
            background: #10b981;
            border-color: #10b981;
        }
        .actions {
            display: flex;
            gap: 1rem;
            flex-direction: column;
        }
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: 8px;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: white;
            color: #667eea;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .info {
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            font-size: 0.9rem;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Credenciales phpMyAdmin</h1>
        
        <div class="credentials-box">
            <div class="credential-item">
                <span class="credential-label">Servidor:</span>
                <span class="credential-value" id="server-value">{$server}</span>
                <button class="copy-btn" onclick="copyToClipboard('server-value', this)">Copiar</button>
            </div>
            <div class="credential-item">
                <span class="credential-label">Usuario:</span>
                <span class="credential-value" id="username-value">{$username}</span>
                <button class="copy-btn" onclick="copyToClipboard('username-value', this)">Copiar</button>
            </div>
            <div class="credential-item">
                <span class="credential-label">Contraseña:</span>
                <span class="credential-value" id="password-value">{$password}</span>
                <button class="copy-btn" onclick="copyToClipboard('password-value', this)">Copiar</button>
            </div>
        </div>
        
        <div class="actions">
            <a href="{$loginUrl}" target="_blank" class="btn btn-primary">Abrir phpMyAdmin</a>
            <button class="btn btn-secondary" onclick="copyAllCredentials()">Copiar todas las credenciales</button>
        </div>
        
        <div class="info">
            <strong>💡 Consejo:</strong> Haz clic en "Abrir phpMyAdmin" y luego pega las credenciales en el formulario de login. 
            También puedes copiar cada campo individualmente usando los botones "Copiar".<br><br>
            <strong>⚠️ Nota importante:</strong> Si el servidor muestra "Name does not resolve", asegúrate de que phpMyAdmin y la base de datos estén en el mismo servicio de Docker Compose. 
            Si están en servicios diferentes, puede que necesites usar la IP del contenedor o configurar la conexión manualmente.
        </div>
    </div>
    
    <script>
        const loginUrl = {$loginUrlJs};
        const server = {$serverJs};
        const username = {$usernameJs};
        const password = {$passwordJs};
        
        function copyToClipboard(elementId, button) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            navigator.clipboard.writeText(text).then(function() {
                const originalText = button.textContent;
                button.textContent = '✓ Copiado';
                button.classList.add('copied');
                
                setTimeout(function() {
                    button.textContent = originalText;
                    button.classList.remove('copied');
                }, 2000);
            }).catch(function(err) {
                console.error('Error al copiar:', err);
                // Fallback para navegadores antiguos
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                
                button.textContent = '✓ Copiado';
                button.classList.add('copied');
                setTimeout(function() {
                    button.textContent = 'Copiar';
                    button.classList.remove('copied');
                }, 2000);
            });
        }
        
        function copyAllCredentials() {
            const credentials = `Servidor: {$server}\nUsuario: {$username}\nContraseña: {$password}`;
            
            navigator.clipboard.writeText(credentials).then(function() {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✓ Todas copiadas';
                btn.classList.add('copied');
                
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(function(err) {
                console.error('Error al copiar:', err);
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = credentials;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                
                const btn = event.target;
                btn.textContent = '✓ Todas copiadas';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = 'Copiar todas las credenciales';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }
        
        // Intentar rellenar automáticamente si phpMyAdmin se abre en la misma ventana
        // (Esto solo funcionará si el usuario hace clic en "Abrir phpMyAdmin" y luego vuelve aquí)
        window.addEventListener('focus', function() {
            // Si la página recupera el foco, puede ser que phpMyAdmin esté abierto
            // No hacemos nada automático aquí para evitar problemas
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
