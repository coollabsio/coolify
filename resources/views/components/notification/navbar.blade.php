@props([
    'title' => 'Notifications',
    'subtitle' => 'Delivery channels for team events',
    // Topbar + channel tabs identify the page at xl+; keep the H1 on tablet.
    'titleOnDesktop' => false,
])

<x-dashboard.navbar section="notifications" :title="$title" :subtitle="$subtitle" :titleOnDesktop="$titleOnDesktop" />
