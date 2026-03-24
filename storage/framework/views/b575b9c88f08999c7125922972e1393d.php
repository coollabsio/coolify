<button <?php if($disabled): echo 'disabled'; endif; ?> <?php echo e($attributes->merge(['class' => $defaultClass])); ?>

    <?php echo e($attributes->merge(['type' => 'button'])); ?>

    <?php if(isset($confirm)): ?>
            x-on:click="toggleConfirmModal('<?php echo e($confirm); ?>', '<?php echo e(explode('(', $confirmAction)[0]); ?>')"
        <?php endif; ?>
    <?php if(isset($confirmAction)): ?>
            x-on:<?php echo e(explode('(', $confirmAction)[0]); ?>.window="$wire.<?php echo e(explode('(', $confirmAction)[0]); ?>"
        <?php endif; ?>>

    <?php echo e($slot); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showLoadingIndicator): ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($attributes->whereStartsWith('wire:click')->first()): ?>
            <?php if (isset($component)) { $__componentOriginal33a67208644958aa0cbde2c766298751 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal33a67208644958aa0cbde2c766298751 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.loading-on-button','data' => ['wire:target' => ''.e($attributes->whereStartsWith('wire:click')->first()).'','wire:loading.delay' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('loading-on-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:target' => ''.e($attributes->whereStartsWith('wire:click')->first()).'','wire:loading.delay' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal33a67208644958aa0cbde2c766298751)): ?>
<?php $attributes = $__attributesOriginal33a67208644958aa0cbde2c766298751; ?>
<?php unset($__attributesOriginal33a67208644958aa0cbde2c766298751); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal33a67208644958aa0cbde2c766298751)): ?>
<?php $component = $__componentOriginal33a67208644958aa0cbde2c766298751; ?>
<?php unset($__componentOriginal33a67208644958aa0cbde2c766298751); ?>
<?php endif; ?>
        <?php elseif($attributes->whereStartsWith('wire:target')->first()): ?>
            <?php if (isset($component)) { $__componentOriginal33a67208644958aa0cbde2c766298751 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal33a67208644958aa0cbde2c766298751 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.loading-on-button','data' => ['wire:target' => ''.e($attributes->whereStartsWith('wire:target')->first()).'','wire:loading.delay' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('loading-on-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:target' => ''.e($attributes->whereStartsWith('wire:target')->first()).'','wire:loading.delay' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal33a67208644958aa0cbde2c766298751)): ?>
<?php $attributes = $__attributesOriginal33a67208644958aa0cbde2c766298751; ?>
<?php unset($__attributesOriginal33a67208644958aa0cbde2c766298751); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal33a67208644958aa0cbde2c766298751)): ?>
<?php $component = $__componentOriginal33a67208644958aa0cbde2c766298751; ?>
<?php unset($__componentOriginal33a67208644958aa0cbde2c766298751); ?>
<?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</button>
<?php /**PATH /var/www/html/resources/views/components/forms/button.blade.php ENDPATH**/ ?>