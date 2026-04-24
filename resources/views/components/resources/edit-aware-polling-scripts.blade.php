@once
    @script
        <script>
            if (!window.coolifyEditAwarePoller) {
                window.coolifyEditAwarePoller = function($wire, method, interval = 10000) {
                    return {
                        isEditing: false,
                        isRefreshing: false,
                        pollingTimer: null,
                        init() {
                            this.pollingTimer = window.setInterval(() => {
                                if (!this.isEditing && !document.hidden) {
                                    this.refreshStatus();
                                }
                            }, interval);
                        },
                        async refreshStatus() {
                            if (this.isRefreshing) {
                                return;
                            }

                            this.isRefreshing = true;

                            try {
                                await $wire.$call(method);
                            } finally {
                                this.isRefreshing = false;
                            }
                        },
                        pause() {
                            this.isEditing = true;
                        },
                        resumeAndRefresh() {
                            this.isEditing = false;
                            this.refreshStatus();
                        },
                        destroy() {
                            if (this.pollingTimer) {
                                window.clearInterval(this.pollingTimer);
                            }
                        },
                    };
                };
            }

            if (!window.coolifyFormFocusTracker) {
                window.coolifyFormFocusTracker = function(
                    startedEvent = 'coolify-form-editing-started',
                    finishedEvent = 'coolify-form-editing-finished',
                ) {
                    return {
                        isEditing: false,
                        startEditing() {
                            if (this.isEditing) {
                                return;
                            }

                            this.isEditing = true;
                            window.dispatchEvent(new CustomEvent(startedEvent));
                        },
                        finishEditing() {
                            this.$nextTick(() => {
                                if (!this.isEditing || this.$el.contains(document.activeElement)) {
                                    return;
                                }

                                this.isEditing = false;
                                window.dispatchEvent(new CustomEvent(finishedEvent));
                            });
                        },
                    };
                };
            }
        </script>
    @endscript
@endonce
