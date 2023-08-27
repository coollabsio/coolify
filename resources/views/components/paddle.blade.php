<x-slot:basic>
    <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-basic" class="w-full h-10 buyme"
        x-on:click="subscribe('basic-monthly')"> Subscribe
    </x-forms.button>

    <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-basic" class="w-full h-10 buyme"
        x-on:click="subscribe('basic-yearly')"> Subscribe
    </x-forms.button>
</x-slot:basic>
<x-slot:pro>
    <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-pro" class="w-full h-10 buyme"
        x-on:click="subscribe('pro-monthly')"> Subscribe
    </x-forms.button>

    <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-pro" class="w-full h-10 buyme"
        x-on:click="subscribe('pro-yearly')"> Subscribe
    </x-forms.button>
</x-slot:pro>
<x-slot:ultimate>
    <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-ultimate" class="w-full h-10 buyme"
        x-on:click="subscribe('ultimate-monthly')"> Subscribe
    </x-forms.button>

    <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-ultimate" class="w-full h-10 buyme"
        x-on:click="subscribe('ultimate-yearly')"> Subscribe
    </x-forms.button>
</x-slot:ultimate>
<x-slot:other>
    <script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
    <script type="text/javascript">
        Paddle.Environment.set("{{ isDev() ? 'sandbox' : 'production' }}");
        Paddle.Setup({
            seller: {{ config('subscription.paddle_vendor_id') }},
            checkout: {
                settings: {
                    displayMode: "overlay",
                    theme: "light",
                }
            }
        });

        function subscribe(type) {
            let priceId = null
            switch (type) {
                case 'basic-monthly':
                    priceId = "{{ config('subscription.paddle_price_id_basic_monthly') }}"
                    break;
                case 'basic-yearly':
                    priceId = "{{ config('subscription.paddle_price_id_basic_yearly') }}"
                    break;
                case 'pro-monthly':
                    priceId = "{{ config('subscription.paddle_price_id_pro_monthly') }}"
                    break;
                case 'pro-yearly':
                    priceId = "{{ config('subscription.paddle_price_id_pro_yearly') }}"
                    break;
                case 'ultimate-monthly':
                    priceId = "{{ config('subscription.paddle_price_id_ultimate_monthly') }}"
                    break;
                case 'ultimate-yearly':
                    priceId = "{{ config('subscription.paddle_price_id_ultimate_yearly') }}"
                    break;
                default:
                    break;
            }
            Paddle.Checkout.open({
                customer: {
                    email: '{{ auth()->user()->email }}',
                },
                customData: {
                    "team_id": "{{ currentTeam()->id }}",
                },
                items: [{
                    priceId,
                    quantity: 1
                }],
            });
        }
    </script>
</x-slot:other>
