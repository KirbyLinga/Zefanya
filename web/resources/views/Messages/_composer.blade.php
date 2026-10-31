{{-- Send form and live thread updates, shared by both sides of a chat.
     Expects #messagesContainer on the page. $role is 'buyer' or 'seller'. --}}
<form id="messageForm" class="space-y-2">
    @csrf
    <input type="hidden" name="conversation_id" value="{{ $conversation->id }}">

    <textarea
        id="messageInput"
        name="body"
        class="w-full border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm text-gray-900"
        placeholder="Type your message... (Shift+Enter for newline)"
        rows="2"
        maxlength="1000"
    ></textarea>
    <div>
        <label for="messageImage" class="text-xs text-gray-500">Attach a photo (JPG or PNG, up to 5 MB)</label>
        <input id="messageImage" type="file" name="image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="block text-xs text-gray-700">
    </div>
    <p id="messageError" role="alert" class="hidden text-sm text-red-600"></p>
    <div class="flex items-center justify-between">
        <span class="text-xs text-gray-500">
            <span id="charCount">0</span>/1000
        </span>
        <button id="messageSend" type="submit" class="rounded bg-primary-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-800 disabled:opacity-60">
            Send
        </button>
    </div>
</form>

<script>
    (function () {
        const POLL_MS = 5000;
        const threadUrl = @json(route($role.'.messages.show', $conversation));
        const form = document.getElementById('messageForm');
        const input = document.getElementById('messageInput');
        const image = document.getElementById('messageImage');
        const error = document.getElementById('messageError');
        const charCount = document.getElementById('charCount');
        const send = document.getElementById('messageSend');
        const container = document.getElementById('messagesContainer');

        const bubbles = container.querySelectorAll('[data-message-id]');
        let lastId = bubbles.length ? Number(bubbles[bubbles.length - 1].dataset.messageId) : 0;
        let fetching = false;
        let fetchAgain = false;

        function showError(text) {
            error.textContent = text;
            error.classList.remove('hidden');
        }

        function scrollToBottom() {
            container.scrollTop = container.scrollHeight;
        }

        function nearBottom() {
            return container.scrollHeight - container.scrollTop - container.clientHeight < 80;
        }

        // Fetches run one at a time so ids only ever move forward and a message
        // created during a fetch is picked up by the follow-up, never skipped.
        async function fetchNew(forceScroll = false) {
            if (fetching) {
                fetchAgain = true;
                return;
            }
            fetching = true;

            try {
                const response = await fetch(`${threadUrl}?after=${lastId}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (response.ok) {
                    const data = await response.json();
                    const stick = forceScroll || nearBottom();

                    data.messages.forEach((message) => {
                        if (message.id <= lastId) return;
                        lastId = message.id;
                        document.getElementById('emptyThread')?.remove();
                        // Rendered and escaped by Blade (Messages/_bubble).
                        container.insertAdjacentHTML('beforeend', message.html);
                    });

                    if (data.messages.length && stick) scrollToBottom();
                }
            } catch (err) {
                console.error('Error loading messages:', err);
            } finally {
                fetching = false;
                if (fetchAgain) {
                    fetchAgain = false;
                    fetchNew(true);
                }
            }
        }

        input.addEventListener('input', () => {
            charCount.textContent = input.value.length;
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.requestSubmit();
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            error.classList.add('hidden');

            const formData = new FormData(form);

            if (!formData.get('body').trim() && !image.files.length) {
                showError('Enter a message or attach a photo.');
                return;
            }

            send.disabled = true;

            try {
                const response = await fetch(@json(route($role.'.messages.store')), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                if (response.ok) {
                    input.value = '';
                    image.value = '';
                    charCount.textContent = '0';
                    fetchNew(true);
                } else if (response.status === 422) {
                    const data = await response.json();
                    showError(Object.values(data.errors).flat()[0]);
                } else if (response.status === 413) {
                    showError('Photos may not be larger than 5 MB.');
                } else {
                    showError('Failed to send message');
                }
            } catch (err) {
                console.error('Error sending message:', err);
                showError('Error sending message');
            } finally {
                send.disabled = false;
            }
        });

        // Only polls while the tab is visible; catches up as soon as it returns.
        setInterval(() => {
            if (!document.hidden) fetchNew();
        }, POLL_MS);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) fetchNew();
        });

        scrollToBottom();
    })();
</script>
