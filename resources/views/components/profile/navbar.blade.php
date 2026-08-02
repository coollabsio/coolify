@props([
    'title' => 'Profile',
    'subtitle' => 'Your account preferences',
])

<x-dashboard.navbar section="profile" :title="$title" :subtitle="$subtitle" />
