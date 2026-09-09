@props([
    'title' => 'Profile',
    'subtitle' => 'Your account preferences',
    'titleOnDesktop' => false,
])

<x-dashboard.navbar section="profile" :title="$title" :subtitle="$subtitle" :titleOnDesktop="$titleOnDesktop" />
