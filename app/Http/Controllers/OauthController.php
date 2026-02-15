<?php



namespace App\Http\Controllers;



use App\Models\InstanceSettings;

use App\Models\Team;

use App\Models\User;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Hash;

use Laravel\Socialite\Facades\Socialite;

use Illuminate\Support\Str;



class OauthController extends Controller
    
{
    
    public function callback(Request $request)
    
    {
        
        $provider = $request->route('provider');
        
        try {
            
            $githubUser = Socialite::driver($provider)->user();
            
        } catch (\Exception $e) {
            
            return redirect('/login')->with('error', 'Authentication failed.');
            
        }
        

        
        $user = User::where('email', $githubUser->getEmail())->first();
        
        $instance_settings = InstanceSettings::get();
        

        
        if (!$user) {
            
            if (!$instance_settings->is_oauth_registration_enabled) {
                
                return redirect('/login')->with('error', 'Registration is disabled.');
                
            }
            

            
            $user = User::create([
                                 
                'name' => $githubUser->getName() ?? $githubUser->getNickname(),
                                 
                'email' => $githubUser->getEmail(),
                                 
                'password' => Hash::make(Str::random(32)),
                                 
                'is_oauth_only' => true,
                                 
            ]);
            

            
            $team = Team::create([
                                 
                'name' => $user->name . \"'s Team\",
                
                'personal_team' => true,
                
            ]);
            


            $user->teams()->attach($team, ['role' => 'admin']);
            
            $user->switchTeam($team);
            
        }
        


        Auth::login($user);
        
        return redirect()->intended('/');
        
    }
    
}



































