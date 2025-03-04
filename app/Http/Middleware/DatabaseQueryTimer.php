<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DatabaseQueryTimer
{
    protected $queries = [];

    public function handle(Request $request, Closure $next): Response
    {
        // Start listening to database queries
        DB::listen(function ($query) {
            $this->queries[] = [
                'sql' => $query->sql,
                'time' => $query->time,  // Time in milliseconds
                'bindings' => $query->bindings,
            ];
        });

        $response = $next($request);

        // Add Server-Timing headers for each query
        $totalTime = 0;
        foreach ($this->queries as $index => $query) {
            // $response->headers->set(
            //     'Server-Timing',
            //     "db-query-{$index};desc=\"{$this->sanitizeQuery($query['sql'])}\";dur={$query['time']}",
            //     false
            // );
            $totalTime += $query['time'];
        }

        // Add total database time
        if (count($this->queries) > 0) {
            $response->headers->set(
                'Server-Timing',
                "db-total;desc=\"Total DB Time\";dur={$totalTime}",
                false
            );
        }

        return $response;
    }

    protected function sanitizeQuery(string $sql): string
    {
        // Remove newlines and extra spaces
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = str_replace('"', '\'', $sql);

        // Truncate long queries
        return strlen($sql) > 100 ? substr($sql, 0, 97).'...' : $sql;
    }
}
