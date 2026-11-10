@props(['menu', 'page', 'label'])
{{-- Star button of one resource menu page. It sits next to the link (a button cannot be inside a link). --}}
<button type="button" class="starred-menu-toggle"
    x-data="{ get starred() { return $store.starredMenu.isStarred(@js($menu), @js($page)); } }"
    @click.prevent.stop="$store.starredMenu.toggle(@js($menu), @js($page))"
    :aria-pressed="starred ? 'true' : 'false'" :title="starred ? 'Remove from Starred' : 'Add to Starred'"
    aria-pressed="false" title="Add to Starred" aria-label="Star {{ $label }}">
    <x-reicon name="star" x-show="!starred" />
    <x-reicon name="star-filled" x-cloak x-show="starred" />
</button>
