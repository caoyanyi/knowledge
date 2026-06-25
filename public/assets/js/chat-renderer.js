/**
 * 渲染助手 Markdown，并通过 DOMPurify 清理模型输出中的不可信 HTML。
 */
function renderMarkdown(text) {
    const html = marked.parse(text);

    return DOMPurify.sanitize(html);
}

/**
 * 在聊天窗口追加一条消息，并返回气泡节点用于流式更新。
 */
function appendMessage(messagesEl, role, text) {
    const wrapper = document.createElement('div');
    wrapper.className = `message ${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'bubble';

    if (role === 'assistant') {
        bubble.innerHTML = renderMarkdown(text);
    } else {
        bubble.textContent = text;
    }

    wrapper.appendChild(bubble);
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    return bubble;
}

/**
 * 渲染后端随流式答案返回的知识库来源列表。
 */
function renderSources(sources) {
    if (sources.length === 0) {
        return null;
    }

    const sourceBox = document.createElement('div');
    sourceBox.className = 'source-box';

    sourceBox.innerHTML = DOMPurify.sanitize(`
  <div class="source-title">参考资料</div>
  ${sources.map(item => `
    <div class="source-item">
      <span>${item.title}</span>
      <small>来源：${item.source || '知识库'} · 相关度：${item.score}</small>
    </div>
  `).join('')}
`);

    return sourceBox;
}

/**
 * 根据当前答案和来源列表刷新助手气泡。
 */
function renderAssistantMessage(bubble, answer, sources = []) {
    bubble.innerHTML = renderMarkdown(answer);

    const sourceBox = renderSources(sources);
    if (sourceBox) {
        bubble.appendChild(sourceBox);
    }

    return answer;
}
