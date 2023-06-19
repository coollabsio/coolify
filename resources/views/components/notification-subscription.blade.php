 <h4 class="mt-4">Subscribe to events</h4>
 <div class="w-64 ">
     @if (isDev())
         <x-forms.checkbox instantSave="instantSaveEvents" id="model.extra_attributes.notifications_test"
             label="Test Notifications" />
     @endif
     <x-forms.checkbox instantSave="instantSaveEvents" id="model.extra_attributes.notifications_deployments"
         label="New Deployments" />
 </div>
