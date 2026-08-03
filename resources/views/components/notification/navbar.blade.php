@props([
    'title' => 'Notifications',
    'subtitle' => 'Delivery channels for team events',
    // Topbar + channel tabs already identify the page on desktop.
    'titleOnDesktop' => false,
])

<x-dashboard.navbar section="notifications" :title="$title" :subtitle="$subtitle" :titleOnDesktop="$titleOnDesktop" />
