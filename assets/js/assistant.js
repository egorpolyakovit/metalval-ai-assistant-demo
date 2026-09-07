(function () {
    'use strict';

    const openBtn = document.getElementById('aiAssistantOpen');
    const closeBtn = document.getElementById('assistantClose');
    const overlay = document.getElementById('assistantOverlay');
    const panel = document.getElementById('assistantPanel');
    const messages = document.getElementById('assistantMessages');
    const typing = document.getElementById('assistantTyping');
    const form = document.getElementById('assistantForm');
    const input = document.getElementById('assistantInput');
    const sendBtn = document.getElementById('assistantSend');

    if (!openBtn || !panel || !form) return;

    let started = false;
    let sessionId = localStorage.getItem('metalval_demo_session') || '';

    function timeNow() {
        return new Date().toLocaleTimeString('ru-RU', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    function addMessage(role, text, time) {
        const item = document.createElement('div');
        item.className = 'message ' + role;
        item.textContent = text;

        const t = document.createElement('span');
        t.className = 'messageTime';
        t.textContent = time || timeNow();

        item.appendChild(t);
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
    }

    function setTyping(show) {
        typing.hidden = !show;
        if (show) messages.scrollTop = messages.scrollHeight;
    }

    async function request(payload) {
        const context = window.METALVAL_ASSISTANT_CONTEXT || {};

        const response = await fetch('/api/assistant.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                ...payload,
                session_id: sessionId,
                context: context
            })
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Request failed');
        }

        if (data.session_id) {
            sessionId = data.session_id;
            localStorage.setItem('metalval_demo_session', sessionId);
        }

        return data;
    }

    async function startAssistant() {
        if (started) return;
        started = true;

        setTyping(true);

        try {
            const data = await request({action: 'start'});
            setTyping(false);
            addMessage('assistant', data.answer, data.time);
        } catch (e) {
            setTyping(false);
            addMessage('assistant', 'Не удалось запустить демо. Проверьте PHP-сервер.');
            console.error(e);
        }
    }

    function openAssistant() {
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        openBtn.setAttribute('aria-expanded', 'true');
        overlay.hidden = false;
        startAssistant();
        setTimeout(() => input.focus(), 50);
    }

    function closeAssistant() {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        openBtn.setAttribute('aria-expanded', 'false');
        overlay.hidden = true;
    }

    openBtn.addEventListener('click', openAssistant);
    closeBtn.addEventListener('click', closeAssistant);
    overlay.addEventListener('click', closeAssistant);

    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const value = input.value.trim();
        if (!value || sendBtn.disabled) return;

        addMessage('user', value);
        input.value = '';
        input.style.height = 'auto';
        setTyping(true);
        sendBtn.disabled = true;

        try {
            const data = await request({
                action: 'message',
                message: value
            });

            setTyping(false);
            addMessage('assistant', data.answer, data.time);
        } catch (e) {
            setTyping(false);
            addMessage('assistant', 'Не удалось получить ответ. Попробуйте ещё раз.');
            console.error(e);
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    });
})();
