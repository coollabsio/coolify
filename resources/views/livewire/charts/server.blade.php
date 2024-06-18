<div wire:poll.5000ms='loadData'>
    <h1>CPU Usage</h1>
    <x-apex-charts :chart-id="$chartId" :series-data="$data" :categories="$categories" series-name="Total distance this year"/>
</div>
