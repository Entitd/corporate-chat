export function messageContextMenu() {
    let timer = null;
    let pointer = null;
    let suppressClick = false;

    return {
        openMessageMenu(event) {
            event?.preventDefault();
            if (pointer || event?.pointerType === 'touch') suppressClick = true;
            this.cancelMessagePress();
            const dropdown = this.$refs.messageDropdown;
            if (!dropdown) return;
            // Flux restores focus here when the menu closes. Avoid scrolling to it.
            dropdown.querySelector('[data-test=message-actions]')?.focus({ preventScroll: true });
            dropdown.value = true;
        },

        startMessagePress(event) {
            this.cancelMessagePress();
            suppressClick = false;
            if (event.pointerType !== 'touch' || !event.isPrimary) return;
            pointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
            timer = setTimeout(() => {
                timer = null;
                pointer = null;
                suppressClick = true;
                this.openMessageMenu();
            }, 500);
        },

        moveMessagePress(event) {
            if (!pointer || event.pointerId !== pointer.id) return;
            if (Math.hypot(event.clientX - pointer.x, event.clientY - pointer.y) > 10) this.cancelMessagePress();
        },

        cancelMessagePress() {
            clearTimeout(timer);
            timer = null;
            pointer = null;
        },

        messageContextClick(event) {
            if (!suppressClick) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            suppressClick = false;
        },

        destroy() { this.cancelMessagePress(); },
    };
}

window.messageContextMenu = messageContextMenu;
