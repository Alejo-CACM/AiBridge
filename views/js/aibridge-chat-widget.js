(function () {
    'use strict';

    if (typeof window.AiBridgeChatConfig === 'undefined' || !window.AiBridgeChatConfig.ajaxUrl) {
        return;
    }

    if (document.getElementById('aibridge-chat-bubble')) {
        return;
    }

    var config = window.AiBridgeChatConfig;
    var pollTimer = null;
    var panelOpen = false;
    var lastRenderedCount = 0;

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text === null || text === undefined ? '' : String(text);
        return div.innerHTML;
    }

    function buildUrl(action) {
        var separator = config.ajaxUrl.indexOf('?') === -1 ? '?' : '&';
        return config.ajaxUrl + separator + 'ajax=1&action=' + action;
    }

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        injectMarkup();
        bindEvents();
        fetchMessages();
    });

    function injectMarkup() {
        var bubble = document.createElement('div');
        bubble.id = 'aibridge-chat-bubble';
        bubble.title = 'Asistente AI Bridge';
        bubble.textContent = 'AI';
        document.body.appendChild(bubble);

        var panel = document.createElement('div');
        panel.id = 'aibridge-chat-panel';
        panel.innerHTML =
            '<div id="aibridge-chat-header">' +
                '<span>Asistente AI Bridge</span>' +
                '<span>' +
                    '<span class="aibridge-chat-reset" id="aibridge-chat-reset" title="Empezar de nuevo">&#8635;</span>' +
                    '<span class="aibridge-chat-close" id="aibridge-chat-close">&times;</span>' +
                '</span>' +
            '</div>' +
            '<div id="aibridge-chat-messages"></div>' +
            '<form id="aibridge-chat-form">' +
                '<textarea id="aibridge-chat-input" placeholder="Escribe un mensaje..."></textarea>' +
                '<button type="submit" id="aibridge-chat-send">Enviar</button>' +
            '</form>';
        document.body.appendChild(panel);
    }

    function bindEvents() {
        document.getElementById('aibridge-chat-bubble').addEventListener('click', togglePanel);
        document.getElementById('aibridge-chat-close').addEventListener('click', togglePanel);
        document.getElementById('aibridge-chat-reset').addEventListener('click', resetConversation);

        var form = document.getElementById('aibridge-chat-form');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            sendMessage();
        });

        var input = document.getElementById('aibridge-chat-input');
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        });
    }

    function togglePanel() {
        panelOpen = !panelOpen;
        var panel = document.getElementById('aibridge-chat-panel');
        panel.classList.toggle('open', panelOpen);

        if (panelOpen) {
            fetchMessages();
            startPolling();
        } else {
            stopPolling();
        }
    }

    function startPolling() {
        stopPolling();
        pollTimer = window.setInterval(fetchMessages, 5000);
    }

    function stopPolling() {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function fetchMessages() {
        fetch(buildUrl('FetchMessages'), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.success) {
                    renderMessages(data.messages || [], data.awaiting_reply);
                }
            })
            .catch(function () {});
    }

    function sendMessage() {
        var input = document.getElementById('aibridge-chat-input');
        var content = input.value.trim();
        if (content === '') {
            return;
        }

        var sendButton = document.getElementById('aibridge-chat-send');
        sendButton.disabled = true;
        input.value = '';

        var body = 'content=' + encodeURIComponent(content);

        fetch(buildUrl('SendMessage'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                sendButton.disabled = false;
                if (data && data.success) {
                    renderMessages(data.messages || [], data.awaiting_reply);
                    startPolling();
                }
            })
            .catch(function () {
                sendButton.disabled = false;
            });
    }

    function resetConversation() {
        if (!window.confirm('¿Borrar esta conversación y empezar de nuevo?')) {
            return;
        }

        fetch(buildUrl('ResetConversation'), { method: 'POST', credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.success) {
                    lastRenderedCount = -1;
                    renderMessages([], false);
                }
            })
            .catch(function () {});
    }

    function renderMessages(messages, awaitingReply) {
        if (messages.length === lastRenderedCount && !awaitingReply) {
            return;
        }
        lastRenderedCount = messages.length;

        var container = document.getElementById('aibridge-chat-messages');
        var html = '';
        messages.forEach(function (message) {
            var role = message && message.role === 'assistant' ? 'assistant' : 'user';
            html += '<div class="aibridge-chat-msg ' + role + '">' + escapeHtml(message.content) + '</div>';
        });

        if (awaitingReply) {
            html += '<div class="aibridge-chat-waiting">Esperando respuesta del agente...</div>';
        }

        container.innerHTML = html;
        container.scrollTop = container.scrollHeight;
    }
})();
