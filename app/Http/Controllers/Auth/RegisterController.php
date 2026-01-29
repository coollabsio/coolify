<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Handle a registration request for the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        // Check if OAuth registration is allowed even when general registration is disabled
        if (!config('auth.allow_oauth_registration') && !config('auth.allow_password_registration')) {
            return response()->json(['error' => 'Registration is disabled'], 403);
        }

        // If general registration is disabled, only allow OAuth registration
        if (!config('auth.allow_password_registration') && $request->input('registration_type') !== 'oauth') {
            return response()->json(['error' => 'Password registration is disabled'], 403);
        }

        // If OAuth registration is disabled, only allow password registration
        if (!config('auth.allow_oauth_registration') && $request->input('registration_type') === 'oauth') {
            return response()->json(['error' => 'OAuth registration is disabled'], 403);
        }

        // If OAuth registration is enabled, allow registration even if general registration is disabled
        if (config('auth.allow_oauth_registration') && $request->input('registration_type') === 'oauth') {
            $this->validator($request->all())->validate();
            
            $user = $this->create($request->all());
            
            Auth::login($user);
            
            return $this->registered($request, $user)
                ?: redirect($this->redirectPath());
        }

        // Default registration handling if not OAuth
        return parent::register($request);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }

    /**
     * The user was successfully registered.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return mixed
     */
    protected function registered(Request $request, $user)
    {
        // You can add any additional logic here after successful registration
        return redirect($this->redirectPath());
    }
}