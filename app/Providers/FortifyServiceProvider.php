<?php



namespace App\\Providers;



use App\\Models\\User;

use Illuminate\\Cache\\RateLimiting\\Limit;

use Illuminate\\Http\\Request;

use Illuminate\\Support\\Facades\\Hash;

use Illuminate\\Support\\Facades\\RateLimiter;

use Laravel\\Fortify\\Fortify;

use Illuminate\\Support\\ServiceProvider;



class FortifyServiceProvider extends ServiceProvider
    
{
    
    public function register(): void
    
    {
        
    }
    

    
    public function boot(): void
    
    {
        
        Fortify::authenticateUsing(function (Request $request) {
            
            $user = User::whereEmail($request->email)->first();
            
            if ($user && $user->is_oauth_only) {
                
                return null;
                
            }
            
            if ($user && Hash::check($request->password, $user->password)) {
                
                return $user;
                
            }
            
        });
        

        
        RateLimiter::for('login', function (Request $request) {
            
            $email = (string) $request->email;
            
            return Limit::perMinute(10)->by($email . $request->ip());
            
        });
                         

                         
        RateLimiter::for('two-factor', function (Request $request) {
            
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
            
        });
                         
                         }
                         
}


























