export function watchChatTabTitle(element) {
    const originalTitle = document.title;
    let currentTitle = originalTitle;

    const update = () => {
        const count = Number(element.dataset.unreadCount) || 0;
        currentTitle = count > 0
            ? `${count === 1 ? 'Новое сообщение' : 'Новые сообщения'} (${count}) — ${originalTitle}`
            : originalTitle;
        document.title = currentTitle;
    };

    const observer = new MutationObserver(update);
    observer.observe(element, { attributes: true, attributeFilter: ['data-unread-count'] });
    update();

    return () => {
        observer.disconnect();
        if (document.title === currentTitle) document.title = originalTitle;
    };
}

window.watchChatTabTitle = watchChatTabTitle;
