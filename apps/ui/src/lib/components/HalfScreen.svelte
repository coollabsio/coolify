<div class="flex flex-col md:flex-row">
  <div class="md:basis-1/2">
    <slot name="content"/>
  </div>
  {#if $$slots.sidebar}
    <div class="md:basis-1/2">
      <div class="md:ml-4">
        <slot name="sidebar"/>
      </div>
    </div>
  {/if}
</div>