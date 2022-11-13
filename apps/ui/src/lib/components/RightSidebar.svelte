<div class="flex flex-col md:flex-row">
  <div class="md:basis-2/3">
    <slot name="content"/>
  </div>
  <div class="md:basis-1/3">
    <div class="md:ml-4">
      <slot name="sidebar"/>
    </div>
  </div>
</div>