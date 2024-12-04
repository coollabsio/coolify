<div x-data="{
    title: 'Default Toast Notification',
    description: '',
    type: 'default',
    expanded: false,
    popToast(custom) {
        let html = '';
        if (typeof custom != 'undefined') {
            html = custom;
        }
        toast(this.title, { description: this.description, type: this.type, position: this.position, html: html })
    }
}" x-init="window.toast = function(message, options = {}) {
    try {
        let description = '';
        let type = 'default';
        let position = 'top-center';
        let html = '';
        if (typeof options.description != 'undefined') description = options.description;
        if (typeof options.type != 'undefined') type = options.type;
        if (typeof options.position != 'undefined') position = options.position;
        if (typeof options.html != 'undefined') html = options.html;

        window.dispatchEvent(new CustomEvent('toast-show', { detail: { type: type, message: message, description: description, position: position, html: html } }));
    } catch (error) {
        console.error('Error showing toast:', error);
    }
}" class="relative space-y-5">
    <template x-teleport="body">
        <ul x-data="{
            toasts: [],
            toastsHovered: false,
            timeout: null,
            expanded: false,
            layout: 'default',
            position: '',
            paddingBetweenToasts: 15,
            deleteToastWithId(id) {
                for (let i = 0; i < this.toasts.length; i++) {
                    if (this.toasts[i].id === id) {
                        this.toasts.splice(i, 1);
                        break;
                    }
                }
            },
            burnToast(id) {
                burnToast = this.getToastWithId(id);
                burnToastElement = document.getElementById(burnToast.id);
                if (burnToastElement) {
                    if (this.toasts.length == 1) {
                        if (this.layout == 'default') {
                            this.expanded = false;
                        }
                        burnToastElement.classList.remove('translate-y-0');
                        if (this.position.includes('bottom')) {
                            burnToastElement.classList.add('translate-y-full');
                        } else {
                            burnToastElement.classList.add('-translate-y-full');
                        }
                        burnToastElement.classList.add('-translate-y-full');
                    }
                    burnToastElement.classList.add('opacity-0');
                    let that = this;
                    setTimeout(function() {
                        that.deleteToastWithId(id);
                        setTimeout(function() {
                            that.stackToasts();
                        }, 1)
                    }, 300);
                }
            },
            getToastWithId(id) {
                for (let i = 0; i < this.toasts.length; i++) {
                    if (this.toasts[i].id === id) {
                        return this.toasts[i];
                    }
                }
            },
            stackToasts() {
                this.positionToasts();
                this.calculateHeightOfToastsContainer();
                let that = this;
                setTimeout(function() {
                    that.calculateHeightOfToastsContainer();
                }, 300);
            },
            positionToasts() {
                if (this.toasts.length == 0) return;
                let topToast = document.getElementById(this.toasts[0].id);
                topToast.style.zIndex = 100;
                if (this.expanded) {
                    if (this.position.includes('bottom')) {
                        topToast.style.top = 'auto';
                        topToast.style.bottom = '0px';
                    } else {
                        topToast.style.top = '0px';
                    }
                }

                let bottomPositionOfFirstToast = this.getBottomPositionOfElement(topToast);

                if (this.toasts.length == 1) return;
                let middleToast = document.getElementById(this.toasts[1].id);
                middleToast.style.zIndex = 90;

                if (this.expanded) {
                    middleToastPosition = topToast.getBoundingClientRect().height +
                        this.paddingBetweenToasts + 'px';

                    if (this.position.includes('bottom')) {
                        middleToast.style.top = 'auto';
                        middleToast.style.bottom = middleToastPosition;
                    } else {
                        middleToast.style.top = middleToastPosition;
                    }

                    middleToast.style.scale = '100%';
                    middleToast.style.transform = 'translateY(0px)';

                } else {
                    middleToast.style.scale = '94%';
                    if (this.position.includes('bottom')) {
                        middleToast.style.transform = 'translateY(-16px)';
                    } else {
                        this.alignBottom(topToast, middleToast);
                        middleToast.style.transform = 'translateY(16px)';
                    }
                }


                if (this.toasts.length == 2) return;
                let bottomToast = document.getElementById(this.toasts[2].id);
                bottomToast.style.zIndex = 80;
                if (this.expanded) {
                    bottomToastPosition = topToast.getBoundingClientRect().height +
                        this.paddingBetweenToasts +
                        middleToast.getBoundingClientRect().height +
                        this.paddingBetweenToasts + 'px';

                    if (this.position.includes('bottom')) {
                        bottomToast.style.top = 'auto';
                        bottomToast.style.bottom = bottomToastPosition;
                    } else {
                        bottomToast.style.top = bottomToastPosition;
                    }

                    bottomToast.style.scale = '100%';
                    bottomToast.style.transform = 'translateY(0px)';
                } else {
                    bottomToast.style.scale = '88%';
                    if (this.position.includes('bottom')) {
                        bottomToast.style.transform = 'translateY(-32px)';
                    } else {
                        this.alignBottom(topToast, bottomToast);
                        bottomToast.style.transform = 'translateY(32px)';
                    }
                }



                if (this.toasts.length == 3) return;
                let burnToast = document.getElementById(this.toasts[3].id);
                burnToast.style.zIndex = 70;
                if (this.expanded) {
                    burnToastPosition = topToast.getBoundingClientRect().height +
                        this.paddingBetweenToasts +
                        middleToast.getBoundingClientRect().height +
                        this.paddingBetweenToasts +
                        bottomToast.getBoundingClientRect().height +
                        this.paddingBetweenToasts + 'px';

                    if (this.position.includes('bottom')) {
                        burnToast.style.top = 'auto';
                        burnToast.style.bottom = burnToastPosition;
                    } else {
                        burnToast.style.top = burnToastPosition;
                    }

                    burnToast.style.scale = '100%';
                    burnToast.style.transform = 'translateY(0px)';
                } else {
                    burnToast.style.scale = '82%';
                    this.alignBottom(topToast, burnToast);
                    burnToast.style.transform = 'translateY(48px)';
                }

                burnToast.firstElementChild.classList.remove('opacity-100');
                burnToast.firstElementChild.classList.add('opacity-0');

                let that = this;
                // Burn 🔥 (remove) last toast
                setTimeout(function() {
                    that.toasts.pop();
                }, 300);

                if (this.position.includes('bottom')) {
                    middleToast.style.top = 'auto';
                }

                return;
            },
            alignBottom(element1, element2) {
                // Get the top position and height of the first element
                let top1 = element1.offsetTop;
                let height1 = element1.offsetHeight;

                // Get the height of the second element
                let height2 = element2.offsetHeight;

                // Calculate the top position for the second element
                let top2 = top1 + (height1 - height2);

                // Apply the calculated top position to the second element
                element2.style.top = top2 + 'px';
            },
            alignTop(element1, element2) {
                // Get the top position of the first element
                let top1 = element1.offsetTop;

                // Apply the same top position to the second element
                element2.style.top = top1 + 'px';
            },
            resetBottom() {
                for (let i = 0; i < this.toasts.length; i++) {
                    if (document.getElementById(this.toasts[i].id)) {
                        let toastElement = document.getElementById(this.toasts[i].id);
                        toastElement.style.bottom = '0px';
                    }
                }
            },
            resetTop() {
                for (let i = 0; i < this.toasts.length; i++) {
                    if (document.getElementById(this.toasts[i].id)) {
                        let toastElement = document.getElementById(this.toasts[i].id);
                        toastElement.style.top = '0px';
                    }
                }
            },
            getBottomPositionOfElement(el) {
                return (el.getBoundingClientRect().height + el.getBoundingClientRect().top);
            },
            calculateHeightOfToastsContainer() {
                if (this.toasts.length == 0) {
                    $el.style.height = '0px';
                    return;
                }

                lastToast = this.toasts[this.toasts.length - 1];
                lastToastRectangle = document.getElementById(lastToast.id).getBoundingClientRect();

                firstToast = this.toasts[0];
                firstToastRectangle = document.getElementById(firstToast.id).getBoundingClientRect();

                if (this.toastsHovered) {
                    if (this.position.includes('bottom')) {
                        $el.style.height = ((firstToastRectangle.top + firstToastRectangle.height) - lastToastRectangle.top) + 'px';
                    } else {
                        $el.style.height = ((lastToastRectangle.top + lastToastRectangle.height) - firstToastRectangle.top) + 'px';
                    }
                } else {
                    $el.style.height = firstToastRectangle.height + 'px';
                }
            }
        }"
            @set-toasts-layout.window="
                layout=event.detail.layout;
                if(layout == 'expanded'){
                    expanded=true;
                } else {
                    expanded=false;
                }
                stackToasts();
            "
            @toast-show.window="
                event.stopPropagation();
                if(event.detail.position){
                    position = event.detail.position;
                }
                toasts.unshift({
                    id: 'toast-' + Math.random().toString(16).slice(2),
                    show: false,
                    message: event.detail.message,
                    description: event.detail.description,
                    type: event.detail.type,
                    html: event.detail.html
                });
            "
            @mouseenter="toastsHovered=true;" @mouseleave="toastsHovered=false" x-init="if (layout == 'expanded') {
                expanded = true;
            }
            stackToasts();
            $watch('toastsHovered', function(value) {
                if (layout == 'default') {
                    if (position.includes('bottom')) {
                        resetBottom();
                    } else {
                        resetTop();
                    }
                    if (value) {
                        // calculate the new positions
                        expanded = true;
                        if (layout == 'default') {
                            stackToasts();
                        }
                    } else {
                        if (layout == 'default') {
                            expanded = false;
                            //setTimeout(function(){
                            stackToasts();
                            //}, 10);
                            setTimeout(function() {
                                stackToasts();
                            }, 10)
                        }
                    }
                }
            });"
            class="fixed block w-full group z-[9999] sm:max-w-xs"
            :class="{ 'right-0 top-0 sm:mt-6 sm:mr-6': position=='top-right', 'left-0 top-0 sm:mt-6 sm:ml-6': position=='top-left', 'left-1/2 -translate-x-1/2 top-0 sm:mt-6': position=='top-center', 'right-0 bottom-0 sm:mr-6 sm:mb-6': position=='bottom-right', 'left-0 bottom-0 sm:ml-6 sm:mb-6': position=='bottom-left', 'left-1/2 -translate-x-1/2 bottom-0 sm:mb-6': position=='bottom-center' }"
            x-cloak>

            <template x-for="(toast, index) in toasts" :key="toast.id">
                <li :id="toast.id" x-data="{
                    toastHovered: false,
                    copyNotification: false,
                    copyToClipboard() {
                        navigator.clipboard.writeText(toast.description);
                        this.copyNotification = true;
                        let that = this;
                        setTimeout(function() {
                            that.copyNotification = false;
                        }, 1000);
                    }
                }" x-init="if (position.includes('bottom')) {
                    $el.firstElementChild.classList.add('toast-bottom');
                    $el.firstElementChild.classList.add('opacity-0', 'translate-y-full');
                } else {
                    $el.firstElementChild.classList.add('opacity-0', '-translate-y-full');
                }
                $watch('toastsHovered', function(value) {
                    if (value && this.timeout) {
                        clearTimeout(this.timeout);
                    } else {
                        this.timeout = setTimeout(function() {
                            setTimeout(function() {
                                $el.firstElementChild.classList.remove('opacity-100');
                                $el.firstElementChild.classList.add('opacity-0');
                                if (toasts.length == 1) {
                                    $el.firstElementChild.classList.remove('translate-y-0');
                                    $el.firstElementChild.classList.add('-translate-y-full');
                                }
                                setTimeout(function() {
                                    deleteToastWithId(toast.id)
                                }, 300);
                            }, 5);
                        }, 2000)
                    }
                });

                setTimeout(function() {

                    setTimeout(function() {
                        if (position.includes('bottom')) {
                            $el.firstElementChild.classList.remove('opacity-0', 'translate-y-full');
                        } else {
                            $el.firstElementChild.classList.remove('opacity-0', '-translate-y-full');
                        }
                        $el.firstElementChild.classList.add('opacity-100', 'translate-y-0');

                        setTimeout(function() {
                            stackToasts();
                        }, 10);
                    }, 5);
                }, 50);

                this.timeout = setTimeout(function() {
                    setTimeout(function() {
                        $el.firstElementChild.classList.remove('opacity-100');
                        $el.firstElementChild.classList.add('opacity-0');
                        if (toasts.length == 1) {
                            $el.firstElementChild.classList.remove('translate-y-0');
                            $el.firstElementChild.classList.add('-translate-y-full');
                        }
                        setTimeout(function() {
                            deleteToastWithId(toast.id)
                        }, 300);
                    }, 5);
                }, 4000);"
                    @mouseover="toastHovered=true" @mouseout="toastHovered=false"
                    class="absolute w-full duration-100 ease-out sm:max-w-xs "
                    :class="{ 'toast-no-description': !toast.description }">
                    <span
                        class="relative flex flex-col items-start shadow-[0_5px_15px_-3px_rgb(0_0_0_/_0.08)] w-full transition-all duration-100 ease-out dark:bg-coolgray-100 bg-white dark:border dark:border-coolgray-200 rounded sm:max-w-xs group"
                        :class="{ 'p-4': !toast.html, 'p-0': toast.html }">
                        <template x-if="!toast.html">
                            <div class="relative w-full">
                                <div class="flex items-start"
                                    :class="{ 'text-green-500': toast.type=='success', 'text-blue-500': toast.type=='info', 'text-orange-400': toast.type=='warning', 'text-red-500': toast.type=='danger', 'text-gray-800': toast.type=='default' }">

                                    <svg x-show="toast.type=='success'" class="w-[18px] h-[18px] mr-1.5 -ml-1"
                                        viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd"
                                            d="M12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2ZM16.7744 9.63269C17.1238 9.20501 17.0604 8.57503 16.6327 8.22559C16.2051 7.87615 15.5751 7.93957 15.2256 8.36725L10.6321 13.9892L8.65936 12.2524C8.24484 11.8874 7.61295 11.9276 7.248 12.3421C6.88304 12.7566 6.92322 13.3885 7.33774 13.7535L9.31046 15.4903C10.1612 16.2393 11.4637 16.1324 12.1808 15.2547L16.7744 9.63269Z"
                                            fill="currentColor"></path>
                                    </svg>
                                    <svg x-show="toast.type=='info'" class="w-[18px] h-[18px] mr-1.5 -ml-1"
                                        viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd"
                                            d="M12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2ZM12 9C12.5523 9 13 8.55228 13 8C13 7.44772 12.5523 7 12 7C11.4477 7 11 7.44772 11 8C11 8.55228 11.4477 9 12 9ZM13 12C13 11.4477 12.5523 11 12 11C11.4477 11 11 11.4477 11 12V16C11 16.5523 11.4477 17 12 17C12.5523 17 13 16.5523 13 16V12Z"
                                            fill="currentColor"></path>
                                    </svg>
                                    <svg x-show="toast.type=='warning'" class="w-[18px] h-[18px] mr-1.5 -ml-1"
                                        viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd"
                                            d="M9.44829 4.46472C10.5836 2.51208 13.4105 2.51168 14.5464 4.46401L21.5988 16.5855C22.7423 18.5509 21.3145 21 19.05 21L4.94967 21C2.68547 21 1.25762 18.5516 2.4004 16.5862L9.44829 4.46472ZM11.9995 8C12.5518 8 12.9995 8.44772 12.9995 9V13C12.9995 13.5523 12.5518 14 11.9995 14C11.4473 14 10.9995 13.5523 10.9995 13V9C10.9995 8.44772 11.4473 8 11.9995 8ZM12.0009 15.99C11.4486 15.9892 11.0003 16.4363 10.9995 16.9886L10.9995 16.9986C10.9987 17.5509 11.4458 17.9992 11.9981 18C12.5504 18.0008 12.9987 17.5537 12.9995 17.0014L12.9995 16.9914C13.0003 16.4391 12.5532 15.9908 12.0009 15.99Z"
                                            fill="currentColor"></path>
                                    </svg>
                                    <svg x-show="toast.type=='danger'" class="w-[18px] h-[18px] mr-1.5 -ml-1"
                                        viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd"
                                            d="M2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12ZM11.9996 7C12.5519 7 12.9996 7.44772 12.9996 8V12C12.9996 12.5523 12.5519 13 11.9996 13C11.4474 13 10.9996 12.5523 10.9996 12V8C10.9996 7.44772 11.4474 7 11.9996 7ZM12.001 14.99C11.4488 14.9892 11.0004 15.4363 10.9997 15.9886L10.9996 15.9986C10.9989 16.5509 11.446 16.9992 11.9982 17C12.5505 17.0008 12.9989 16.5537 12.9996 16.0014L12.9996 15.9914C13.0004 15.4391 12.5533 14.9908 12.001 14.99Z"
                                            fill="currentColor"></path>
                                    </svg>
                                    <p class="text-black leading-2 dark:text-neutral-200" x-html="toast.message">
                                    </p>
                                </div>
                                <div x-show="toast.description" :class="{ 'pl-5': toast.type!='default' }"
                                    class="mt-1.5 text-xs px-2 opacity-90 whitespace-pre-wrap w-full break-words"
                                    x-html="toast.description"></div>
                            </div>
                        </template>
                        <template x-if="toast.html">
                            <div x-html="toast.html"></div>
                        </template>
                        <span class="absolute mt-1 text-xs right-[4.4rem] text-success font-bold"
                            x-show="copyNotification"
                            :class="{
                                'opacity-100': toastHovered,
                                'opacity-0': !
                                    toastHovered
                            }">
                            Copied
                        </span>
                        <span @click="copyToClipboard()"
                            class="absolute right-7 p-1.5 mr-2.5 text-neutral-700 hover:text-neutral-900 dark:text-neutral-400 hover:bg-neutral-300  duration-100 ease-in-out rounded-full opacity-0 cursor-pointer dark:hover:bg-coolgray-400 dark:hover:text-neutral-300"
                            :class="{
                                'top-1/2 -translate-y-1/2': !toast.description && !toast.html,
                                'top-0 mt-3': (toast
                                    .description || toast.html),
                                'opacity-100': toastHovered,
                                'opacity-0': !
                                    toastHovered
                            }">

                            <svg class="w-4 h-4 stroke-current" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                            </svg>
                        </span>
                        <span @click="burnToast(toast.id)"
                            class="absolute right-0 p-1.5 mr-2.5 text-neutral-700 hover:text-neutral-900 dark:text-neutral-400 duration-100 ease-in-out rounded-full opacity-0 cursor-pointer hover:bg-neutral-300 dark:hover:bg-coolgray-400 dark:hover:text-neutral-300"
                            :class="{
                                'top-1/2 -translate-y-1/2': !toast.description && !toast.html,
                                'top-0 mt-3.5': (toast
                                    .description || toast.html),
                                'opacity-100': toastHovered,
                                'opacity-0': !
                                    toastHovered
                            }">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"
                                xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd"
                                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                    clip-rule="evenodd"></path>
                            </svg>
                        </span>
                    </span>
                </li>
            </template>
        </ul>
    </template>
</div>
