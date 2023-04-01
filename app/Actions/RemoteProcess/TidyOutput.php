<?php

namespace App\Actions\RemoteProcess;

use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class TidyOutput
{
    protected $output;

    public function __construct(
        protected Activity $activity
    )
    {
    }

    public function __invoke()
    {
        $chunks = preg_split(
            RunRemoteProcess::MARK_REGEX,
            $this->activity->description,
            flags: PREG_SPLIT_DELIM_CAPTURE
        );

        $tidyRows = $this
            ->joinMarksWithFollowingItem($chunks)
            ->reject(fn($i) => $i === '')
            ->map(function ($i) {
                if (!preg_match('/\|--(\d+)\|(\d+)\|(out|err)--\|(.*)/', $i, $matches)) {
                    return $i;
                }
                [$wholeLine, $sequence, $elapsedTime, $type, $output] = $matches;
                return [
                    'sequence' => $sequence,
                    'time' => $elapsedTime,
                    'type' => $type,
                    'output' => $output,
                ];
            });

            return $tidyRows
                ->sortBy(fn($i) => $i['sequence'])
                ->map(fn($i) => $i['output'])
                ->implode("\n");
    }

    /**
     * Function to join the defined mark, with the output
     * that is the following element in the array.
     *
     * Turns this:
     *      [
     *          "|--1|149|out--|",
     *          "/root\n",
     *          "|--2|251|out--|",
     *          "Welcome 1 times 1\n",
     *          "|--3|366|out--|",
     *          "Welcome 2 times 2\n",
     *          "|--4|466|out--|",
     *          "Welcome 3 times 3\n",
     *      ]
     *
     *  into this:
     *
     *      [
     *          "|--1|149|out--|/root\n",
     *          "|--2|251|out--|Welcome 1 times 1\n",
     *          "|--3|366|out--|Welcome 2 times 2\n",
     *          "|--4|466|out--|Welcome 3 times 3\n",
     *      ]
     */
    public function joinMarksWithFollowingItem($chunks): Collection
    {
        return collect($chunks)->reduce(function ($carry, $item) {
            $last = $carry->last();
            if (preg_match(RunRemoteProcess::MARK_REGEX, $last) && !preg_match(RunRemoteProcess::MARK_REGEX, $item)) {
                // If the last element is a delimiter and the current element is not,
                // join them together and replace the last element with the joined string
                $carry->pop();
                $joined = $last . $item;
                $carry->push($joined);
            } else {
                // Otherwise, just add the current element to the result array
                $carry->push($item);
            }
            return $carry;
        }, collect());
    }
}
