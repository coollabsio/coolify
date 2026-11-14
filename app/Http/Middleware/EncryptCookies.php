<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    /**
     * The remembered list views (RemembersCanvasView) hold only an allow-listed view name.
     * Encryption makes each of these per-user, per-page cookies about 330 bytes, and the
     * browser sends all of them on the Reverb WebSocket handshake too.
     */
    public function isDisabled($name)
    {
        return parent::isDisabled($name) || preg_match('/^coolify_\d+_[a-z_]+_view$/', $name) === 1;
    }
}
