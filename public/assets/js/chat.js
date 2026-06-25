const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('messageInput');
const sendBtn = document.getElementById('sendBtn');

async function sendMessage() {
    const message = inputEl.value.trim();

    if (!message) {
        return;
    }

    appendMessage(messagesEl, 'user', message);
    inputEl.value = '';
    sendBtn.disabled = true;
    sendBtn.textContent = '请求中';

    const wrapper = document.createElement('div');
    wrapper.className = 'message assistant';

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = '思考中...';

    wrapper.appendChild(bubble);
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    try {
        const response = await ApiClient.sendChatStream(message, sessionId);

        if (!response.ok || !response.body) {
            bubble.textContent = '请求失败：' + response.status;
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');

        let answer = '';
        let rawStream = '';

        while (true) {
            const { value, done } = await reader.read();

            if (done) {
                break;
            }

            const chunk = decoder.decode(value, { stream: true });
            rawStream += chunk;

            answer = renderAssistantStream(bubble, rawStream);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        if (!answer.trim()) {
            bubble.textContent = '模型没有返回内容';
        }
    } catch (error) {
        bubble.textContent = '网络请求失败：' + error.message;
    } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = '发送';
        inputEl.focus();
    }
}

async function sendMessage_all() {
    const message = inputEl.value.trim();

    if (!message) {
        return;
    }

    appendMessage(messagesEl, 'user', message);
    inputEl.value = '';
    sendBtn.disabled = true;
    sendBtn.textContent = '请求中';

    try {
        const data = await ApiClient.sendChat(message);

        if (!data.ok) {
            appendMessage(messagesEl, 'assistant', data.error || '请求失败');
            return;
        }

        appendMessage(messagesEl, 'assistant', data.answer || '模型没有返回内容');
    } catch (error) {
        appendMessage(messagesEl, 'assistant', '网络请求失败：' + error.message);
    } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = '发送';
        inputEl.focus();
    }
}

sendBtn.addEventListener('click', sendMessage);

inputEl.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
});

function getSessionId() {
    let sessionId = localStorage.getItem('ai_demo_session_id');

    if (!sessionId) {
        sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(16).slice(2);
        localStorage.setItem('ai_demo_session_id', sessionId);
    }

    return sessionId;
}

const newChatBtn = document.getElementById('newChatBtn');
const sessionListEl = document.getElementById('sessionList');

function resetMessages() {
    messagesEl.innerHTML = `
    <div class="message assistant">
      <div class="bubble">你好，我是企业 AI 知识库客服助手。你可以先问我一个企业客服相关的问题。</div>
    </div>
  `;
}

newChatBtn.addEventListener('click', function () {
    localStorage.removeItem('ai_demo_session_id');
    window.location.reload();
});

async function loadSessions() {
    const data = await ApiClient.listSessions();

    if (!data.ok) return;

    sessionListEl.innerHTML = '';

    data.sessions.forEach(item => {
        const div = document.createElement('div');
        div.className = 'session-item';
        div.textContent = item.title || item.session_id;
        div.title = item.title || item.session_id;

        div.addEventListener('click', function () {
            loadSessionMessages(item.session_id);
        });

        sessionListEl.appendChild(div);
    });
}

async function loadSessionMessages(targetSessionId) {
    const data = await ApiClient.getSessionMessages(targetSessionId);

    if (!data.ok) return;

    localStorage.setItem('ai_demo_session_id', targetSessionId);
    sessionId = targetSessionId;

    messagesEl.innerHTML = '';

    data.messages.forEach(row => {
        appendMessage(messagesEl, 'user', row.user_message);
        appendMessage(messagesEl, 'assistant', row.assistant_answer);
    });
}

let sessionId = getSessionId();
loadSessions();
