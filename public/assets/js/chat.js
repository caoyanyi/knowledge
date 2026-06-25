const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('messageInput');
const sendBtn = document.getElementById('sendBtn');
const STREAM_RENDER_INTERVAL_MS = 24;
const STREAM_RENDER_CHUNK_SIZE = 2;
const STREAM_DIRECT_RENDER_LIMIT = 8;

/**
 * 创建前端打字机渲染器；即使后端降级为一次性 delta，也能逐步展示。
 */
function createStreamRenderer(bubble) {
    let displayedAnswer = '';
    let pendingText = '';
    let sources = [];
    let timerId = null;
    let resolveIdle = null;

    function render() {
        renderAssistantMessage(bubble, displayedAnswer, sources);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function stopTimerIfIdle() {
        if (pendingText !== '' || !timerId) {
            return;
        }

        clearInterval(timerId);
        timerId = null;

        if (resolveIdle) {
            resolveIdle();
            resolveIdle = null;
        }
    }

    function tick() {
        if (pendingText === '') {
            stopTimerIfIdle();
            return;
        }

        displayedAnswer += pendingText.slice(0, STREAM_RENDER_CHUNK_SIZE);
        pendingText = pendingText.slice(STREAM_RENDER_CHUNK_SIZE);
        render();
        stopTimerIfIdle();
    }

    function startTimer() {
        if (!timerId) {
            timerId = setInterval(tick, STREAM_RENDER_INTERVAL_MS);
        }
    }

    return {
        append(text) {
            if (pendingText === '' && text.length <= STREAM_DIRECT_RENDER_LIMIT) {
                displayedAnswer += text;
                render();
                return;
            }

            pendingText += text;
            startTimer();
        },

        setSources(nextSources, shouldRender = true) {
            sources = nextSources;

            if (shouldRender) {
                render();
            }
        },

        renderNow() {
            render();
        },

        waitUntilIdle() {
            if (pendingText === '') {
                return Promise.resolve();
            }

            return new Promise(resolve => {
                resolveIdle = resolve;
                startTimer();
            });
        },

        getAnswer() {
            return displayedAnswer + pendingText;
        }
    };
}

/**
 * 发送当前输入内容，并用流式响应持续更新助手消息气泡。
 */
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

        let streamBuffer = '';
        const streamRenderer = createStreamRenderer(bubble);

        // 后端按 NDJSON 输出事件；这里缓存半行数据，等换行到达后再解析。
        while (true) {
            const { value, done } = await reader.read();

            if (done) {
                break;
            }

            const chunk = decoder.decode(value, { stream: true });
            streamBuffer += chunk;

            const lines = streamBuffer.split('\n');
            streamBuffer = lines.pop();

            lines.forEach(line => {
                if (!line.trim()) {
                    return;
                }

                try {
                    const event = JSON.parse(line);

                    if (event.type === 'delta') {
                        streamRenderer.append(event.text || '');
                    } else if (event.type === 'sources') {
                        streamRenderer.setSources(event.sources || [], false);
                    } else if (event.type === 'error') {
                        streamRenderer.append(`\n[模型错误] ${event.message || '未知错误'}`);
                    }
                } catch (error) {
                    streamRenderer.append(line);
                }
            });
        }

        if (streamBuffer.trim()) {
            try {
                const event = JSON.parse(streamBuffer);

                if (event.type === 'delta') {
                    streamRenderer.append(event.text || '');
                } else if (event.type === 'sources') {
                    streamRenderer.setSources(event.sources || [], false);
                } else if (event.type === 'error') {
                    streamRenderer.append(`\n[模型错误] ${event.message || '未知错误'}`);
                }
            } catch (error) {
                streamRenderer.append(streamBuffer);
            }
        }

        await streamRenderer.waitUntilIdle();
        streamRenderer.renderNow();

        if (!streamRenderer.getAnswer().trim()) {
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

/**
 * 非流式聊天备用方法，保留给调试或兼容不支持 ReadableStream 的环境。
 */
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

/**
 * 获取当前浏览器会话 ID，没有则创建一个并持久化到 localStorage。
 */
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

/**
 * 重置聊天窗口为初始欢迎语。
 */
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

/**
 * 加载最近会话列表，供用户切换历史上下文。
 */
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

/**
 * 加载指定会话的历史消息，并把当前会话 ID 切换到该会话。
 */
async function loadSessionMessages(targetSessionId) {
    const data = await ApiClient.getSessionMessages(targetSessionId);

    if (!data.ok) return;

    localStorage.setItem('ai_demo_session_id', targetSessionId);
    sessionId = targetSessionId;

    messagesEl.innerHTML = '';

    data.messages.forEach(row => {
        appendMessage(messagesEl, 'user', row.user_message);
        appendMessage(messagesEl, 'assistant', row.assistant_answer, row.sources || []);
    });
}

let sessionId = getSessionId();
loadSessions();
