<style>
#buyer-messaging{--chat-wine:#853e50;--chat-ink:#34252a;--chat-muted:#70636a;--chat-line:#eadfe1;max-width:1224px;margin:0 auto;padding:28px 20px 36px;color:var(--chat-ink);background:radial-gradient(ellipse at 10% 0%,#fcf4f4,transparent 70%)}
#buyer-messaging .messaging-title{display:flex;align-items:center;gap:14px;margin-bottom:16px}
#buyer-messaging .title-icon{display:grid;place-items:center;width:48px;height:46px;background:#f7ebed;border-radius:12px;color:var(--chat-wine)}
#buyer-messaging svg{width:21px;height:21px;flex-shrink:0}
#buyer-messaging .title-icon svg{width:27px;height:27px}
#buyer-messaging .title-line{display:flex;align-items:center;gap:14px}
#buyer-messaging h1{font-size:28px;font-weight:700;line-height:1.2;margin:0}
#buyer-messaging .messaging-title p{font-size:14px;margin:3px 0 0;color:var(--chat-muted)}
#buyer-messaging .unread-total{font-size:11px;font-weight:600;color:var(--chat-wine);background:#f5e5e8;border-radius:20px;padding:4px 12px}
#buyer-messaging [hidden]{display:none!important}
#buyer-messaging .messaging-grid{display:grid;grid-template-columns:minmax(280px,32.5%) minmax(0,1fr);gap:10px;height:588px}
#buyer-messaging .inbox-panel,#buyer-messaging .thread-panel{min-width:0;min-height:0;border:1px solid var(--chat-line);border-radius:16px;background:#fffcfc;box-shadow:0 1px 2px #3e202b0c;overflow:hidden}
#buyer-messaging .inbox-panel{display:flex;flex-direction:column}
#buyer-messaging .inbox-controls{padding:16px 18px 12px}
#buyer-messaging .conversation-search{display:flex;align-items:center;gap:10px;border:1px solid #e0d7db;border-radius:9px;padding:8px 11px;color:var(--chat-muted)}
#buyer-messaging .conversation-search svg{width:17px;height:17px}
#buyer-messaging .conversation-search input{width:100%;min-width:0;font-size:12px;border:0;outline:0;background:transparent;padding:0;color:var(--chat-ink)}
#buyer-messaging .conversation-search:focus-within{outline:2px solid var(--chat-wine);outline-offset:2px}
#buyer-messaging .conversation-filters{display:flex;gap:8px;margin-top:13px}
#buyer-messaging .conversation-filters button{flex:1;padding:7px 5px;border:1px solid var(--chat-line);border-radius:8px;font-size:12px;white-space:nowrap;cursor:pointer}
#buyer-messaging .conversation-filters button[aria-pressed=true]{color:white;background:var(--chat-wine);border-color:var(--chat-wine)}
#buyer-messaging .conversation-list{overflow-y:auto;min-height:0;padding:7px;flex:1}
#buyer-messaging .conversation-row{display:flex;gap:13px;align-items:center;position:relative;padding:13px 12px 13px 15px;border-radius:9px;border-bottom:1px solid #f1e9eb;color:inherit;text-decoration:none}
#buyer-messaging .conversation-row[hidden]{display:none}
#buyer-messaging .conversation-row:hover{background:#fbf0f2}
#buyer-messaging .conversation-row.is-selected{background:#f6e6ea;border-bottom-color:transparent}
#buyer-messaging .conversation-row.is-selected:before{content:'';position:absolute;left:0;top:4px;bottom:4px;width:3px;border-radius:4px;background:var(--chat-wine)}
#buyer-messaging .seller-avatar{width:44px;height:44px;display:grid;place-items:center;flex-shrink:0;background:#633641;color:white;border:2px solid #fff;border-radius:50%;font-weight:600;font-size:18px}
#buyer-messaging .conversation-row:nth-child(even) .seller-avatar{background:#f7ece4;color:#8b5141;border-color:#efe5df}
#buyer-messaging .conversation-copy{display:flex;flex:1;min-width:0;flex-direction:column;font-size:12px;line-height:1.45}
#buyer-messaging .conversation-copy strong{font-size:14px;font-weight:650;color:#241b1e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#buyer-messaging .conversation-context,#buyer-messaging .conversation-preview{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--chat-muted)}
#buyer-messaging .conversation-meta{align-self:stretch;display:flex;align-items:flex-end;flex-direction:column;justify-content:space-between;gap:10px;font-size:10px;color:var(--chat-muted);white-space:nowrap}
#buyer-messaging .unread-count{display:grid;place-items:center;min-width:20px;height:20px;padding:0 5px;background:var(--chat-wine);color:white;border-radius:50%;font-size:11px}
#buyer-messaging .thread-panel{display:flex;flex-direction:column;background:#fffdfd}
#buyer-messaging .thread-header{display:flex;align-items:center;gap:9px;padding:12px;border-bottom:1px solid var(--chat-line);min-height:68px}
#buyer-messaging .thread-person{min-width:0;flex:1}
#buyer-messaging .thread-person h2{font-size:17px;font-weight:700;line-height:1.2;overflow-wrap:anywhere}
#buyer-messaging .thread-person p{font-size:12px;color:var(--chat-muted);margin:3px 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#buyer-messaging .view-order{display:flex;align-items:center;gap:7px;padding:7px 8px;border:1px solid #d8b4bf;color:var(--chat-wine);border-radius:8px;font-size:12px;font-weight:600;white-space:nowrap}
#buyer-messaging .thread-messages{flex:1;min-height:0;overflow-y:auto;padding:16px 12px;scrollbar-width:thin;scrollbar-color:#d9cfd2 transparent;display:flex;flex-direction:column;gap:16px}
#buyer-messaging .thread-product{padding:10px 12px 0;flex-shrink:0}
#buyer-messaging .thread-product>a{margin-bottom:0;padding:8px 12px;border-color:var(--chat-line);background:#fff9f9}
#buyer-messaging .thread-product img,#buyer-messaging .thread-product>a>span:first-child{width:40px;height:40px}
#buyer-messaging .thread-empty{display:flex;flex:1;min-height:180px;align-items:center;justify-content:center;flex-direction:column;text-align:center;padding:28px;color:var(--chat-muted);gap:9px;font-size:13px}
#buyer-messaging .thread-empty h2,#buyer-messaging .thread-empty h3{font-size:21px;font-weight:600;color:var(--chat-ink)}
#buyer-messaging .empty-chat-icon{display:grid;place-items:center;width:54px;height:48px;border:1px solid #e9d5da;border-radius:16px;background:#f9eef0;font-size:30px;color:var(--chat-wine);margin-bottom:6px}
#buyer-messaging .inbox-empty{padding:25px 15px;text-align:center;font-size:13px;color:var(--chat-muted)}
#buyer-messaging .thread-composer{flex-shrink:0;padding:10px;border-top:1px solid var(--chat-line);background:#fffafa}
#buyer-messaging .buyer-composer{display:grid;grid-template-columns:minmax(0,1fr) 38px 68px;gap:6px;align-items:start}
#buyer-messaging .buyer-composer textarea{resize:none;width:100%;min-height:40px;max-height:110px;border:1px solid #dcbcc5;border-radius:9px;padding:10px 9px;font-size:12px;line-height:18px;background:white;color:var(--chat-ink)}
#buyer-messaging .attach-photo{display:grid;place-items:center;width:40px;height:40px;border:1px solid #e1c7ce;border-radius:9px;color:var(--chat-wine);cursor:pointer;background:#fff}
#buyer-messaging .buyer-composer button{height:40px;display:flex;align-items:center;justify-content:center;gap:7px;color:white;background:var(--chat-wine);border:1px solid var(--chat-wine);border-radius:9px;font-size:13px;font-weight:600;cursor:pointer}
#buyer-messaging .buyer-composer button:hover{background:#723243}
#buyer-messaging .buyer-composer button:disabled{opacity:.6;cursor:wait}
#buyer-messaging .composer-details{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:10px;color:var(--chat-muted);min-width:0}
#buyer-messaging .attachment-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#buyer-messaging #messageError{grid-column:1/-1;font-size:12px;color:#a41d35;margin:0}
#buyer-messaging .chat-message{display:flex;gap:10px;align-items:flex-start;flex-shrink:0}
#buyer-messaging .chat-message.mine{justify-content:flex-end}
#buyer-messaging .chat-message .seller-avatar{width:34px;height:34px;font-size:13px}
#buyer-messaging .chat-message-content{min-width:0;max-width:82%}
#buyer-messaging .chat-bubble{border-radius:13px;background:#f6edef;padding:9px 13px;font-size:13px;line-height:1.45;overflow-wrap:anywhere}
#buyer-messaging .chat-bubble p{white-space:pre-line}
#buyer-messaging .mine .chat-bubble p{color:inherit}
#buyer-messaging .mine .chat-bubble{color:white;background:var(--chat-wine);border-bottom-right-radius:4px}
#buyer-messaging .chat-bubble img{max-width:100%;max-height:220px;border-radius:9px;margin-bottom:4px}
#buyer-messaging .chat-time{font-size:10px;color:var(--chat-muted);margin:4px 2px 0}
#buyer-messaging .mine .chat-time{text-align:right}
#buyer-messaging .mobile-back{display:none;font-size:22px;color:var(--chat-wine)}
#buyer-messaging a:focus-visible,#buyer-messaging button:focus-visible,#buyer-messaging textarea:focus-visible,#buyer-messaging .attach-photo:has(input:focus-visible){outline:2px solid var(--chat-wine);outline-offset:3px}
#buyer-messaging.is-widget{margin:0;padding:0;width:100%;max-width:none;height:100dvh;overflow:hidden;background:#fffdfd}
#buyer-messaging.is-widget .messaging-title{display:none}
#buyer-messaging.is-widget .messaging-grid{height:100%;display:flex;gap:0;min-height:0}
#buyer-messaging.is-widget .inbox-panel,#buyer-messaging.is-widget .thread-panel{flex:1;border:0;border-radius:0;box-shadow:none}
#buyer-messaging.is-widget .thread-panel{display:none}
#buyer-messaging.is-widget.has-thread .inbox-panel{display:none}
#buyer-messaging.is-widget.has-thread .thread-panel{display:flex}
#buyer-messaging.is-widget .mobile-back{display:block}
@media(max-width:767px){
#buyer-messaging{padding:20px 12px}
#buyer-messaging .messaging-grid{grid-template-columns:minmax(0,1fr);height:min(650px,75dvh);min-height:400px}
#buyer-messaging .thread-panel{display:none}
#buyer-messaging.has-thread .inbox-panel{display:none}
#buyer-messaging.has-thread .thread-panel{display:flex}
#buyer-messaging .mobile-back{display:block}
#buyer-messaging.is-widget .messaging-grid{min-height:0;height:100%}
#buyer-messaging .thread-header .seller-avatar{width:32px;height:32px;font-size:15px}
#buyer-messaging .view-order{font-size:11px;gap:4px}
#buyer-messaging .view-order svg{width:16px;height:16px}
#buyer-messaging h1{font-size:25px}
#buyer-messaging .unread-total{padding:4px 8px}
}
@media(max-height:450px){
#buyer-messaging.is-widget .thread-header{min-height:52px;padding:7px 10px}
#buyer-messaging.is-widget .thread-product{max-height:74px;overflow-y:auto;padding-top:4px}
#buyer-messaging.is-widget .thread-messages{padding:8px 10px;gap:10px}
#buyer-messaging.is-widget .thread-empty{min-height:0;padding:12px}
#buyer-messaging.is-widget .thread-empty .empty-chat-icon{display:none}
#buyer-messaging.is-widget .composer-details{display:none}
}
</style>
