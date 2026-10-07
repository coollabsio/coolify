The enabled backup schedule for {{ $database_name }} has produced no executions in the last {{ $days }} day(s).

@if ($last_execution_at)
The last execution was at {{ $last_execution_at }}.
@else
This schedule has never produced an execution.
@endif
