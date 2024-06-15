<?php /** @noinspection ALL */

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Class RemoteProcessManager
 * This class should be used to execute remote processes.
 * @package App\Services\Remote
 */
class RemoteProcessManager
{
    private Server $server;
    private InstantRemoteProcessFactory $instantRemoteProcessFactory;
    private RemoteProcessExecutionerManager $executioner;


    public function __construct(Server                          $server, InstantRemoteProcessFactory $remoteProcessFactory,
                                RemoteProcessExecutionerManager $executionerManager)
    {
        $this->server = $server;
        $this->instantRemoteProcessFactory = $remoteProcessFactory;
        $this->executioner = $executionerManager;

    }


    public function execute(Collection|array|string $commands): string
    {
        $commands = $this->getCommandCollection($commands);

        $generatedCommand = $this->instantRemoteProcessFactory->generateCommand($this->server, $commands);

        $executedResult = $this->executioner->execute($generatedCommand);

        return $executedResult;
    }

    private function getCommandCollection(Collection|array|string $commands): Collection
    {
        if ($commands instanceof Collection) {
            return $commands;
        }

        if (is_array($commands)) {
            return collect($commands);
        }

        return collect([$commands]);
    }
}
