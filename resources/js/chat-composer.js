export function chatComposer() {
    return {
        sending: false,
        uploading: false,
        uploadProgress: 0,
        inputHeight: 44,
        stackActions: false,
        init() {
            this.$watch('$wire.messageBody', () => this.$nextTick(() => this.resizeInput()));
            this.$nextTick(() => this.resizeInput());
        },
        resizeInput() {
            const input = this.$refs.messageInput;
            if (!input || !input.getClientRects().length) return;
            // Measure from one line, including when the previous draft was taller.
            input.style.height = '44px';
            let compactHeight = input.scrollHeight + 2;
            const actions = this.$refs.composerActions;
            if (actions) {
                // Always test wrapping at the width with both buttons side by side.
                // Otherwise stacking the buttons can make the same text fit one line.
                const buttons = [...actions.querySelectorAll('button')];
                const gap = parseFloat(getComputedStyle(actions).columnGap) || 0;
                const compactActionsWidth = buttons.reduce((width, button) => width + button.getBoundingClientRect().width, 0)
                    + Math.max(0, buttons.length - 1) * gap;
                const extraWidth = Math.max(0, compactActionsWidth - actions.getBoundingClientRect().width);
                const previousWidth = input.style.width;
                const previousFlex = input.style.flex;
                const compactWidth = input.getBoundingClientRect().width - extraWidth;
                input.style.flex = 'none';
                input.style.width = `${Math.max(44, compactWidth)}px`;
                compactHeight = input.scrollHeight + 2;
                input.style.width = previousWidth;
                input.style.flex = previousFlex;
            }
            const shouldStack = compactHeight > 44;
            const layoutChanged = this.stackActions !== shouldStack;
            this.stackActions = shouldStack;
            const contentHeight = input.scrollHeight + 2;
            const minimumHeight = shouldStack ? 88 : 44;
            this.inputHeight = Math.min(Math.max(contentHeight, minimumHeight), 160);
            input.style.height = `${this.inputHeight}px`;
            if (layoutChanged) this.$nextTick(() => this.resizeInput());
        },
        syncMentionSearch(value) {
            // Ordinary typing stays local. Only mention suggestions need a server render.
            if (/(?:^|\s)@[\p{L}\p{N}._-]*$/u.test(value) || this.$wire.showMentionPicker) {
                this.$wire.$set('messageBody', value);
            }
        },
    };
}

window.chatComposer = chatComposer;
