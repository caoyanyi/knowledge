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

    const title = document.createElement('div');
    title.className = 'source-title';
    title.textContent = '参考资料';
    sourceBox.appendChild(title);

    sources.forEach(item => {
        const sourceItem = document.createElement('div');
        sourceItem.className = 'source-item';

        const sourceTitle = document.createElement('span');
        sourceTitle.textContent = item.title;

        const sourceMeta = document.createElement('small');
        sourceMeta.textContent = `来源：${item.source || '知识库'} · 相关度：${item.score}`;

        sourceItem.appendChild(sourceTitle);
        sourceItem.appendChild(sourceMeta);
        sourceBox.appendChild(sourceItem);
    });

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
