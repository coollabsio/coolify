<?php

use App\Actions\CoolifyTask\PrepareCoolifyTask;
use App\Data\CoolifyTaskArgs;
use App\Models\GithubApp;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Contracts\Activity;

if (!function_exists('generalErrorHandler')) {
    function generalErrorHandler(\Throwable $e, $that = null)
    {
        if ($that) {
            if ($e instanceof QueryException) {
                if ($e->errorInfo[0] === '23505') {
                    $that->emit('error', 'Duplicate entry found.');
                } else if (count($e->errorInfo) === 4) {
                    $that->emit('error', $e->errorInfo[3]);
                } else {
                    $that->emit('error', $e->errorInfo[2]);
                }
            } else {
                $that->emit('error', $e->getMessage());
            }
        } else {
            dump($e);
        }
    }
}
if (!function_exists('remoteProcess')) {
    /**
     * Run a Remote Process, which SSH's asynchronously into a machine to run the command(s).
     * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
     *
     */
    function remoteProcess(
        array   $command,
        Server  $server,
        string $type,
        ?string $type_uuid = null,
        ?Model  $model = null,
    ): Activity {

        $command_string = implode("\n", $command);

        // @TODO: Check if the user has access to this server
        // checkTeam($server->team_id);

        $private_key_location = savePrivateKeyForServer($server);

        return resolve(PrepareCoolifyTask::class, [
            'remoteProcessArgs' => new CoolifyTaskArgs(
                server_ip: $server->ip,
                private_key_location: $private_key_location,
                command: <<<EOT
                {$command_string}
                EOT,
                port: $server->port,
                user: $server->user,
                type: $type,
                type_uuid: $type_uuid,
                model: $model,
            ),
        ])();
    }
}

// function checkTeam(string $team_id)
// {
//     $found_team = auth()->user()->teams->pluck('id')->contains($team_id);
//     if (!$found_team) {
//         throw new \RuntimeException('You do not have access to this server.');
//     }
// }

if (!function_exists('savePrivateKeyForServer')) {
    function savePrivateKeyForServer(Server $server)
    {
        $temp_file = "id.root@{$server->ip}";
        Storage::disk('ssh-keys')->put($temp_file, $server->privateKey->private_key, 'private');
        return '/var/www/html/storage/app/ssh-keys/' . $temp_file;
    }
}

if (!function_exists('generateSshCommand')) {
    function generateSshCommand(string $private_key_location, string $server_ip, string $user, string $port, string $command, bool $isMux = true)
    {
        $delimiter = 'EOF-COOLIFY-SSH';
        Storage::disk('local')->makeDirectory('.ssh');
        $ssh_command = "ssh ";
        if ($isMux) {
            $ssh_command .= '-o ControlMaster=auto -o ControlPersist=1m -o ControlPath=/var/www/html/storage/app/.ssh/ssh_mux_%h_%p_%r ';
        }
        $ssh_command .= "-i {$private_key_location} "
            . '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PasswordAuthentication=no '
            . '-o ConnectTimeout=3600 '
            . '-o ServerAliveInterval=60 '
            . '-o RequestTTY=no '
            . '-o LogLevel=ERROR '
            . "-p {$port} "
            . "{$user}@{$server_ip} "
            . " 'bash -se' << \\$delimiter" . PHP_EOL
            . $command . PHP_EOL
            . $delimiter;

        return $ssh_command;
    }
}
if (!function_exists('formatDockerCmdOutputToJson')) {
    function formatDockerCmdOutputToJson($rawOutput): Collection
    {
        $outputLines = explode(PHP_EOL, $rawOutput);

        return collect($outputLines)
            ->reject(fn ($line) => empty($line))
            ->map(fn ($outputLine) => json_decode($outputLine, true, flags: JSON_THROW_ON_ERROR));
    }
}
if (!function_exists('formatDockerLabelsToJson')) {
    function formatDockerLabelsToJson($rawOutput): Collection
    {
        $outputLines = explode(PHP_EOL, $rawOutput);

        return collect($outputLines)
            ->reject(fn ($line) => empty($line))
            ->map(function ($outputLine) {
                $outputArray = explode(',', $outputLine);
                return collect($outputArray)
                    ->map(function ($outputLine) {
                        return explode('=', $outputLine);
                    })
                    ->mapWithKeys(function ($outputLine) {
                        return [$outputLine[0] => $outputLine[1]];
                    });
            })[0];
    }
}
if (!function_exists('instantRemoteProcess')) {
    function instantRemoteProcess(array $command, Server $server, $throwError = true)
    {
        $command_string = implode("\n", $command);
        $private_key_location = savePrivateKeyForServer($server);
        $ssh_command = generateSshCommand($private_key_location, $server->ip, $server->user, $server->port, $command_string);
        $process = Process::run($ssh_command);
        $output = trim($process->output());
        $exitCode = $process->exitCode();
        if ($exitCode !== 0) {
            if (!$throwError) {
                return false;
            }
            Log::error($process->errorOutput());
            throw new \RuntimeException('There was an error running the command.');
        }
        return $output;
    }
}

if (!function_exists('getLatestVersionOfCoolify')) {
    function getLatestVersionOfCoolify()
    {
        $response = Http::get('https://get.coollabs.io/versions.json');
        $versions = $response->json();
        return data_get($versions, 'coolify.v4.version');
    }
}
if (!function_exists('generateRandomName')) {
    function generateRandomName()
    {
        $generator = new \Nubs\RandomNameGenerator\All(
            [
                new \Nubs\RandomNameGenerator\Alliteration()
            ]
        );
        return $generator->getName();
    }
}

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

if (!function_exists('generate_github_token')) {
    function generate_github_token(GithubApp $source)
    {
        $signingKey = InMemory::plainText($source->privateKey->private_key);
        $algorithm = new Sha256();
        $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
        $now = new DateTimeImmutable();
        $now = $now->setTime($now->format('H'), $now->format('i'));
        $issuedToken = $tokenBuilder
            ->issuedBy($source->app_id)
            ->issuedAt($now)
            ->expiresAt($now->modify('+10 minutes'))
            ->getToken($algorithm, $signingKey)
            ->toString();
        $token = Http::withHeaders([
            'Authorization' => "Bearer $issuedToken",
            'Accept' => 'application/vnd.github.machine-man-preview+json'
        ])->post("{$source->api_url}/app/installations/{$source->installation_id}/access_tokens");
        if ($token->failed()) {
            throw new \Exception("Failed to get access token for " . $source->name . " with error: " . $token->json()['message']);
        }
        return $token->json()['token'];
    }
}
if (!function_exists('getParameters')) {
    function getParameters()
    {
        return Route::current()->parameters();
    }
}
