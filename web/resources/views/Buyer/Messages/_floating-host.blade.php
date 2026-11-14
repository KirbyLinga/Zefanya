<style>
#buyer-chat-host{--chat-wine:#853e50;color:#34252a;font-family:var(--font-sans)}
#buyer-chat-host [hidden]{display:none!important}
#buyer-chat-host .chat-launcher{position:fixed;z-index:60;right:max(24px,env(safe-area-inset-right));bottom:max(24px,env(safe-area-inset-bottom));display:flex;align-items:center;justify-content:center;width:52px;height:52px;max-width:calc(100vw - 24px);padding:0;background:var(--chat-wine);color:#fff;border:1px solid #ffffff80;border-radius:30px;box-shadow:0 7px 24px #41202c35;cursor:pointer;font-size:13px;font-weight:600}
#buyer-chat-host .chat-launcher [data-chat-launcher-label],#buyer-chat-host .chat-launcher svg,#buyer-chat-host .chat-toolbar h2,#buyer-chat-host .chat-actions svg{color:#fff}
#buyer-chat-host svg{width:20px;height:20px;flex-shrink:0}
#buyer-chat-host .chat-window{position:fixed;z-index:60;right:max(24px,env(safe-area-inset-right));bottom:max(24px,env(safe-area-inset-bottom));display:flex;flex-direction:column;width:380px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100dvh - 48px);background:#fffdfd;border:1px solid #dfc7ce;border-radius:16px;box-shadow:0 18px 70px #41202c38,0 3px 14px #41202c14;overflow:hidden}
#buyer-chat-host .chat-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px 10px 16px;min-height:52px;flex-shrink:0;background:var(--chat-wine);color:#fff}
#buyer-chat-host .chat-toolbar h2{font-size:17px;font-weight:650;color:inherit}
#buyer-chat-host .chat-actions{display:flex;align-items:center;gap:4px}
#buyer-chat-host .chat-actions button,#buyer-chat-host .chat-actions a{display:grid;place-items:center;width:30px;height:30px;background:transparent;border:0;border-radius:6px;color:inherit;cursor:pointer}
#buyer-chat-host .chat-actions button:hover,#buyer-chat-host .chat-actions a:hover{background:#ffffff24}
#buyer-chat-host .chat-frame{display:block;width:100%;min-height:0;height:0;flex:1 1 0%;border:0;background:#fffdfd}
#buyer-chat-host .chat-status{padding:24px 18px;text-align:center;font-size:13px;color:#70636a}
#buyer-chat-host .chat-status button{display:block;margin:16px auto 0;color:var(--chat-wine);text-decoration:underline;cursor:pointer}
#buyer-chat-host button:focus-visible,#buyer-chat-host a:focus-visible{outline:2px solid #853e50;outline-offset:3px}
#buyer-chat-host .chat-toolbar button:focus-visible,#buyer-chat-host .chat-toolbar a:focus-visible{outline-color:white;outline-offset:0}
@media(max-width:480px){#buyer-chat-host .chat-window{right:max(12px,env(safe-area-inset-right));bottom:max(12px,env(safe-area-inset-bottom));width:calc(100vw - 24px);max-height:calc(100dvh - 24px - env(safe-area-inset-top) - env(safe-area-inset-bottom))}#buyer-chat-host .chat-launcher{right:max(12px,env(safe-area-inset-right));bottom:max(12px,env(safe-area-inset-bottom))}}
</style>
<div id="buyer-chat-host">
    <section id="buyerChatWindow" class="chat-window" role="dialog" aria-modal="false" aria-labelledby="buyerChatTitle" hidden inert>
        <header class="chat-toolbar"><h2 id="buyerChatTitle">Messages</h2><div class="chat-actions">
            <a data-chat-full-page href="{{ route('buyer.messages.index') }}" aria-label="Open full Messages page" title="Full Messages page"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3h7v7M21 3 11 13M10 3H4v17h17v-6"/></svg></a>
            <button type="button" data-chat-minimize aria-label="Minimize messages" title="Minimize"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17h14"/></svg></button>
            <button type="button" data-chat-close aria-label="Close messages" title="Close"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
        </div></header>
        <p class="chat-status" data-chat-loading role="status">Loading your conversations…</p>
        <div class="chat-status" data-chat-recovery hidden><p>This conversation could not be opened. Your session may have expired or the conversation may no longer be available.</p><button type="button" data-chat-inbox>Open conversations</button><a class="mt-3 inline-block underline" href="{{ route('buyer.messages.index') }}">Open full Messages page</a></div>
    </section>
    <button type="button" class="chat-launcher" data-chat-launcher title="Messages" aria-controls="buyerChatWindow" aria-expanded="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 11h8M8 14h5"/></svg><span class="sr-only" data-chat-launcher-label>Messages</span></button>
</div>
<script>
(() => {
    const root = document.getElementById('buyer-chat-host');
    const panel = root.querySelector('#buyerChatWindow');
    const launcher = root.querySelector('[data-chat-launcher]');
    const fullPage = root.querySelector('[data-chat-full-page]');
    const loading = root.querySelector('[data-chat-loading]');
    const recovery = root.querySelector('[data-chat-recovery]');
    const key = @json('buyer.chat.' . auth('buyer')->id());
    const inboxUrl = @json(route('buyer.messages.index'));
    const conversationTemplate = @json(route('buyer.messages.show', ['conversation' => 'CONVERSATION_ID']));
    let frame;
    function remember(name, value) {
        try { value === null ? sessionStorage.removeItem(key + '.' + name) : sessionStorage.setItem(key + '.' + name, value); } catch (_) {}
    }
    function recall(name) { try { return sessionStorage.getItem(key + '.' + name); } catch (_) { return null; } }
    function validId(value) { return typeof value === 'string' && /^[1-9]\d{0,15}$/.test(value) ? value : null; }
    function conversationUrl(id) { return id ? conversationTemplate.replace('CONVERSATION_ID', id) : inboxUrl; }
    function loadConversation(id) {
        fullPage.href = conversationUrl(id);
        loading.hidden = false;
        recovery.hidden = true;
        if (!frame) {
            frame = document.createElement('iframe');
            frame.className = 'chat-frame';
            frame.title = 'Buyer messages';
            frame.hidden = true;
            frame.addEventListener('load', () => {
                loading.hidden = true;
                try {
                    const chat = frame.contentDocument.querySelector('[data-chat-widget="true"]');
                    const error = frame.contentDocument.querySelector('[data-chat-widget-error]');
                    if (!chat && !error) throw new Error('Chat unavailable');
                    frame.hidden = false;
                    recovery.hidden = true;
                    const selectedId = validId(chat?.dataset.conversationId || '');
                    remember('conversation', selectedId);
                    fullPage.href = conversationUrl(selectedId);
                    if (!panel.hidden) requestAnimationFrame(() => {
                        const thread = frame.contentDocument.querySelector('#messagesContainer');
                        if (thread) thread.scrollTop = thread.scrollHeight;
                        frame.contentDocument.dispatchEvent(new Event('buyer-chat-resume'));
                    });
                } catch (_) {
                    frame.hidden = true;
                    remember('conversation', null);
                    fullPage.href = inboxUrl;
                    recovery.hidden = false;
                }
            });
            panel.appendChild(frame);
        }
        frame.hidden = true;
        frame.src = conversationUrl(id) + '?widget=1';
    }
    function setState(state) {
        const open = state === 'open';
        panel.hidden = !open;
        panel.inert = !open;
        launcher.hidden = open;
        launcher.setAttribute('aria-expanded', String(open));
        root.querySelector('[data-chat-launcher-label]').textContent = state === 'minimized' ? 'Restore messages' : 'Messages';
        remember('state', state);
        if (open) {
            if (!frame) loadConversation(validId(recall('conversation')));
            requestAnimationFrame(() => {
                if (!panel.hidden) {
                    try {
                        frame.contentDocument.dispatchEvent(new Event('buyer-chat-resume'));
                        const target = frame.contentDocument.querySelector('#messageInput, #conversationSearch');
                        if (target) target.focus({ preventScroll: true }); else root.querySelector('[data-chat-minimize]').focus();
                    } catch (_) { root.querySelector('[data-chat-minimize]').focus(); }
                }
            });
        } else launcher.focus({ preventScroll: true });
    }
    launcher.addEventListener('click', () => setState('open'));
    root.querySelector('[data-chat-minimize]').addEventListener('click', () => setState('minimized'));
    root.querySelector('[data-chat-close]').addEventListener('click', () => setState('closed'));
    root.querySelector('[data-chat-inbox]').addEventListener('click', () => { remember('conversation', null); loadConversation(null); });
    document.addEventListener('buyer-chat-close', () => { if (!panel.hidden) setState('closed'); });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden && root.contains(event.target) && event.target?.type !== 'file' && !event.defaultPrevented) {
            event.preventDefault(); setState('closed');
        }
    });
    window.zfOpenBuyerConversation = id => {
        const selectedId = validId(String(id));
        if (!selectedId) return false;
        remember('conversation', selectedId);
        loadConversation(selectedId);
        setState('open');
        return true;
    };
    if (!['closed', 'minimized'].includes(recall('state'))) setState('open');
    else if (recall('state') === 'minimized') root.querySelector('[data-chat-launcher-label]').textContent = 'Restore messages';
})();
</script>
