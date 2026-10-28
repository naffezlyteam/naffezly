document.addEventListener('DOMContentLoaded', function () {
    const selectAllNotifications = document.querySelector('[data-select-all-notifications]');
    const notificationBulkForm = document.getElementById('notification-bulk-form');
    if (selectAllNotifications && notificationBulkForm) {
        const notificationCheckboxes = Array.from(document.querySelectorAll('input[name="ids[]"]'))
            .filter((checkbox) => checkbox.form === notificationBulkForm);
        const updateSelectAllState = () => {
            const selectedCount = notificationCheckboxes.filter((checkbox) => checkbox.checked).length;
            selectAllNotifications.checked = notificationCheckboxes.length > 0 && selectedCount === notificationCheckboxes.length;
            selectAllNotifications.indeterminate = selectedCount > 0 && selectedCount < notificationCheckboxes.length;
        };
        selectAllNotifications.addEventListener('change', function () {
            notificationCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAllNotifications.checked;
            });
            selectAllNotifications.indeterminate = false;
        });
        notificationCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelectAllState));
        updateSelectAllState();
    }

    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });

    const addToCartForm = document.querySelector('[data-add-to-cart]');
    if (addToCartForm) {
        addToCartForm.addEventListener('submit', function () {
            const button = addToCartForm.querySelector('button');
            if (button) {
                button.disabled = true;
                button.textContent = button.dataset.adding || 'Adding…';
            }
        });
    }

    const checkoutForm = document.querySelector('[data-checkout-form]');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', () => {
            const button = checkoutForm.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = button.dataset.submitting || 'Submitting…';
            }
        });
    }

    const conversation = document.querySelector('[data-conversation]');
    if (!conversation) {
        return;
    }

    const list = conversation.querySelector('[data-messages]');
    const form = conversation.querySelector('[data-message-form]');
    const feedback = conversation.querySelector('[data-feedback]');
    const endpoint = conversation.dataset.api;
    const kind = conversation.dataset.kind;
    const id = conversation.dataset.id;
    const context = conversation.dataset.admin === '1' ? 'admin' : 'user';
    const uiText = conversation.dataset.language === 'ar'
        ? {
            messageDeleted: 'تم حذف الرسالة.',
            you: 'أنت',
            customer: 'العميل',
            naffezly: 'Naffezly',
            restore: 'استعادة',
            delete: 'حذف',
            loadError: 'تعذر تحميل هذه المحادثة.',
            empty: 'لا توجد رسائل حتى الآن. أرسل أول رسالة لبدء المحادثة.',
            sending: 'جارٍ الإرسال…',
            sendError: 'تعذر إرسال الرسالة.',
            sent: 'تم إرسال الرسالة.',
            confirmDelete: 'حذف هذه الرسالة نهائيًا؟ لا يمكن التراجع عن ذلك.',
            updateError: 'تعذر تحديث هذه الرسالة.',
            moved: 'تم حذف الرسالة نهائيًا.',
            restored: 'تمت استعادة الرسالة.'
        }
        : {
            messageDeleted: 'Message deleted.',
            you: 'You',
            customer: 'Customer',
            naffezly: 'Naffezly',
            restore: 'Restore',
            delete: 'Delete',
            loadError: 'Could not load this conversation.',
            empty: 'No messages yet. Send the first message to start this conversation.',
            sending: 'Sending…',
            sendError: 'Message could not be sent.',
            sent: 'Message sent.',
            confirmDelete: 'Permanently delete this message? This cannot be undone.',
            updateError: 'Could not update this message.',
            moved: 'Message permanently deleted.',
            restored: 'Message restored.'
        };
    let latestId = 0;
    let loading = false;

    const appendMessage = (message, ownMessage) => {
        const emptyState = list.querySelector('.conversation-empty');
        if (emptyState) {
            emptyState.remove();
        }
        const item = document.createElement('article');
        item.className = 'chat-message ' + (ownMessage ? 'own' : 'incoming');
        item.dataset.messageId = String(message.id);
        const content = document.createElement('p');
        if (message.deleted_at) {
            item.classList.add('deleted');
            content.textContent = uiText.messageDeleted;
        } else {
            content.textContent = message.message;
        }
        const meta = document.createElement('small');
        meta.textContent = (ownMessage ? uiText.you : (conversation.dataset.admin === '1' ? uiText.customer : uiText.naffezly)) + ' · ' + message.created_at;
        item.append(content, meta);
        if (conversation.dataset.admin === '1' && conversation.dataset.canDelete === '1') {
            const action = document.createElement('button');
            action.type = 'button';
            action.className = 'button button-light message-delete-action';
            action.dataset.messageAction = message.deleted_at ? 'restore' : 'delete';
            action.textContent = message.deleted_at ? uiText.restore : uiText.delete;
            item.append(action);
        }
        list.append(item);
        list.scrollTop = list.scrollHeight;
        latestId = Math.max(latestId, Number(message.id));
    };

    const loadMessages = async (initial = false) => {
        if (loading) return;
        loading = true;
        try {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('kind', kind);
            url.searchParams.set('id', id);
            url.searchParams.set('context', context);
            if (latestId && !initial) url.searchParams.set('since', String(latestId));
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || uiText.loadError);
            if (initial) list.replaceChildren();
            data.messages.forEach((message) => appendMessage(message, (conversation.dataset.admin === '1') === (message.sender_type === 'admin')));
            if (initial && data.messages.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'conversation-empty';
                empty.textContent = uiText.empty;
                list.append(empty);
            }
        } catch (error) {
            if (initial) list.textContent = error.message;
            else feedback.textContent = error.message;
        } finally {
            loading = false;
        }
    };

    loadMessages(true);
    if (form) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (form.dataset.sending === '1') return;
            const textarea = form.querySelector('textarea[name="message"]');
            const button = form.querySelector('button[type="submit"]');
            const text = textarea.value.trim();
            if (!text) return;
            form.dataset.sending = '1';
            button.disabled = true;
            feedback.textContent = uiText.sending;
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                    body: new URLSearchParams({ kind, id, context, csrf_token: conversation.dataset.csrf, message: text })
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || uiText.sendError);
                textarea.value = '';
                appendMessage(data.message, true);
                feedback.textContent = uiText.sent;
            } catch (error) {
                feedback.textContent = error.message;
                textarea.value = text;
                textarea.focus();
            } finally {
                form.dataset.sending = '0';
                button.disabled = false;
            }
        });
    }

    if (conversation.dataset.admin === '1' && conversation.dataset.canDelete === '1') {
        list.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-message-action]');
            if (!button) return;
            const action = button.dataset.messageAction;
            if (action === 'delete' && !window.confirm(uiText.confirmDelete)) return;
            const item = button.closest('[data-message-id]');
            button.disabled = true;
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                    body: new URLSearchParams({
                        kind,
                        id,
                        context,
                        message_id: item.dataset.messageId,
                        action,
                        csrf_token: conversation.dataset.csrf
                    })
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || uiText.updateError);
                const paragraph = item.querySelector('p');
                if (action === 'delete') {
                    item.remove();
                    if (!list.querySelector('[data-message-id]')) {
                        const empty = document.createElement('div');
                        empty.className = 'conversation-empty';
                        empty.textContent = uiText.empty;
                        list.append(empty);
                    }
                } else {
                    paragraph.textContent = data.message.message;
                    item.classList.remove('deleted');
                    button.dataset.messageAction = 'delete';
                    button.textContent = uiText.delete;
                }
                feedback.textContent = action === 'delete' ? uiText.moved : uiText.restored;
            } catch (error) {
                feedback.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
    }

    window.setInterval(() => {
        if (!document.hidden) loadMessages(false);
    }, 7000);
});
