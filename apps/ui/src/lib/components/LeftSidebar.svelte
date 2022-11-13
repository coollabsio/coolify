<div class="flex flex-col md:flex-row">
  {#if $$slots.sidebar}
    <div class="md:w-64 md:mr-5">
      <slot name="sidebar"/>
    </div>
  {/if}
  <div class="md:flex-grow">
    <slot name="content"/>
  </div>
</div>