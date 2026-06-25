const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('messageInput');
const sendBtn = document.getElementById('sendBtn');

function addMessage(role, text) {
  const wrapper = document.createElement('div');
  wrapper.className = `message ${role}`;

  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  if (role === 'assistant') {
    const html = marked.parse(text);
    bubble.innerHTML = DOMPurify.sanitize(html);
  } else {
    bubble.textContent = text;
  }

  wrapper.appendChild(bubble);
  messagesEl.appendChild(wrapper);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

async function sendMessage() {
  const message = inputEl.value.trim();

  if (!message) {
    return;
  }

  addMessage('user', message);
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
    const response = await fetch('chat_stream.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        message: message,
        session_id: sessionId
      })
    });

    if (!response.ok || !response.body) {
      bubble.textContent = '请求失败：' + response.status;
      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');

    let answer = '';

    while (true) {
      const { value, done } = await reader.read();

      if (done) {
        break;
      }

      const chunk = decoder.decode(value, { stream: true });
      answer += chunk;

      const html = marked.parse(answer);
      bubble.innerHTML = DOMPurify.sanitize(html);

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

  addMessage('user', message);
  inputEl.value = '';
  sendBtn.disabled = true;
  sendBtn.textContent = '请求中';

  try {
    const response = await fetch('chat.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        message: message
      })
    });

    const data = await response.json();

    if (!data.ok) {
      addMessage('assistant', data.error || '请求失败');
      return;
    }

    addMessage('assistant', data.answer || '模型没有返回内容');
  } catch (error) {
    addMessage('assistant', '网络请求失败：' + error.message);
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
  const response = await fetch('sessions.php');
  const data = await response.json();

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
  const response = await fetch('session_messages.php?session_id=' + encodeURIComponent(targetSessionId));
  const data = await response.json();

  if (!data.ok) return;

  localStorage.setItem('ai_demo_session_id', targetSessionId);
  sessionId = targetSessionId;

  messagesEl.innerHTML = '';

  data.messages.forEach(row => {
    addMessage('user', row.user_message);
    addMessage('assistant', row.assistant_answer);
  });
}

let sessionId = getSessionId();
loadSessions();
