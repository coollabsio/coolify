<div class="flex flex-col md:flex-row">
  <div class="md:w-64 md:mr-5">
    <slot name="sidebar"/>
  </div>
  <div class="md:flex-grow">
    <slot name="content"/>
  </div>
</div>